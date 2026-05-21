<?php

declare(strict_types=1);

namespace OwnPay\Service\Sms;

/**
 * Tier 1: Template-based regex matching engine.
 *
 * Scans SMS messages against registered templates stored in `op_sms_templates`.
 * Utilizes PHP PCRE matching structures with named capture groups including:
 * - amount
 * - trx_id
 * - sender_number
 * - balance
 *
 * If a match succeeds, returns extracted metadata with high parsing confidence.
 * Otherwise, falls back to downstream heuristic parsers.
 */
final class SmsRegexParser
{
    /**
     * Attempts to match and parse an SMS body using a list of templates.
     *
     * @param string $body The raw (decrypted) text content of the SMS.
     * @param array<int, array<string, mixed>> $templates List of ordered SMS template records.
     * @return array{
     *   parsed_amount: float,
     *   parsed_trx_id: string|null,
     *   parsed_sender: string|null,
     *   parsed_balance: float|null,
     *   parsed_type: string,
     *   parse_method: string,
     *   template_id: int,
     *   parse_confidence: string
     * }|null Parsed fields if template successfully matches, otherwise null.
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
     * Enforces valid pattern delimiters on raw regular expression inputs.
     *
     * @param string $regex The regex under validation.
     * @return string The normalized regular expression with standard delimiters.
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
     * Extracts and cleans numeric amounts from match strings.
     *
     * Strips thousand separators and casts values. Returns null if invalid or zero.
     *
     * @param string|null $raw The raw amount string.
     * @return float|null Cleaned amount float, or null.
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
     * Cleans whitespace from strings, treating empty strings as null.
     *
     * @param string|null $val The raw input.
     * @return string|null Cleaned string or null.
     */
    private function clean(?string $val): ?string
    {
        if ($val === null || trim($val) === '') {
            return null;
        }
        return trim($val);
    }
}
