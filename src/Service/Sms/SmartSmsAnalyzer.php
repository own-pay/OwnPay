<?php
declare(strict_types=1);

namespace OwnPay\Service\Sms;

/**
 * SmartSmsAnalyzer — Heuristic field extractor for Method B.
 *
 * Architecture (3 independent methods):
 *   Method A: Admin-defined regex templates (op_sms_templates) ← handled by SmsRegexParser
 *   Method B: THIS CLASS — heuristic extraction from SMS body (credit fields only)
 *   Method C: AI prompt generator — static, returns copy-ready prompt for Gemini/Claude/ChatGPT
 *
 * CRITICAL RULES:
 *   - Sender ("From") ALWAYS comes from the actual SMS sender field — NEVER from body text
 *   - Gateway identity NEVER guessed from body text — comes only from matched template
 *   - Sender whitelist = admin-configured sender_pattern values (case-sensitive exact match)
 *   - Credit/received transactions ONLY — debit/send/OTP SMS rejected
 *   - Zero external dependencies. Pure PHP.
 *
 * @see SmsTemplateRepository::getSenderWhitelist()  for whitelist source
 * @see SmsTemplateRepository::findBySender()        for template matching (case-sensitive BINARY)
 */
final class SmartSmsAnalyzer
{
    /**
     * @param list<string> $senderWhitelist Exact sender_pattern values from op_sms_templates for this brand.
     *                                      Case-sensitive. Empty = no whitelist configured (accept any sender).
     */
    public function __construct(
        private readonly array $senderWhitelist = []
    ) {}

    // ── Credit-indicator keywords ────────────────────────────────────────────

    private const CREDIT_WORDS = [
        'received', 'credited', 'deposited', 'added', 'cash in',
        'পেয়েছেন', 'জমা', 'ক্রেডিট', 'receive', 'incoming',
        'you have received', 'has been credited', 'received tk',
    ];

    /** Debit / OTP signals — SMS containing these are NOT credit transactions */
    private const SKIP_WORDS = [
        'sent', 'payment successful', 'paid', 'cash out', 'withdrawn',
        'your otp', 'otp is', 'otp:', 'verification code',
        'পাঠিয়েছেন', 'উত্তোলন', 'ক্যাশ আউট',
        'password reset', 'login code', 'security code',
    ];

    // ── Amount patterns ──────────────────────────────────────────────────────

    private const AMOUNT_PATTERNS = [
        '/(?:Tk\.?|TK)\s*([\d,]+(?:\.\d{1,2})?)/ui'                           => 'high',
        '/BDT\s*([\d,]+(?:\.\d{1,2})?)/ui'                                    => 'high',
        '/৳\s*([\d,]+(?:\.\d{1,2})?)/u'                                       => 'high',
        '/([\d,]+(?:\.\d{1,2})?)\s*Tk\.?/ui'                                  => 'medium',
        '/(?:amount|total)[:\s]+(?:Tk\.?|BDT|৳)?\s*([\d,]+(?:\.\d{1,2})?)/ui' => 'medium',
        '/received\s+(?:Tk\.?|BDT|৳)?\s*([\d,]+(?:\.\d{1,2})?)/ui'           => 'low',
    ];

    // ── Transaction ID patterns ──────────────────────────────────────────────

    private const TRXID_PATTERNS = [
        '/(?:TrxID|Trx\s*ID|TxnID|TransactionID)[:\s]+([A-Z0-9]{4,20})/i'   => 'high',
        '/(?:Ref(?:erence)?(?:\s*No\.?|#)?)[:\s]*([A-Z0-9]{4,20})/i'        => 'high',
        '/(?:Transaction\s*(?:ID|No\.?|Ref))[:\s]+([A-Z0-9]{4,20})/i'       => 'high',
        '/\b([A-Z]{2,4}[0-9]{6,14})\b/'                                      => 'low',
    ];

    // ── Balance patterns ─────────────────────────────────────────────────────

    private const BALANCE_PATTERNS = [
        '/(?:balance|bal\.?|নতুন ব্যালেন্স)[:\s]+(?:Tk\.?|BDT|৳)?\s*([\d,]+(?:\.\d{1,2})?)/ui' => 'high',
        '/([\d,]+(?:\.\d{1,2})?)\s*(?:Tk\.?|BDT)?\s*(?:balance|bal\.?)/ui'                        => 'medium',
    ];

    /**
     * Analyze raw SMS body and extract credit-relevant fields.
     *
     * @param  string      $rawSms  The SMS body text
     * @param  string|null $sender  The actual "From" field of the SMS (set by mobile app).
     *                              If provided, checked case-sensitively against whitelist.
     *                              If null, whitelist check is skipped (admin preview mode).
     *
     * @return array{
     *   sender:              string|null,
     *   sender_whitelisted:  bool,
     *   is_credit:           bool,
     *   skip_reason:         string|null,
     *   amount:              string|null,
     *   trx_id:              string|null,
     *   balance:             string|null,
     *   confidence:          array<string,string>,
     *   suggested_regexes:   array<string,string>,
     *   raw_sms:             string,
     * }
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
            'confidence'         => [],
            'suggested_regexes'  => [],
            'raw_sms'            => $text,
        ];

        // ── 1. Sender whitelist check (case-sensitive exact match) ───────────
        if ($sender !== null) {
            $result['sender_whitelisted'] = $this->checkSenderWhitelist($sender);
        }

        // ── 2. Skip OTP / debit ──────────────────────────────────────────────
        foreach (self::SKIP_WORDS as $skip) {
            if (str_contains($lower, $skip)) {
                $result['skip_reason'] = "Skipped: contains debit/OTP keyword '{$skip}'";
                return $result;
            }
        }

        // ── 3. Credit check ──────────────────────────────────────────────────
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

        // ── 4. Amount ────────────────────────────────────────────────────────
        foreach (self::AMOUNT_PATTERNS as $pattern => $confidence) {
            if (preg_match($pattern, $text, $m)) {
                $result['amount']                            = str_replace(',', '', $m[1]);
                $result['confidence']['amount']              = $confidence;
                $result['suggested_regexes']['amount_regex'] = $this->patternToSuggestion($pattern);
                break;
            }
        }

        // ── 5. Transaction ID ────────────────────────────────────────────────
        foreach (self::TRXID_PATTERNS as $pattern => $confidence) {
            if (preg_match($pattern, $text, $m)) {
                $result['trx_id']                            = $m[1];
                $result['confidence']['trx_id']              = $confidence;
                $result['suggested_regexes']['trx_id_regex'] = $this->patternToSuggestion($pattern);
                break;
            }
        }

        // ── 6. Balance ───────────────────────────────────────────────────────
        foreach (self::BALANCE_PATTERNS as $pattern => $confidence) {
            if (preg_match($pattern, $text, $m)) {
                $result['balance']               = str_replace(',', '', $m[1]);
                $result['confidence']['balance'] = $confidence;
                break;
            }
        }

        return $result;
    }

    /**
     * Generate a ready-to-paste AI prompt for Gemini / ChatGPT / Claude (Method C).
     *
     * The prompt:
     *   - Includes the raw SMS body
     *   - Includes the exact SMS sender (From) so AI knows the source
     *   - Instructs AI to reply with ONLY a JSON code block — no prose, no explanation
     *   - Output JSON matches op_sms_templates schema exactly
     *   - Admin pastes output directly into Method A (New Template form)
     *
     * @param string $rawSms   The SMS body text
     * @param string $sender   The exact "From" field of the SMS (e.g. "bKash", "AD-NAGAD")
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
- This is a CREDIT / RECEIVED payment SMS — extract only received/credited amounts
- Output ONLY the JSON code block below — absolutely no explanation, no prose, no other text
- All regex values must work with PHP's preg_match() — use capture group 1 for the extracted value
- gateway_slug: lowercase slug from the sender name (e.g. bkash, nagad, rocket, dbbl, ibbl)
- sender_pattern: copy EXACTLY from "SMS SENDER" above — case-sensitive, no modification
- amount_regex: captures credited amount digits (no currency symbol in capture group)
- trx_id_regex: captures transaction/reference ID (null if not present)
- sender_regex: captures payer phone number from SMS BODY only (null if not present)
- If a field cannot be detected, set it to null
{$senderLine}

SMS BODY:
```
{$escapedSms}
```

Reply with ONLY this JSON in a code block — nothing else:

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

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Check actual SMS sender against admin-configured whitelist.
     * Case-sensitive exact match — "bKash" ≠ "bkash".
     */
    private function checkSenderWhitelist(string $sender): bool
    {
        if (empty($this->senderWhitelist)) {
            return true; // No whitelist configured = accept all senders
        }
        return in_array($sender, $this->senderWhitelist, strict: true);
    }

    /** Strip regex delimiters + flags for display */
    private function patternToSuggestion(string $pattern): string
    {
        return (string) preg_replace('/^\/(.*)\/[a-z]*$/u', '$1', $pattern);
    }
}
