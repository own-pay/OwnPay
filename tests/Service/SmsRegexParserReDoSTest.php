<?php

declare(strict_types=1);

namespace Tests\Service;

use OwnPay\Service\Sms\SmsRegexParser;
use PHPUnit\Framework\TestCase;

final class SmsRegexParserReDoSTest extends TestCase
{
    private SmsRegexParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SmsRegexParser();
    }

    public function testCatastrophicPatternDoesNotHang(): void
    {
        // Catastrophic-backtracking template from a malicious staff member
        $template = [
            'id' => 7,
            'amount_regex' => '/(a+)+b/',
            'transaction_type' => 'credit',
        ];
        $body = str_repeat('a', 60);

        $start = microtime(true);
        $result = $this->parser->parse($body, [$template]);
        $elapsed = microtime(true) - $start;

        $this->assertNull($result);
        // Without the backtrack cap this pattern would run for many seconds
        $this->assertLessThan(1.0, $elapsed, 'ReDoS pattern was not bounded - execution took too long');
    }

    public function testBenignPatternStillMatchesAfterGuard(): void
    {
        $template = [
            'id' => 8,
            'amount_regex' => '/Tk\s*([\d,]+(?:\.\d{2})?)/i',
            'transaction_type' => 'credit',
        ];
        $body = 'Payment of Tk 1,250.75 received successfully.';

        $result = $this->parser->parse($body, [$template]);

        $this->assertNotNull($result);
        $this->assertSame(1250.75, $result['parsed_amount']);
    }
}
