<?php

namespace App\Draft\Service;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Draft;
use App\Entity\User;
use App\Repository\AccountRepository;
use App\Repository\DraftRepository;
use App\Transaction\Service\DescriptionHistory;
use App\Transaction\Service\PayeeHistory;

/**
 * What a pending draft most likely is, for the review to start from: its
 * kind, the other side and the category. In order:
 * - another pending draft of the same amount, the other way, on another of
 *   the user's accounts within a day: the two legs of a transfer;
 * - money from or to the user themself ("bonifico da Nicolò Pozzato"): a
 *   transfer, with the account the latest transfer that way came from or
 *   went to;
 * - the latest transaction with the same words (or words one holds of the
 *   other: "ESSELUNGA MILANO" and "Esselunga");
 * - an account named like it (one of the user's own: a transfer);
 * - else payees with a similar name, to pick from.
 * With a payee, what the user wrote with it before (PayeeHistory): its usual
 * description in place of the bank's merchant when the latest ones agree,
 * the latest descriptions and categories to pick from, and the category.
 * A few queries for all the drafts, whatever their number.
 */
class DraftSuggestions
{
    private const MAX_SIMILAR = 5;

    public function __construct(
        private readonly DescriptionHistory $history,
        private readonly AccountRepository $accounts,
        private readonly DraftRepository $drafts,
        private readonly PayeeHistory $payees,
    ) {
    }

    /**
     * @param list<Draft> $drafts
     * @return array<string, array{kind: ?string, account: ?array{id: string, name: string, type: string}, categoryId: ?string, pairedDraftId: ?string, similar: list<array{id: string, name: string, type: string}>, description: ?string, recent: ?array{descriptions: list<array{description: string, categoryId: ?string, uses: int}>, categories: list<string>}}> by draft id, pending ones only
     */
    public function forDrafts(User $user, array $drafts): array
    {
        $drafts = array_values(array_filter($drafts, static fn (Draft $draft) => $draft->isPending()));
        if ($drafts === []) {
            return [];
        }
        $history = $this->history->forUser($user);
        $accounts = [];
        foreach ([AccountType::Asset, AccountType::Expense, AccountType::Revenue] as $type) {
            foreach ($this->accounts->findByUserAndType($user, $type) as $account) {
                $accounts[$type->value][] = $account;
            }
        }
        $pending = $this->drafts->findPending($user);
        $self = DescriptionHistory::key($user->getFirstName().' '.$user->getLastName());

        $result = [];
        $toSelf = ['in' => [], 'out' => []];
        foreach ($drafts as $draft) {
            $id = (string) $draft->getId();
            $result[$id] = $this->suggest($draft, $history, $accounts, $pending, $self);
            if ($result[$id]['kind'] === 'transfer' && $result[$id]['account'] === null && $draft->getAssetAccount() !== null) {
                $toSelf[$draft->effectiveDirection() ?? $draft->getDirection()][$id] = (string) $draft->getAssetAccount()->getId();
            }
        }

        // Money from or to themself: the account the latest transfer that way came from or went to.
        foreach ($toSelf as $direction => $assets) {
            $partners = $this->payees->lastTransferPartners($user, array_values($assets), $direction);
            foreach ($assets as $id => $asset) {
                $result[$id]['account'] = $partners[$asset] ?? null;
            }
        }

        $payeeIds = [];
        foreach ($result as $suggestion) {
            if ($suggestion['kind'] !== 'transfer' && $suggestion['account'] !== null) {
                $payeeIds[] = $suggestion['account']['id'];
            }
        }
        $payees = $this->payees->forAccounts($user, $payeeIds);
        foreach ($result as $id => $suggestion) {
            $payee = $suggestion['kind'] !== 'transfer' && $suggestion['account'] !== null ? $payees[$suggestion['account']['id']] ?? null : null;
            if ($payee === null) {
                continue;
            }
            $result[$id]['description'] = $payee['usual'];
            $result[$id]['categoryId'] = $payee['usual'] !== null ? ($payee['usualCategoryId'] ?? $payee['categoryId']) : ($suggestion['categoryId'] ?? $payee['categoryId']);
            $result[$id]['recent'] = ['descriptions' => $payee['descriptions'], 'categories' => $payee['categories']];
        }

        return $result;
    }

    /**
     * @param array<string, array{source: ?array{id: string, name: string, type: string}, destination: ?array{id: string, name: string, type: string}, categoryId: ?string}> $history
     * @param array<string, list<Account>> $accounts by type
     * @param list<Draft> $pending
     * @param string $self the user's name, as a key
     * @return array{kind: ?string, account: ?array{id: string, name: string, type: string}, categoryId: ?string, pairedDraftId: ?string, similar: list<array{id: string, name: string, type: string}>, description: ?string, recent: null}
     */
    private function suggest(Draft $draft, array $history, array $accounts, array $pending, string $self): array
    {
        $direction = $draft->effectiveDirection() ?? $draft->getDirection();
        $asset = $draft->getAssetAccount();
        $suggestion = ['kind' => null, 'account' => null, 'categoryId' => null, 'pairedDraftId' => null, 'similar' => [], 'description' => null, 'recent' => null];
        if ($direction === null || ($draft->getSourceAccount() !== null && $draft->getDestinationAccount() !== null)) {
            return $suggestion;
        }
        $plainKind = $direction === 'out' ? 'withdrawal' : 'deposit';
        $payeeType = $direction === 'out' ? AccountType::Expense->value : AccountType::Revenue->value;

        $pair = $asset !== null ? $this->pair($draft, $asset, $pending) : null;
        if ($pair !== null) {
            return [...$suggestion, 'kind' => 'transfer', 'account' => self::ref($pair->getAssetAccount()), 'pairedDraftId' => (string) $pair->getId()];
        }

        $text = DescriptionHistory::key((string) $draft->getDescription());
        // A notification without a merchant is named after its app: the bank, not a payee.
        if ($text === '' || $text === DescriptionHistory::key((string) $draft->getSourceName())) {
            return $suggestion;
        }
        if (self::isSelf($text, $self)) {
            return [...$suggestion, 'kind' => 'transfer'];
        }

        $known = $history[$text] ?? $this->closest($text, $history);
        if ($known !== null) {
            $other = $direction === 'out' ? $known['destination'] : $known['source'];
            if ($other !== null && $other['type'] === AccountType::Asset->value && $other['id'] !== (string) $asset?->getId()) {
                return [...$suggestion, 'kind' => 'transfer', 'account' => $other, 'categoryId' => $known['categoryId']];
            }
            if ($other !== null && $other['type'] === $payeeType) {
                return [...$suggestion, 'kind' => $plainKind, 'account' => $other, 'categoryId' => $known['categoryId']];
            }
        }

        foreach ([AccountType::Asset->value, $payeeType] as $type) {
            foreach ($accounts[$type] ?? [] as $account) {
                if ($asset !== null && $account->getId()->equals($asset->getId())) {
                    continue;
                }
                $name = DescriptionHistory::key($account->getName());
                if ($name === $text || DescriptionHistory::contains($text, $name) || DescriptionHistory::contains($name, $text)) {
                    $ref = self::ref($account);

                    return [
                        ...$suggestion,
                        'kind' => $type === AccountType::Asset->value ? 'transfer' : $plainKind,
                        'account' => $ref,
                        'categoryId' => $known['categoryId'] ?? null,
                    ];
                }
            }
        }

        return [...$suggestion, 'categoryId' => $known['categoryId'] ?? null, 'similar' => $this->similar($text, $accounts[$payeeType] ?? [])];
    }

    /** The other leg of a transfer: pending, same amount, the other way, another account of the user's, within a day. */
    private function pair(Draft $draft, Account $asset, array $pending): ?Draft
    {
        $direction = $draft->effectiveDirection() ?? $draft->getDirection();
        foreach ($pending as $other) {
            $otherAsset = $other->getAssetAccount();
            if ($other->getId()->equals($draft->getId()) || $otherAsset === null || $otherAsset->getId()->equals($asset->getId())) {
                continue;
            }
            $otherDirection = $other->effectiveDirection() ?? $other->getDirection();
            if ($otherDirection === null || $otherDirection === $direction || bccomp($other->getAmount(), $draft->getAmount(), 4) !== 0) {
                continue;
            }
            if (abs($other->getOccurredAt()->getTimestamp() - $draft->getOccurredAt()->getTimestamp()) <= 86_400) {
                return $other;
            }
        }

        return null;
    }

    /**
     * The past description that holds this one's words or is held in them,
     * the longest (the most specific).
     *
     * @param array<string, array{source: ?array{id: string, name: string, type: string}, destination: ?array{id: string, name: string, type: string}, categoryId: ?string}> $history
     * @return array{source: ?array{id: string, name: string, type: string}, destination: ?array{id: string, name: string, type: string}, categoryId: ?string}|null
     */
    private function closest(string $text, array $history): ?array
    {
        $best = null;
        foreach (array_keys($history) as $key) {
            $key = (string) $key;
            if ((DescriptionHistory::contains($text, $key) || DescriptionHistory::contains($key, $text)) && ($best === null || mb_strlen($key) > mb_strlen($best))) {
                $best = $key;
            }
        }

        return $best !== null ? $history[$best] : null;
    }

    /**
     * Whether the text names the user ("Nicolo Pozzato", "Nicolò", "Pozzato
     * Nicolo'"): every word of it is a word of their name, or it holds their
     * whole name.
     */
    private static function isSelf(string $text, string $self): bool
    {
        $words = explode(' ', $text);
        $name = array_filter(explode(' ', $self), static fn (string $word) => mb_strlen($word) >= 3);
        if ($name === []) {
            return false;
        }
        if (array_diff($words, $name) === [] && max(array_map('mb_strlen', $words)) >= 4) {
            return true;
        }

        return \count($name) >= 2 && array_diff($name, $words) === [];
    }

    /**
     * Payees whose name looks like the text: a word in common, or close
     * spelling ("Esselunga" for "ESSELUNG MI"), best first.
     *
     * @param list<Account> $candidates
     * @return list<array{id: string, name: string, type: string}>
     */
    private function similar(string $text, array $candidates): array
    {
        $words = array_filter(explode(' ', $text), static fn (string $word) => mb_strlen($word) >= 4);
        $scored = [];
        foreach ($candidates as $account) {
            $name = DescriptionHistory::key($account->getName());
            if ($name === '') {
                continue;
            }
            $nameWords = array_filter(explode(' ', $name), static fn (string $word) => mb_strlen($word) >= 4);
            $shared = 0;
            foreach ($words as $word) {
                foreach ($nameWords as $nameWord) {
                    if ($word === $nameWord || levenshtein($word, $nameWord) <= (mb_strlen($word) >= 7 ? 2 : 1)) {
                        ++$shared;
                        break;
                    }
                }
            }
            similar_text($text, $name, $percent);
            $score = $shared * 100 + $percent;
            if ($shared > 0 || $percent >= 70) {
                $scored[] = [$score, $account];
            }
        }
        usort($scored, static fn (array $a, array $b) => $b[0] <=> $a[0]);

        return array_map(static fn (array $row) => self::ref($row[1]), \array_slice($scored, 0, self::MAX_SIMILAR));
    }

    /** @return array{id: string, name: string, type: string} */
    private static function ref(Account $account): array
    {
        return ['id' => (string) $account->getId(), 'name' => $account->getName(), 'type' => $account->getType()->value];
    }
}
