<?php

declare(strict_types=1);

namespace OwnPay\Service;

/**
 * SmsHeuristicParser — Tier 2: Lexical/keyword-based SMS analysis.
 *
 * Pure PHP engine using proximity-based keyword analysis:
 *   1. Amount detection: Tk/BDT/Taka followed by numeric
 *   2. TrxID detection: TrxID/TxnId/Ref/Transaction ID + alphanumeric
 *   3. Sender detection: 11-digit BD mobile numbers
 *   4. Balance detection: balance/remaining + Tk/BDT + numeric
 *   5. Type detection: credit/debit keyword classification
 *
 * Confidence: medium (if amount + type found), low (if only partial extraction).
 * Returns null only if zero useful data was extracted.
 */
final class SmsHeuristicParser
{
    // Credit indicators (case-insensitive)
    private const CREDIT_KEYWORDS = [
        'received', 'credited', 'deposited', 'cash in',
        'added', 'refunded', 'payment received',
    ];

    // Debit indicators (case-insensitive)
    private const DEBIT_KEYWORDS = [
        'debited', 'sent', 'withdrawn', 'cash out',
        'paid', 'deducted', 'transferred', 'payment of',
    ];

    // Transaction ID label patterns
    private const TRX_ID_PATTERNS = [
        '/(?:TrxID|TxnID|TxnId|TrxId|Txn\s*ID|Transaction\s*ID|Ref(?:erence)?)\s*[:\.\-]?\s*([A-Z0-9]{5,20})/i',
    ];

    // BD mobile number pattern (01XXXXXXXXX)
    private const PHONE_PATTERN = '/\b(01[3-9]\d{8})\b/';

    // Amount patterns: "Tk 1,500.00" or "BDT 1500" or "Taka 500.50"
    private const AMOUNT_PATTERNS = [
        '/(?:Tk\.?\s*|BDT\s*|Taka\s*)([\d,]+(?:\.\d{1,2})?)/i',
    ];

    // Balance-specific patterns (must be near "balance"/"remaining" keywords)
    private const BALANCE_KEYWORDS = ['balance', 'remaining', 'bal'];

    /**
     * Attempt heuristic parse of an SMS body.
     *
     * @param string $body Decrypted SMS text
     * @return array|null Parsed fields or null if nothing extracted
     */
    public function parse(string $body): ?array
    {
        $bodyLower = mb_strtolower($body, 'UTF-8');

        // 1. Detect transaction type
        $type = $this->detectType($bodyLower);

        // 2. Extract amounts (separate transaction amount from balance)
        $amounts = $this->extractAmounts($body, $bodyLower);

        // 3. Extract transaction ID
        $trxId = $this->extractTrxId($body);

        // 4. Extract sender phone number
        $senderPhone = $this->extractPhone($body);

        // Nothing useful extracted?
        if ($amounts['amount'] === null && $trxId === null && $senderPhone === null) {
            return null;
        }

        // Determine confidence
        $confidence = 'low';
        if ($amounts['amount'] !== null && $type !== 'unknown') {
            $confidence = 'medium';
        }
        if ($amounts['amount'] !== null && $type !== 'unknown' && $trxId !== null) {
            $confidence = 'medium'; // Still medium for heuristic (never high)
        }

        return [
            'parsed_amount'    => $amounts['amount'],
            'parsed_trx_id'    => $trxId,
            'parsed_sender'    => $senderPhone,
            'parsed_balance'   => $amounts['balance'],
            'parsed_type'      => $type,
            'parse_method'     => 'heuristic',
            'template_id'      => null,
            'parse_confidence' => $confidence,
        ];
    }

    /**
     * Detect credit/debit from keyword presence.
     */
    private function detectType(string $bodyLower): string
    {
        $creditScore = 0;
        $debitScore = 0;

        foreach (self::CREDIT_KEYWORDS as $keyword) {
            if (str_contains($bodyLower, $keyword)) {
                $creditScore++;
            }
        }

        foreach (self::DEBIT_KEYWORDS as $keyword) {
            if (str_contains($bodyLower, $keyword)) {
                $debitScore++;
            }
        }

        if ($creditScore > $debitScore) {
            return 'credit';
        }
        if ($debitScore > $creditScore) {
            return 'debit';
        }
        return 'unknown';
    }

    /**
     * Extract and classify amounts (transaction vs balance).
     *
     * Strategy: Find all "Tk/BDT X" occurrences. If one is near a balance keyword,
     * classify it as balance. The other (or first) is the transaction amount.
     *
     * @return array{amount: ?float, balance: ?float}
     */
    private function extractAmounts(string $body, string $bodyLower): array
    {
        $allMatches = [];

        foreach (self::AMOUNT_PATTERNS as $pattern) {
            if (preg_match_all($pattern, $body, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[1] as $match) {
                    $rawValue = str_replace(',', '', $match[0]);
                    if (is_numeric($rawValue) && (float) $rawValue > 0) {
                        $allMatches[] = [
                            'value'  => (float) $rawValue,
                            'offset' => $match[1],
                        ];
                    }
                }
            }
        }

        if (empty($allMatches)) {
            return ['amount' => null, 'balance' => null];
        }

        // Classify: check if any amount is near a "balance" keyword
        $transactionAmount = null;
        $balanceAmount = null;

        foreach ($allMatches as $m) {
            $isBalance = false;
            foreach (self::BALANCE_KEYWORDS as $balKw) {
                // Look for balance keyword within 40 chars before the amount
                $searchStart = max(0, $m['offset'] - 40);
                $searchChunk = mb_strtolower(substr($body, $searchStart, $m['offset'] - $searchStart + 5), 'UTF-8');
                if (str_contains($searchChunk, $balKw)) {
                    $isBalance = true;
                    break;
                }
            }

            if ($isBalance && $balanceAmount === null) {
                $balanceAmount = $m['value'];
            } elseif ($transactionAmount === null) {
                $transactionAmount = $m['value'];
            }
        }

        return [
            'amount'  => $transactionAmount,
            'balance' => $balanceAmount,
        ];
    }

    /**
     * Extract transaction ID from SMS body.
     */
    private function extractTrxId(string $body): ?string
    {
        foreach (self::TRX_ID_PATTERNS as $pattern) {
            if (preg_match($pattern, $body, $matches)) {
                return trim($matches[1]);
            }
        }
        return null;
    }

    /**
     * Extract first BD mobile phone number.
     */
    private function extractPhone(string $body): ?string
    {
        if (preg_match(self::PHONE_PATTERN, $body, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
