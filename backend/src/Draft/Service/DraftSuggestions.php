<?php

namespace App\Draft\Service;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Draft;
use App\Entity\User;
use App\Repository\AccountRepository;
use App\Repository\DraftRepository;
use App\Transaction\Service\DescriptionHistory;

/**
 * What a pending draft most likely is, for the review to start from: its
 * kind, the other side and the category. In order:
 * - another pending draft of the same amount, the other way, on another of
 *   the user's accounts within a day: the two legs of a transfer;
 * - the latest transaction with the same words (or words one holds of the
 *   other: "ESSELUNGA MILANO" and "Esselunga");
 * - an account named like it (one of the user's own: a transfer);
 * - else payees with a similar name, to pick from.
 * One query for the history and one for the accounts, whatever the drafts.
 */
class DraftSuggestions
{
    private const MAX_SIMILAR = 5;

    public function __construct(
        private readonly DescriptionHistory $history,
        private readonly AccountRepository $accounts,
        private readonly DraftRepository $drafts,
    ) {
    }

    /**
     * @param list<Draft> $drafts
     * @return array<string, array{kind: ?string, account: ?array{id: string, name: string, type: string}, categoryId: ?string, pairedDraftId: ?string, similar: list<array{id: string, name: string, type: string}>}> by draft id, pending ones only
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

        $result = [];
        foreach ($drafts as $draft) {
            $result[(string) $draft->getId()] = $this->suggest($draft, $history, $accounts, $pending);
        }

        return $result;
    }

    /**
     * @param array<string, array{source: ?array{id: string, name: string, type: string}, destination: ?array{id: string, name: string, type: string}, categoryId: ?string}> $history
     * @param array<string, list<Account>> $accounts by type
     * @param list<Draft> $pending
     * @return array{kind: ?string, account: ?array{id: string, name: string, type: string}, categoryId: ?string, pairedDraftId: ?string, similar: list<array{id: string, name: string, type: string}>}
     */
    private function suggest(Draft $draft, array $history, array $accounts, array $pending): array
    {
        $direction = $draft->effectiveDirection() ?? $draft->getDirection();
        $asset = $draft->getAssetAccount();
        $suggestion = ['kind' => null, 'account' => null, 'categoryId' => null, 'pairedDraftId' => null, 'similar' => []];
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
                        'categoryId' => $known['categoryId'] ?? $this->categoryOf($ref['id'], $history),
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
     * The category of the latest transaction with that account on its other side.
     *
     * @param array<string, array{source: ?array{id: string, name: string, type: string}, destination: ?array{id: string, name: string, type: string}, categoryId: ?string}> $history
     */
    private function categoryOf(string $accountId, array $history): ?string
    {
        foreach ($history as $known) {
            if ($known['categoryId'] !== null && (($known['destination']['id'] ?? null) === $accountId || ($known['source']['id'] ?? null) === $accountId)) {
                return $known['categoryId'];
            }
        }

        return null;
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
