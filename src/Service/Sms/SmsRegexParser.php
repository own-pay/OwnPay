<?php

declare(strict_types=1);

namespace OwnPay\Service\Sms;

/**
 * SmsRegexParser — Tier 1: Template-based regex matching engine.
 *
 * Attempts to parse SMS body using regex templates from `op_sms_templates`.
 * Templates use PHP PCRE named capture groups: amount, trx_id, sender_number, balance.
 *
 * On match: returns extracted fields + template_id + confidence=high.
 * On no match: returns null (caller should fall through to Tier 2 heuristic).
 */
final class SmsRegexParser
{
    /**
     * Attempt to parse an SMS body against a set of templates.
     *
     * @param string $body      The raw (decrypted) SMS text
     * @param array  $templates Ordered list of template rows (from SmsTemplateRepository)
     * @return array|null Parsed data or null if no template matched
     */
    public function parse(string $body, array $templates): ?array
    {
        foreach ($templates as $template) {
            $pattern = $template['regex_pattern'] ?? '';
            if ($pattern !== '') {
                // Validate regex before executing
                if (@preg_match($pattern, '') === false) {
                    continue; // Invalid regex — skip
                }

                if (preg_match($pattern, $body, $matches)) {
                    $amount = $this->extractAmount($matches['amount'] ?? null);
                    if ($amount === null) {
                        continue; // Matched but no amount — not useful
                    }

                    return [
                        'parsed_amount'    => $amount,
                        'parsed_trx_id'    => $this->clean($matches['trx_id'] ?? null),
                        'parsed_sender'    => $this->clean($matches['sender_number'] ?? null),
                        'parsed_balance'   => $this->extractAmount($matches['balance'] ?? null),
                        'parsed_type'      => $template['transaction_type'] ?? 'unknown',
                        'parse_method'     => 'regex',
                        'template_id'      => (int) $template['id'],
                        'parse_confidence' => 'high',
                    ];
                }
            } else {
                // Database-defined individual regexes
                $amountRegex = $template['amount_regex'] ?? '';
                if ($amountRegex === '') {
                    continue;
                }

                $amountPattern = $this->ensureDelimiters($amountRegex);
                if (@preg_match($amountPattern, '') === false) {
                    continue; // Invalid regex — skip
                }

                if (preg_match($amountPattern, $body, $amountMatches)) {
                    $amount = $this->extractAmount($amountMatches[1] ?? null);
                    if ($amount === null) {
                        continue;
                    }

                    $trxId = null;
                    $trxIdRegex = $template['trx_id_regex'] ?? '';
                    if ($trxIdRegex !== '') {
                        $trxIdPattern = $this->ensureDelimiters($trxIdRegex);
                        if (@preg_match($trxIdPattern, '') !== false) {
                            if (preg_match($trxIdPattern, $body, $trxMatches)) {
                                $trxId = $this->clean($trxMatches[1] ?? null);
                            }
                        }
                    }

                    $senderNumber = null;
                    $senderRegex = $template['sender_regex'] ?? '';
                    if ($senderRegex !== '') {
                        $senderPattern = $this->ensureDelimiters($senderRegex);
                        if (@preg_match($senderPattern, '') !== false) {
                            if (preg_match($senderPattern, $body, $senderMatches)) {
                                $senderNumber = $this->clean($senderMatches[1] ?? null);
                            }
                        }
                    }

                    return [
                        'parsed_amount'    => $amount,
                        'parsed_trx_id'    => $trxId,
                        'parsed_sender'    => $senderNumber,
                        'parsed_balance'   => null,
                        'parsed_type'      => $template['transaction_type'] ?? 'credit',
                        'parse_method'     => 'regex',
                        'template_id'      => (int) $template['id'],
                        'parse_confidence' => 'high',
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Ensure regex has pattern delimiters. Wrap in /.../i if not.
     */
    private function ensureDelimiters(string $regex): string
    {
        $regex = trim($regex);
        if ($regex === '') {
            return '';
        }
        if (preg_match('/^([^\w\s\\\\\\\\]).*\1[a-z]*$/is', $regex)) {
            return $regex;
        }
        return '/' . str_replace('/', '\\/', $regex) . '/i';
    }

    /**
     * Clean and parse an amount string: "1,500.50" ─ 1500.50
     */
    private function extractAmount(?string $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $cleaned = str_replace(',', '', trim($raw));
        if (!is_numeric($cleaned)) {
            return null;
        }
        $val = (float) $cleaned;
        return $val > 0 ? $val : null;
    }

    /**
     * Trim whitespace, return null if empty.
     */
    private function clean(?string $val): ?string
    {
        if ($val === null || trim($val) === '') {
            return null;
        }
        return trim($val);
    }
}
