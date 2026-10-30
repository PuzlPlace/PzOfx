<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Puzl\PzOfx\PzOfx;

/** Fuso fixo e estado global do PzOfx zerado a cada teste. */
abstract class TestCase extends BaseTestCase
{
    private string $previousTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('America/Sao_Paulo');
        PzOfx::reset();
    }

    protected function tearDown(): void
    {
        PzOfx::reset();
        date_default_timezone_set($this->previousTimezone);
        parent::tearDown();
    }

    protected static function fixture(string $name): string
    {
        return __DIR__.'/Fixtures/ofx/'.$name;
    }

    /** OFX SGML mínimo, uma conta. */
    protected static function ofx(string $transactions, string $account = '<BANKID>341<ACCTID>12345-6<ACCTTYPE>CHECKING'): string
    {
        return "OFXHEADER:100\nDATA:OFXSGML\nVERSION:102\n\n<OFX>\n<BANKMSGSRSV1><STMTTRNRS><TRNUID>1<STMTRS><CURDEF>BRL\n"
            ."<BANKACCTFROM>{$account}</BANKACCTFROM>\n<BANKTRANLIST>\n{$transactions}\n</BANKTRANLIST>\n"
            ."</STMTRS></STMTTRNRS></BANKMSGSRSV1>\n</OFX>\n";
    }

    protected static function trn(string $amount = '-10.00', string $date = '20240115', string $fitId = 'F1', string $memo = 'MEMO', string $extra = ''): string
    {
        return "<STMTTRN>\n<TRNTYPE>DEBIT\n<DTPOSTED>{$date}\n<TRNAMT>{$amount}\n<FITID>{$fitId}\n<MEMO>{$memo}\n{$extra}</STMTTRN>";
    }
}
