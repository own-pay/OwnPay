<?php
declare(strict_types=1);

namespace OwnPay\Service\Sms;

/**
 * Heuristic field extractor for Method B processing.
 *
 * Implements proximity-based lexical analysis to identify credit-only fields
 * within raw SMS content. Part of the three-tiered processing engine (Regex matching,
 * Heuristic fallback, and AI prompt assistance).
 *
 * Strict Architectural Rules:
 * - Sender identity ("From") must originate from the native carrier SMS headers, never parsed from body text.
 * - Gateway configurations are only matched against whitelisted sender patterns.
 * - Non-credit events (debits, OTP codes, outgoing payments) are aggressively ignored and rejected.
 *
 * @see \OwnPay\Repository\SmsTemplateRepository::getSenderWhitelist() For source details on whitelisted senders.
 */
final class SmartSmsAnalyzer
{
    /**
     * Exact sender pattern values configured in database templates for case-sensitive matching.
     *
     * @var array<int, string>
     */
    private readonly array $senderWhitelist;

    /**
     * Initializes the analyzer with a whitelist of approved senders.
     *
     * @param array<int, string> $senderWhitelist Exact sender_pattern values from op_sms_templates.
     */
    public function __construct(
        array $senderWhitelist = []
    ) {
        $this->senderWhitelist = $senderWhitelist;
    }

    /**
     * Keywords indicating a credit or inbound financial transfer event.
     */
    private const CREDIT_WORDS = [
        'received', 'credited', 'deposited', 'added', 'cash in',
        'পেয়েছেন', 'জমা', 'ক্রেডিট', 'receive', 'incoming',
        'you have received', 'has been credited', 'received tk',
    ]; // TODO: Add more positive words

    /**
     * Keywords indicating debit, cash out, or administrative OTP actions that abort analysis.
     */
    private const SKIP_WORDS = [
        'sent', 'payment successful', 'paid', 'cash out', 'withdrawn',
        'your otp', 'otp is', 'otp:', 'verification code',
        'পাঠিয়েছেন', 'উত্তোলন', 'ক্যাশ',
        'password reset', 'login code', 'security code',
    ]; // TODO: Add more negative words.

    /**
     * Regexp matching structures for transaction amount resolution.
     */
    private const AMOUNT_PATTERNS = [
        '/(?:Tk\.?|TK)\s*([\d,]+(?:\.\d{1,2})?)/ui'                           => 'high',
        '/BDT\s*([\d,]+(?:\.\d{1,2})?)/ui'                                    => 'high',
        '/৳\s*([\d,]+(?:\.\d{1,2})?)/u'                                       => 'high',
        '/([\d,]+(?:\.\d{1,2})?)\s*Tk\.?/ui'                                  => 'medium',
        '/(?:amount|total)[:\s]+(?:Tk\.?|BDT|৳)?\s*([\d,]+(?:\.\d{1,2})?)/ui' => 'medium',
        '/received\s+(?:Tk\.?|BDT|৳)?\s*([\d,]+(?:\.\d{1,2})?)/ui'            => 'low',
    ]; //TODO: Add more amount patterns.

    /**
     * Regexp matching structures for transaction identifier resolution.
     */
    private const TRXID_PATTERNS = [
        '/(?:TrxID|Trx\s*ID|TxnID|TransactionID)[:\s]+([A-Z0-9]{4,20})/i'   => 'high',
        '/(?:Ref(?:erence)?(?:\s*No\.?|#)?)[:\s]*([A-Z0-9]{4,20})/i'        => 'high',
        '/(?:Transaction\s*(?:ID|No\.?|Ref))[:\s]+([A-Z0-9]{4,20})/i'       => 'high',
        '/\b([A-Z]{2,4}[0-9]{6,14})\b/'                                     => 'low',
    ]; //TODO: Add more trx id patterns.

    /**
     * Regexp matching structures for account balance resolution.
     */
    private const BALANCE_PATTERNS = [
        '/(?:balance|bal\.?|নতুন ব্যালেন্স)[:\s]+(?:Tk\.?|BDT|৳)?\s*([\d,]+(?:\.\d{1,2})?)/ui' => 'high',
        '/([\d,]+(?:\.\d{1,2})?)\s*(?:Tk\.?|BDT)?\s*(?:balance|bal\.?)/ui'                  => 'medium',
    ]; //TODO: Add more balance patterns.

    /**
     * Regexp matching structures for the PAYER's account ("Sender Account") resolution - the account the
     * money came FROM, captured from the SMS body. Bangladeshi MFS senders (bKash, Nagad, Rocket) quote
     * the payer's 11-digit mobile number ("from 01XXXXXXXXX"); some quote a masked account string. This
     * feeds the template's `sender_regex` suggestion, which was previously never produced (issue #5).
     */
    private const SENDER_ACCOUNT_PATTERNS = [
        '/(?:from|sender|a\/c|account|customer)[:\s]+(01[3-9]\d{8})/ui' => 'high',
        '/\b(01[3-9]\d{8})\b/u'                                         => 'medium',
        '/(?:from|sender|a\/c|account)[:\s]+([0-9X*]{6,20})/ui'         => 'low',
    ]; //TODO: Add more sender account patterns.

    /**
     * Analyzes raw SMS text using heuristic matching strategies.
     *
     * Extracts fields like transaction amount, transaction ID, and final account balance.
     *
     * @param string $rawSms The raw text content of the SMS.
     * @param string|null $sender The sender identity value from the SMS header, used for whitelist lookups.
     * @return array{
     *   sender: string|null,
     *   sender_whitelisted: bool,
     *   is_credit: bool,
     *   skip_reason: string|null,
     *   amount: string|null,
     *   trx_id: string|null,
     *   balance: string|null,
     *   sender_account: string|null,
     *   confidence: array<string, string>,
     *   suggested_regexes: array<string, string>,
     *   raw_sms: string
     * } Extraction analytics mapping containing resolved variables and confidence scores.
     */
    public function analyze(string $rawSms, ?string $sender = null): array
    {
        $text  = trim($rawSms);
        $lower = mb_strtolower($text, 'UTF-8');

        $result = [
            'sender'             => $sender,
            'sender_whitelisted' => false,
            'is_credit'          => false,
            'skip_reason'        => null,
            'amount'             => null,
            'trx_id'             => null,
            'balance'            => null,
            'sender_account'     => null,
            'confidence'         => [],
            'suggested_regexes'  => [],
            'raw_sms'            => $text,
        ];

        if ($sender !== null) {
            $result['sender_whitelisted'] = $this->checkSenderWhitelist($sender);
        }

        foreach (self::SKIP_WORDS as $skip) {
            if (str_contains($lower, $skip)) {
                $result['skip_reason'] = "Skipped: contains debit/OTP keyword '{$skip}'";
                return $result;
            }
        }

        $isCredit = false;
        foreach (self::CREDIT_WORDS as $cw) {
            if (str_contains($lower, $cw)) {
                $isCredit = true;
                break;
            }
        }
        $result['is_credit'] = $isCredit;
        if (!$isCredit) {
            $result['skip_reason'] = 'No credit keyword found. Verify this is a received-payment SMS.';
        }

        foreach (self::AMOUNT_PATTERNS as $pattern => $confidence) {
            if (preg_match($pattern, $text, $m)) {
                $result['amount']                            = str_replace(',', '', $m[1]);
                $result['confidence']['amount']              = $confidence;
                $result['suggested_regexes']['amount_regex'] = $this->patternToSuggestion($pattern);
                break;
            }
        }

        foreach (self::TRXID_PATTERNS as $pattern => $confidence) {
            if (preg_match($pattern, $text, $m)) {
                $result['trx_id']                            = $m[1];
                $result['confidence']['trx_id']              = $confidence;
                $result['suggested_regexes']['trx_id_regex'] = $this->patternToSuggestion($pattern);
                break;
            }
        }

        foreach (self::BALANCE_PATTERNS as $pattern => $confidence) {
            if (preg_match($pattern, $text, $m)) {
                $result['balance']               = str_replace(',', '', $m[1]);
                $result['confidence']['balance'] = $confidence;
                break;
            }
        }

        foreach (self::SENDER_ACCOUNT_PATTERNS as $pattern => $confidence) {
            if (preg_match($pattern, $text, $m)) {
                $result['sender_account']                    = $m[1];
                $result['confidence']['sender_account']      = $confidence;
                $result['suggested_regexes']['sender_regex'] = $this->patternToSuggestion($pattern);
                break;
            }
        }

        return $result;
    }

    /**
     * Generates a structural instruction prompt for large language model execution (Method C).
     *
     * Provides templates to format dynamic data into a structured layout copy-ready for AI copy-pasting.
     *
     * @param string $rawSms The raw message content to inject.
     * @param string $sender The source sender identity header of the message.
     * @return string The structured prompt text.
     */
    public static function buildAiPrompt(string $rawSms, string $sender = ''): string
    {
        $escapedSms    = trim($rawSms);
        $escapedSender = trim($sender);
        $senderLine    = $escapedSender !== '' ? "SMS SENDER (From field): {$escapedSender}" : '';

        return <<<PROMPT
You are a regex extraction expert for an SMS payment parsing system.

Analyze the following SMS message and create regex patterns to extract credit payment fields.

STRICT RULES:
- This is a CREDIT / RECEIVED payment SMS - extract only received/credited amounts
- Output ONLY the JSON code block below - absolutely no explanation, no prose, no other text
- All regex values must work with PHP's preg_match() - use capture group 1 for the extracted value
- gateway_slug: lowercase slug from the sender name (e.g. bkash, nagad, rocket, dbbl, ibbl)
- sender_pattern: copy EXACTLY from "SMS SENDER" above - case-sensitive, no modification
- amount_regex: captures credited amount digits (no currency symbol in capture group)
- trx_id_regex: captures transaction/reference ID (null if not present)
- sender_regex: captures payer phone number from SMS BODY only (null if not present)
- If a field cannot be detected, set it to null
{$senderLine}

SMS BODY:
```
{$escapedSms}
```

Reply with ONLY this JSON in a code block - nothing else:

```json
{
  "gateway_slug": "",
  "sender_pattern": "{$escapedSender}",
  "amount_regex": "",
  "trx_id_regex": null,
  "sender_regex": null,
  "priority": 10
}
```
PROMPT;
    }

    /**
     * Validates SMS sender against whitelist rules.
     *
     * Performs strict, case-sensitive string matching against values.
     *
     * @param string $sender The sender identity under validation.
     * @return bool True if permitted or if whitelist configurations are empty.
     */
    private function checkSenderWhitelist(string $sender): bool
    {
        if (empty($this->senderWhitelist)) {
            return true;
        }
        return in_array($sender, $this->senderWhitelist, strict: true);
    }

    /**
     * Sanitizes regex patterns for display suggestions.
     *
     * @param string $pattern The raw regex string.
     * @return string Sanitized regex representation without outer delimiters or modifiers.
     */
    private function patternToSuggestion(string $pattern): string
    {
        return (string) preg_replace('/^\/(.*)\/[a-z]*$/u', '$1', $pattern);
    }
}
