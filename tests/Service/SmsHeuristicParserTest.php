<?php

declare(strict_types=1);

namespace Tests\Service;

use OwnPay\Service\Sms\SmsHeuristicParser;
use PHPUnit\Framework\TestCase;

final class SmsHeuristicParserTest extends TestCase
{
    private SmsHeuristicParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SmsHeuristicParser();
    }

    public function testCreditWithFullInfo(): void
    {
        $sms = 'You have received Tk 500.00 from 01712345678. TrxID ABC123. Balance Tk 2,500.00';

        $result = $this->parser->parse($sms);

        $this->assertNotNull($result);
        $this->assertSame(500.0, $result['parsed_amount']);
        $this->assertSame('ABC123', $result['parsed_trx_id']);
        $this->assertSame('01712345678', $result['parsed_sender']);
        $this->assertSame(2500.0, $result['parsed_balance']);
        $this->assertSame('credit', $result['parsed_type']);
        $this->assertSame('heuristic', $result['parse_method']);
        $this->assertSame('medium', $result['parse_confidence']);
        $this->assertNull($result['template_id']);
    }

    public function testCreditWithBdtPrefix(): void
    {
        $sms = 'BDT 1000 credited to your account. Ref: XYZ789';

        $result = $this->parser->parse($sms);

        $this->assertNotNull($result);
        $this->assertSame(1000.0, $result['parsed_amount']);
        $this->assertSame('XYZ789', $result['parsed_trx_id']);
        $this->assertSame('credit', $result['parsed_type']);
    }

    public function testCreditWithTakaPrefix(): void
    {
        $sms = 'Taka 750 deposited to your wallet from 01812345678';

        $result = $this->parser->parse($sms);

        $this->assertNotNull($result);
        $this->assertSame(750.0, $result['parsed_amount']);
        $this->assertSame('01812345678', $result['parsed_sender']);
        $this->assertSame('credit', $result['parsed_type']);
    }

    public function testDebitDetection(): void
    {
        $sms = 'You have sent Tk 300 to 01612345678. TrxID DEF456.';

        $result = $this->parser->parse($sms);

        $this->assertNotNull($result);
        $this->assertSame(300.0, $result['parsed_amount']);
        $this->assertSame('DEF456', $result['parsed_trx_id']);
        $this->assertSame('01612345678', $result['parsed_sender']);
        $this->assertSame('debit', $result['parsed_type']);
    }

    public function testCashOutDebit(): void
    {
        $sms = 'Cash Out Tk 1,500.00 from your account. TrxID COA123. Remaining balance Tk 500.00';

        $result = $this->parser->parse($sms);

        $this->assertNotNull($result);
        $this->assertSame(1500.0, $result['parsed_amount']);
        $this->assertSame(500.0, $result['parsed_balance']);
        $this->assertSame('debit', $result['parsed_type']);
    }

    public function testBalanceDisambiguation(): void
    {
        $sms = 'Received Tk 200.00. Your balance is Tk 800.00';

        $result = $this->parser->parse($sms);

        $this->assertNotNull($result);
        $this->assertSame(200.0, $result['parsed_amount']);
        $this->assertSame(800.0, $result['parsed_balance']);
    }

    public function testTxnIdVariant(): void
    {
        $sms = 'Received Tk 100. TxnID: TXN999ABC';

        $result = $this->parser->parse($sms);

        $this->assertNotNull($result);
        $this->assertSame('TXN999ABC', $result['parsed_trx_id']);
    }

    public function testTransactionIdFullLabel(): void
    {
        $sms = 'Received Tk 100. Transaction ID REF123XY';

        $result = $this->parser->parse($sms);

        $this->assertNotNull($result);
        $this->assertSame('REF123XY', $result['parsed_trx_id']);
    }

    public function testMediumConfidenceWithAmountAndType(): void
    {
        $sms = 'Credited Tk 500 to your account.';

        $result = $this->parser->parse($sms);

        $this->assertNotNull($result);
        $this->assertSame('medium', $result['parse_confidence']);
    }

    public function testLowConfidenceWithAmountOnly(): void
    {
        $sms = 'Tk 500 transaction processed.';

        $result = $this->parser->parse($sms);

        $this->assertNotNull($result);
        $this->assertSame('unknown', $result['parsed_type']);
        $this->assertSame('low', $result['parse_confidence']);
    }

    public function testCommaAmount(): void
    {
        $sms = 'Received Tk 25,000.50 from 01712345678.';

        $result = $this->parser->parse($sms);

        $this->assertNotNull($result);
        $this->assertSame(25000.5, $result['parsed_amount']);
    }

    public function testGarbageTextReturnsNull(): void
    {
        $sms = 'Grameenphone welcomes you! Dial *121# for balance.';

        $result = $this->parser->parse($sms);
        $this->assertNull($result);
    }

    public function testNoAmountButHasPhoneReturnsPartial(): void
    {
        $sms = 'Call 01712345678 for support.';

        $result = $this->parser->parse($sms);

        $this->assertNotNull($result);
        $this->assertNull($result['parsed_amount']);
        $this->assertSame('01712345678', $result['parsed_sender']);
    }

    public function testOtpMessageReturnsNull(): void
    {
        $sms = 'Your OTP is 123456. Do not share.';

        $result = $this->parser->parse($sms);
        $this->assertNull($result);
    }
}
