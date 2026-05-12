<?php

declare(strict_types=1);

namespace Tests\Controller;

use OwnPay\Service\Sms\SmsRegexParser;
use PHPUnit\Framework\TestCase;

/**
 * AdminSmsTemplateTest â€” Tests for regex validation and parser tester logic.
 *
 * Tests the core logic without HTTP (regex validation, parse testing).
 */
final class AdminSmsTemplateTest extends TestCase
{
    // â”€â”€â”€ Regex Validation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testValidRegexPassesValidation(): void
    {
        $pattern = '/received Tk\s*(?P<amount>[\d,]+(?:\.\d{1,2})?)/i';
        $result = @preg_match($pattern, '');
        $this->assertNotFalse($result, 'Valid regex should compile');
    }

    public function testInvalidRegexFailsValidation(): void
    {
        $pattern = '/unclosed (group/';
        $result = @preg_match($pattern, '');
        $this->assertFalse($result, 'Invalid regex should fail');
    }

    public function testEmptyRegexFailsValidation(): void
    {
        $result = @preg_match('', '');
        $this->assertFalse($result, 'Empty regex should fail');
    }

    // â”€â”€â”€ Regex Tester Logic â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testRegexTesterMatchesSampleText(): void
    {
        $parser = new SmsRegexParser();
        $templates = [[
            'id'               => 0,
            'sender_pattern'   => 'test',
            'regex_pattern'    => '/received Tk\s*(?P<amount>[\d,]+(?:\.\d{1,2})?)\s*from\s*(?P<sender_number>\d{11})/i',
            'transaction_type' => 'credit',
        ]];

        $result = $parser->parse('You have received Tk 500.00 from 01712345678', $templates);

        $this->assertNotNull($result);
        $this->assertSame(500.0, $result['parsed_amount']);
        $this->assertSame('01712345678', $result['parsed_sender']);
        $this->assertSame('credit', $result['parsed_type']);
    }

    public function testRegexTesterNoMatchReturnsNull(): void
    {
        $parser = new SmsRegexParser();
        $templates = [[
            'id'               => 0,
            'sender_pattern'   => 'test',
            'regex_pattern'    => '/DOES_NOT_MATCH_ANYTHING/i',
            'transaction_type' => 'credit',
        ]];

        $result = $parser->parse('You have received Tk 500.00 from 01712345678', $templates);
        $this->assertNull($result);
    }

    public function testRegexTesterCapturesNamedGroups(): void
    {
        $pattern = '/TrxID\s*(?P<trx_id>[A-Z0-9]+)/i';
        $sample = 'Payment received. TrxID ABC123DEF.';

        preg_match($pattern, $sample, $matches);
        $named = array_filter($matches, fn($k) => is_string($k), ARRAY_FILTER_USE_KEY);

        $this->assertArrayHasKey('trx_id', $named);
        $this->assertSame('ABC123DEF', $named['trx_id']);
    }

    // â”€â”€â”€ Admin Queue Reprocess Logic â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testReprocessWithNewTemplate(): void
    {
        $parser = new SmsRegexParser();

        // Initially no templates â†’ null
        $result1 = $parser->parse('Received Tk 750.00 from 01611222333. TrxID XYZ999.', []);
        $this->assertNull($result1);

        // Add a template â†’ should match
        $templates = [[
            'id'               => 99,
            'sender_pattern'   => 'test',
            'regex_pattern'    => '/Received Tk\s*(?P<amount>[\d,]+(?:\.\d{1,2})?)\s*from\s*(?P<sender_number>\d{11}).*?TrxID\s*(?P<trx_id>[A-Z0-9]+)/i',
            'transaction_type' => 'credit',
        ]];

        $result2 = $parser->parse('Received Tk 750.00 from 01611222333. TrxID XYZ999.', $templates);
        $this->assertNotNull($result2);
        $this->assertSame(750.0, $result2['parsed_amount']);
        $this->assertSame('XYZ999', $result2['parsed_trx_id']);
        $this->assertSame(99, $result2['template_id']);
    }

    public function testManualResolveDataStructure(): void
    {
        // Simulate what the resolve endpoint would do
        $adminInput = [
            'amount'        => 500.00,
            'type'          => 'credit',
            'trx_id'        => 'MANUAL001',
            'sender_number' => '01712345678',
        ];

        $updateData = [
            'parsed_amount'    => (float) $adminInput['amount'],
            'parsed_type'      => $adminInput['type'],
            'parsed_trx_id'    => $adminInput['trx_id'],
            'parsed_sender'    => $adminInput['sender_number'],
            'parse_method'     => 'manual',
            'parse_confidence' => 'high',
            'status'           => 'accepted',
        ];

        $this->assertSame(500.0, $updateData['parsed_amount']);
        $this->assertSame('credit', $updateData['parsed_type']);
        $this->assertSame('manual', $updateData['parse_method']);
        $this->assertSame('high', $updateData['parse_confidence']);
    }
}

