<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Tests\Unit\Support;

use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use Puzl\PzOfx\Exceptions\PzOfxParseException;
use Puzl\PzOfx\Support\Encoding;
use Puzl\PzOfx\Tests\TestCase;

final class EncodingTest extends TestCase
{
    public function test_utf8_valido_fica_como_esta_mesmo_com_cabecalho_dizendo_1252(): void
    {
        $this->assertSame('TRANSFERÊNCIA João', Encoding::toUtf8('TRANSFERÊNCIA João', '1252', null));
    }

    public function test_bytes_latin1_e_cp1252_viram_utf8(): void
    {
        $this->assertSame('TARIFA BANCÁRIA – €5', Encoding::toUtf8(mb_convert_encoding('TARIFA BANCÁRIA – €5', 'Windows-1252', 'UTF-8'), '1252', null));
        $this->assertSame('POUPANÇA', Encoding::toUtf8(mb_convert_encoding('POUPANÇA', 'ISO-8859-1', 'UTF-8'), 'ISO-8859-1', null));
        $this->assertSame('AÇÃO', Encoding::toUtf8(mb_convert_encoding('AÇÃO', 'ISO-8859-1', 'UTF-8'), 'NONE', null));
    }

    public function test_cabecalho_utf8_mentindo_cai_em_windows_1252(): void
    {
        $this->assertSame('JOSÉ', Encoding::toUtf8(mb_convert_encoding('JOSÉ', 'ISO-8859-1', 'UTF-8'), 'UTF-8', null));
    }

    public function test_charset_declarado_diferente_e_respeitado(): void
    {
        $this->assertSame('€', Encoding::toUtf8("\xA4", 'ISO-8859-15', null));
    }

    public function test_charset_desconhecido_cai_em_windows_1252(): void
    {
        $this->assertSame('É', Encoding::toUtf8("\xC9", 'KLINGON', null));
    }

    public function test_encoding_forcado_vence_a_deteccao(): void
    {
        // UTF-8 válido, mas o forçado vence.
        $this->assertSame('Ã©', Encoding::toUtf8("\xC3\xA9", null, 'Windows-1252'));
    }

    public function test_encoding_forcado_inexistente_lanca(): void
    {
        try {
            Encoding::toUtf8('x', null, 'NAO-EXISTE');
            $this->fail('Deveria lançar exceção');
        } catch (PzOfxParseException $e) {
            $this->assertSame(PzOfxErrorEnum::UNSUPPORTED_ENCODING, $e->error());
        }
    }

    public function test_bom_e_utf16(): void
    {
        $this->assertSame('<OFX>', Encoding::normalizeBom("\xEF\xBB\xBF<OFX>"));
        $this->assertSame('<OFX>ç', Encoding::normalizeBom("\xFF\xFE".mb_convert_encoding('<OFX>ç', 'UTF-16LE', 'UTF-8')));
        $this->assertSame('<OFX>ç', Encoding::normalizeBom("\xFE\xFF".mb_convert_encoding('<OFX>ç', 'UTF-16BE', 'UTF-8')));
        $this->assertSame('OFXHEADER:100 <OFX>', Encoding::normalizeBom(mb_convert_encoding('OFXHEADER:100 <OFX>', 'UTF-16LE', 'UTF-8')));
        $this->assertSame('<OFX>', Encoding::normalizeBom('<OFX>'));
    }

    public function test_caracteres_de_controle_sao_removidos(): void
    {
        $this->assertSame("A B\tC\r\nD", Encoding::toUtf8("A\x00 B\tC\r\n\x1FD\x7F", null, null));
    }
}
