<?php

namespace App\BankNotification\Service;

/**
 * What a bank's notification says: money out or in, how much, where. The
 * source's rules first, in order: a template written as the bank writes,
 * with {importo} (or {amount}) and {esercente} (or {merchant}) where those
 * go and {*} for anything, of a kind (out, in, or ignore: a login, a code, a
 * promotion). Then a generic reading: an amount next to € or EUR, and words
 * that say which way it went ("pagamento", "addebito", "accredito",
 * "ricevuto", "paid", "received"…), the merchant after "presso", "a", "at"…
 */
final class NotificationReading
{
    private const OUT_WORDS = '/\b(pagament[oi]|pagat[oa]|hai pagato|addebit[oa]|prelievo|prelevat[oi]|spesa|acquist[oi]|bonifico (?:disposto|inviato)|payment|paid|spent|purchase|withdrawal|charged)\b/iu';
    private const IN_WORDS = '/\b(accredit[oa]|ricevut[oi]|hai ricevuto|rimbors[oi]|bonifico (?:ricevuto|in entrata)|stipendio|received|refund|deposit|credited)\b/iu';
    private const AMOUNT = '\d[\d.,\x{a0} ]*\d|\d';

    /**
     * @param list<array{kind: string, pattern: string}> $rules
     * @return array{kind: string, amount: ?string, merchant: ?string, rule: ?int}|null null: not understood
     */
    public static function read(array $rules, string $text): ?array
    {
        foreach ($rules as $index => $rule) {
            if (preg_match(self::regex($rule['pattern']), $text, $match) !== 1) {
                continue;
            }
            if ($rule['kind'] === 'ignore') {
                return ['kind' => 'ignore', 'amount' => null, 'merchant' => null, 'rule' => $index];
            }
            $amount = isset($match['amount']) ? self::amount($match['amount']) : null;
            if ($amount === null) {
                continue;
            }

            return ['kind' => $rule['kind'], 'amount' => $amount, 'merchant' => self::clean($match['merchant'] ?? null), 'rule' => $index];
        }

        return self::generic($text);
    }

    /** A rule's template as a regular expression; a broken one matches nothing. */
    public static function regex(string $pattern): string
    {
        $parts = preg_split('/(\{(?:importo|amount|esercente|merchant|\*)\})/iu', trim($pattern), -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';
        foreach ($parts as $i => $part) {
            $last = $i === \count($parts) - 1 || ($i === \count($parts) - 2 && trim($parts[$i + 1]) === '');
            $regex .= match (mb_strtolower($part)) {
                '{importo}', '{amount}' => '(?P<amount>'.self::AMOUNT.')',
                // Last in the template: to the end of the sentence; else as little as reaches what follows.
                '{esercente}', '{merchant}' => $last ? '(?P<merchant>[^\n.]+)' : '(?P<merchant>.+?)',
                '{*}' => '.*?',
                default => (string) preg_replace('/\\\\?\s+/u', '\s+', preg_quote($part, '/')),
            };
        }

        return '/'.$regex.'/iu';
    }

    /** "1.234,56", "1,234.56", "12,40", "4.99" as a decimal with "."; null when it isn't an amount. */
    public static function amount(string $raw): ?string
    {
        $text = (string) preg_replace('/[\s\x{a0}]/u', '', $raw);
        $comma = strrpos($text, ',');
        $dot = strrpos($text, '.');
        $decimal = null;
        if ($comma !== false && $dot !== false) {
            $decimal = $comma > $dot ? ',' : '.';
        } elseif ($comma !== false || $dot !== false) {
            $separator = $comma !== false ? ',' : '.';
            $after = \strlen($text) - (int) strrpos($text, $separator) - 1;
            // One separator before exactly three digits is a thousands one ("1.234"): cents have two.
            $decimal = $after === 3 ? null : $separator;
        }
        if ($decimal !== null) {
            $thousands = $decimal === ',' ? '.' : ',';
            $text = str_replace([$thousands, $decimal], ['', '.'], $text);
        } else {
            $text = str_replace([',', '.'], '', $text);
        }

        return preg_match('/^\d+(\.\d{1,4})?$/', $text) === 1 && (float) $text > 0 ? $text : null;
    }

    /** @return array{kind: string, amount: ?string, merchant: ?string, rule: ?int}|null */
    private static function generic(string $text): ?array
    {
        $amount = null;
        if (preg_match('/(?:€|EUR)\s*('.self::AMOUNT.')/iu', $text, $match) === 1 || preg_match('/('.self::AMOUNT.')\s*(?:€|EUR|euro)/iu', $text, $match) === 1) {
            $amount = self::amount($match[1]);
        }
        $out = preg_match(self::OUT_WORDS, $text) === 1;
        $in = preg_match(self::IN_WORDS, $text) === 1;
        if ($amount === null || $out === $in) {
            return null;
        }
        $merchant = null;
        if (preg_match('/\b(?:presso|da|a|at|to|from|su|verso)\s+([^\n,.]+?)(?=\s+(?:con|il|in data|with|on)\b|[,.\n]|$)/iu', $text, $match) === 1) {
            $merchant = self::clean($match[1]);
        }

        return ['kind' => $out ? 'out' : 'in', 'amount' => $amount, 'merchant' => $merchant, 'rule' => null];
    }

    private static function clean(?string $text): ?string
    {
        $text = trim((string) $text, " \t\n\r\0\x0B.,:;");

        return $text === '' ? null : $text;
    }
}
