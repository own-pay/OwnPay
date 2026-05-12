<?php

declare(strict_types=1);

namespace Tests\Service;

use OwnPay\Service\Sms\SmsRegexParser;
use PHPUnit\Framework\TestCase;

/**
 * SmsRegexParserTest â€” Unit tests for Tier 1 regex-based SMS parsing.
 *
 * Tests cover:
 *   - bKash credit/debit patterns
 *   - Nagad patterns
 *   - Optional field handling (trx_id, balance)
 *   - Comma-separated amounts
 *   - No-match fallthrough
 *   - Invalid regex skip
 *   - Amount-less match skip
 */
final class SmsRegexParserTest extends TestCase
{
    private SmsRegexParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SmsRegexParser();
    }

    // â”€â”€â”€ bKash Credit â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testBkashCreditFullMatch(): void
    {
        $templates = [$this->bkashCreditTemplate()];
        $sms = 'You have received Tk 500.00 from 01712345678. TrxID ABC1234. Your new balance is Tk 1,500.50.';

        $result = $this->parser->parse($sms, $templates);

        $this->assertNotNull($result);
        $this->assertSame(500.0, $result['parsed_amount']);
        $this->assertSame('ABC1234', $result['parsed_trx_id']);
        $this->assertSame('01712345678', $result['parsed_sender']);
        $this->assertSame(1500.5, $result['parsed_balance']);
        $this->assertSame('credit', $result['parsed_type']);
        $this->assertSame('regex', $result['parse_method']);
        $this->assertSame('high', $result['parse_confidence']);
        $this->assertSame(1, $result['template_id']);
    }

    public function testBkashCreditWithoutTrxId(): void
    {
        $templates = [$this->bkashCreditTemplate()];
        $sms = 'You have received Tk 250.00 from 01812345678. Your new balance is Tk 750.00.';

        $result = $this->parser->parse($sms, $templates);

        $this->assertNotNull($result);
        $this->assertSame(250.0, $result['parsed_amount']);
        $this->assertNull($result['parsed_trx_id']);
        $this->assertSame('01812345678', $result['parsed_sender']);
        $this->assertSame(750.0, $result['parsed_balance']);
    }

    public function testBkashCreditWithoutBalance(): void
    {
        $templates = [$this->bkashCreditTemplate()];
        $sms = 'You have received Tk 100 from 01912345678. TrxID XYZ999.';

        $result = $this->parser->parse($sms, $templates);

        $this->assertNotNull($result);
        $this->assertSame(100.0, $result['parsed_amount']);
        $this->assertSame('XYZ999', $result['parsed_trx_id']);
        $this->assertNull($result['parsed_balance']);
    }

    public function testBkashCreditCommaAmount(): void
    {
        $templates = [$this->bkashCreditTemplate()];
        $sms = 'You have received Tk 12,500.75 from 01712345678. TrxID AAA111.';

        $result = $this->parser->parse($sms, $templates);

        $this->assertNotNull($result);
        $this->assertSame(12500.75, $result['parsed_amount']);
    }

    // â”€â”€â”€ bKash Debit â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testBkashSendMoney(): void
    {
        $template = [
            'id' => 4,
            'regex_pattern' => '/You have sent Tk\s*(?P<amount>[\d,]+(?:\.\d{1,2})?)\s*to\s*(?P<sender_number>\d{11})(?:.*?TrxID\s*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:balance|Balance)\s*(?:is\s*)?Tk\s*(?P<balance>[\d,]+(?:\.\d{1,2})?))?/i',
            'transaction_type' => 'debit',
        ];

        $sms = 'You have sent Tk 200.00 to 01612345678. TrxID DEF456. Balance is Tk 300.00.';
        $result = $this->parser->parse($sms, [$template]);

        $this->assertNotNull($result);
        $this->assertSame(200.0, $result['parsed_amount']);
        $this->assertSame('DEF456', $result['parsed_trx_id']);
        $this->assertSame('01612345678', $result['parsed_sender']);
        $this->assertSame(300.0, $result['parsed_balance']);
        $this->assertSame('debit', $result['parsed_type']);
    }

    // â”€â”€â”€ Nagad â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testNagadCredit(): void
    {
        $template = [
            'id' => 5,
            'regex_pattern' => '/(?:You have received|Received)\s*Tk\.?\s*(?P<amount>[\d,]+(?:\.\d{1,2})?)\s*from\s*(?P<sender_number>\d{11})(?:.*?TxnID[:\s]*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:balance|Balance)[:\s]*Tk\.?\s*(?P<balance>[\d,]+(?:\.\d{1,2})?))?/i',
            'transaction_type' => 'credit',
        ];

        $sms = 'You have received Tk. 1,000.00 from 01312345678. TxnID: NAG789. Balance: Tk. 5,000.00';
        $result = $this->parser->parse($sms, [$template]);

        $this->assertNotNull($result);
        $this->assertSame(1000.0, $result['parsed_amount']);
        $this->assertSame('NAG789', $result['parsed_trx_id']);
        $this->assertSame('01312345678', $result['parsed_sender']);
        $this->assertSame(5000.0, $result['parsed_balance']);
        $this->assertSame('credit', $result['parsed_type']);
    }

    // â”€â”€â”€ Edge Cases â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testNoMatchReturnsNull(): void
    {
        $templates = [$this->bkashCreditTemplate()];
        $sms = 'Your OTP is 123456. Do not share with anyone.';

        $result = $this->parser->parse($sms, $templates);
        $this->assertNull($result);
    }

    public function testEmptyTemplatesReturnsNull(): void
    {
        $result = $this->parser->parse('Some SMS text', []);
        $this->assertNull($result);
    }

    public function testInvalidRegexSkipped(): void
    {
        $templates = [
            ['id' => 99, 'regex_pattern' => '/[invalid(regex/', 'transaction_type' => 'credit'],
            $this->bkashCreditTemplate(),
        ];

        $sms = 'You have received Tk 100 from 01712345678.';
        $result = $this->parser->parse($sms, $templates);

        $this->assertNotNull($result);
        $this->assertSame(1, $result['template_id']);
    }

    public function testEmptyRegexPatternSkipped(): void
    {
        $templates = [
            ['id' => 98, 'regex_pattern' => '', 'transaction_type' => 'credit'],
        ];

        $result = $this->parser->parse('Some text', $templates);
        $this->assertNull($result);
    }

    public function testPriorityOrder(): void
    {
        // Two templates match, first one should win
        $t1 = $this->bkashCreditTemplate();
        $t1['id'] = 10;
        $t2 = $this->bkashCreditTemplate();
        $t2['id'] = 20;

        $sms = 'You have received Tk 999 from 01712345678.';
        $result = $this->parser->parse($sms, [$t1, $t2]);

        $this->assertSame(10, $result['template_id']);
    }

    public function testZeroAmountRejected(): void
    {
        $templates = [[
            'id'               => 1,
            'regex_pattern'    => '/Tk\s*(?P<amount>[\d,]+(?:\.\d{1,2})?)/i',
            'transaction_type' => 'credit',
        ]];

        $sms = 'Tk 0.00 balance notification.';
        $result = $this->parser->parse($sms, $templates);
        $this->assertNull($result); // 0 amount = not useful
    }

    // â”€â”€â”€ Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function bkashCreditTemplate(): array
    {
        return [
            'id'               => 1,
            'regex_pattern'    => '/You have received Tk\s*(?P<amount>[\d,]+(?:\.\d{1,2})?)\s*from\s*(?P<sender_number>\d{11})(?:.*?TrxID\s*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:new balance|Balance)\s*(?:is\s*)?Tk\s*(?P<balance>[\d,]+(?:\.\d{1,2})?))?/i',
            'transaction_type' => 'credit',
        ];
    }
}

