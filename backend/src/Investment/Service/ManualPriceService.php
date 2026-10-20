<?php

namespace App\Investment\Service;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentPrice;
use App\Entity\InvestmentPriceSource;
use App\Entity\User;
use App\Repository\InvestmentPriceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The prices the user keeps by hand on an instrument's page: one per day at
 * most, never overwritten by the provider afterwards (InvestmentPrice). On a
 * day the provider has a price, entering one corrects it and restoring goes
 * back to it; a day only the user entered can be removed (softly).
 */
class ManualPriceService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvestmentPriceRepository $priceRepository,
    ) {
    }

    /** Records the user's price for the day: a new one, or a correction of the one there. */
    public function set(InvestmentInstrument $instrument, \DateTimeImmutable $day, string $price, User $user): InvestmentPrice
    {
        $today = new \DateTimeImmutable((new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone())))->format('Y-m-d'));
        if ($day > $today) {
            throw new ApiException(ErrorCode::PriceDateInFuture);
        }

        $price = bcadd($price, '0', 4);
        $existing = $this->priceRepository->findOneByInstrumentAndDate($instrument, $day);
        if ($existing !== null) {
            $existing->setManualPrice($price);
        } else {
            $existing = new InvestmentPrice($instrument, $day, $price, InvestmentPriceSource::Manual);
            $this->entityManager->persist($existing);
        }

        $this->entityManager->flush();

        return $existing;
    }

    /** Back to the provider's value for the day. */
    public function restore(InvestmentInstrument $instrument, \DateTimeImmutable $day): InvestmentPrice
    {
        $price = $this->find($instrument, $day);
        if ($price->getOriginalPrice() === null) {
            throw new ApiException(ErrorCode::PriceWithoutProviderValue);
        }

        $price->restoreProviderPrice();
        $this->entityManager->flush();

        return $price;
    }

    /** Removes a day only the user entered; the provider's days are restored instead. */
    public function discard(InvestmentInstrument $instrument, \DateTimeImmutable $day): void
    {
        $price = $this->find($instrument, $day);
        if ($price->getOriginalPrice() !== null) {
            throw new ApiException(ErrorCode::PriceFromProvider);
        }

        $price->markDeleted();
        $this->entityManager->flush();
    }

    private function find(InvestmentInstrument $instrument, \DateTimeImmutable $day): InvestmentPrice
    {
        return $this->priceRepository->findOneByInstrumentAndDate($instrument, $day)
            ?? throw ApiException::notFound(ErrorCode::PriceNotFound);
    }
}
