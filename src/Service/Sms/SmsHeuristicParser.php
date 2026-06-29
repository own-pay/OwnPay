<?php

declare(strict_types=1);

namespace OwnPay\Service\Sms;

/**
 * Lexical/keyword-based SMS parser.
 *
 * Employs a pure PHP proximity analysis engine to extract transaction metadata:
 * 1. Resolves amounts based on currency and numeric symbols.
 * 2. Matches transaction IDs against generic pattern ranges.
 * 3. Identifies BD phone number footprints.
 * 4. Separates cash balances from transfer amounts.
 * 5. Classifies transactions based on semantic keywords.
 */
final class SmsHeuristicParser
{
    /**
     * Keywords indicating an incoming financial transaction.
     */
    private const CREDIT_KEYWORDS = [
        'received', 'credited', 'deposited', 'cash in',
        'added', 'refunded', 'payment received',
    ]; // TODO: Add more positive words.

    /**
     * Keywords indicating an outgoing financial transaction.
     */
    private const DEBIT_KEYWORDS = [
        'debited', 'sent', 'withdrawn', 'cash out',
        'paid', 'deducted', 'transferred', 'payment of',
    ]; // TODO: Add more negative words.

    /**
     * Regex patterns used to locate transaction identifiers.
     */
    private const TRX_ID_PATTERNS = [
        '/(?:TrxID|TxnID|TxnId|TrxId|Txn\s*ID|Transaction\s*ID|Ref(?:erence)?)\s*[:\.\-]?\s*([A-Z0-9]{5,20})/i',
    ]; // TODO: Add more trx id patterns.

    /**
     * Standard regex pattern for matching Bangladeshi mobile numbers.
     */
    private const PHONE_PATTERN = '/\b(01[3-9]\d{8})\b/'; // TODO: Add more phone number patterns.

    /**
     * Regex patterns for transaction amount matching.
     */
    private const AMOUNT_PATTERNS = [
        '/(?:Tk\.?\s*|BDT\s*|Taka\s*)([\d,]+(?:\.\d{1,2})?)/i',
    ];   //TODO: Add more amount patterns.

    /**
     * Keywords indicating an account balance block.
     */
    private const BALANCE_KEYWORDS = ['balance', 'remaining', 'bal']; // TODO: Add more balance keywords.

    /**
     * Attempts to parse an SMS message using heuristics.
     *
     * Extracts fields including transaction amount, transaction ID, sender phone number, balance and event type.
     *
     * @param string $body Decrypted or raw text of the incoming SMS body.
     * @return array{
     *   parsed_amount: float|null,
     *   parsed_trx_id: string|null,
     *   parsed_sender: string|null,
     *   parsed_balance: float|null,
     *   parsed_type: string,
     *   parse_method: string,
     *   template_id: int|null,
     *   parse_confidence: string
     * }|null Parsed data map, or null if no useful info could be extracted.
     */
    public function parse(string $body): ?array
    {
        $bodyLower = mb_strtolower($body, 'UTF-8');

        $type = $this->detectType($bodyLower);
        $amounts = $this->extractAmounts($body, $bodyLower);
        $trxId = $this->extractTrxId($body);
        $senderPhone = $this->extractPhone($body);

        if ($amounts['amount'] === null && $trxId === null && $senderPhone === null) {
            return null;
        }

        $confidence = 'low';
        if ($amounts['amount'] !== null && $type !== 'unknown') {
            $confidence = 'medium';
        }
        if ($amounts['amount'] !== null && $type !== 'unknown' && $trxId !== null) {
            $confidence = 'medium';
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
     * Determines transaction direction (credit/debit) based on keyword score.
     *
     * @param string $bodyLower Lowercase representation of the SMS message body.
     * @return string The resolved transaction direction ('credit', 'debit', or 'unknown').
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
     * Extracts and separates transaction amount from account balance.
     *
     * Identifies amounts following currency indicators and checks their proximity to balance keywords.
     *
     * @param string $body The raw SMS text.
     * @param string $bodyLower Lowercase representation of the SMS text.
     * @return array{amount: float|null, balance: float|null}
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

        $transactionAmount = null;
        $balanceAmount = null;

        foreach ($allMatches as $m) {
            $isBalance = false;
            foreach (self::BALANCE_KEYWORDS as $balKw) {
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
     * Extracts the transaction ID from the message.
     *
     * @param string $body The raw text of the SMS.
     * @return string|null The extracted transaction ID, or null if not found.
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
     * Extracts a Bangladeshi mobile number from the message body.
     *
     * @param string $body The raw text of the SMS.
     * @return string|null The extracted phone number, or null if not found.
     */
    private function extractPhone(string $body): ?string
    {
        if (preg_match(self::PHONE_PATTERN, $body, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
