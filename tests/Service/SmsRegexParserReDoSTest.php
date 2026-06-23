<?php

declare(strict_types=1);

namespace Tests\Service;

use OwnPay\Service\Sms\SmsRegexParser;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the ReDoS guard on merchant-supplied SMS regex templates: a
 * catastrophic-backtracking pattern executed against a crafted SMS body must
 * fail fast (bounded PCRE backtracking) instead of pinning a CPU and stalling
 * the SMS cron / mobile endpoint.
 */
final class SmsRegexParserReDoSTest extends TestCase
{
    private SmsRegexParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SmsRegexParser();
    }

    public function testCatastrophicPatternDoesNotHang(): void
    {
        // Classic catastrophic-backtracking template configured by a malicious
        // staff member, run against a long non-matching body.
        $template = [
            'id' => 7,
            'amount_regex' => '/(a+)+b/',
            'transaction_type' => 'credit',
        ];
        $body = str_repeat('a', 60); // no trailing 'b' → forces backtracking

        $start = microtime(true);
        $result = $this->parser->parse($body, [$template]);
        $elapsed = microtime(true) - $start;

        // No match (the bounded execution returns false → treated as no match).
        $this->assertNull($result);
        // Must complete well under a second; without the backtrack cap this
        // pattern would run for many seconds / effectively hang.
        $this->assertLessThan(1.0, $elapsed, 'ReDoS pattern was not bounded — execution took too long');
    }

    public function testBenignPatternStillMatchesAfterGuard(): void
    {
        // The backtracking cap must not break ordinary, well-formed templates.
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
