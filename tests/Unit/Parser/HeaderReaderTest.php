<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Tests\Unit\Parser;

use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use Puzl\PzOfx\Exceptions\PzOfxParseException;
use Puzl\PzOfx\Parser\HeaderReader;
use Puzl\PzOfx\Tests\TestCase;

final class HeaderReaderTest extends TestCase
{
    public function test_cabecalho_sgml_em_linhas(): void
    {
        [$header, $body] = HeaderReader::split("OFXHEADER:100\r\nDATA:OFXSGML\r\nVERSION:102\r\nENCODING:USASCII\r\nCHARSET:1252\r\n\r\n<OFX>\r\n</OFX>");

        $this->assertFalse($header->isXml);
        $this->assertTrue($header->isSgml());
        $this->assertSame('102', $header->version());
        $this->assertSame('USASCII', $header->encoding());
        $this->assertSame('1252', $header->charset());
        $this->assertSame('OFXSGML', $header->get('data'));
        $this->assertStringStartsWith('<OFX>', $body);
    }

    public function test_cabecalho_sgml_numa_linha_so_e_com_espacos(): void
    {
        [$header] = HeaderReader::split('OFXHEADER : 100 DATA:OFXSGML VERSION:151 CHARSET:ISO-8859-1<OFX></OFX>');

        $this->assertSame('100', $header->get('OFXHEADER'));
        $this->assertSame('151', $header->version());
        $this->assertSame('ISO-8859-1', $header->charset());
    }

    public function test_cabecalho_xml_ofx2(): void
    {
        [$header] = HeaderReader::split('<?xml version="1.0" encoding="UTF-8" standalone="no"?>'."\n".'<?OFX OFXHEADER="200" VERSION="211" SECURITY="NONE"?>'."\n<OFX></OFX>");

        $this->assertTrue($header->isXml);
        $this->assertSame('211', $header->version());
        $this->assertSame('200', $header->get('OFXHEADER'));
        $this->assertSame('UTF-8', $header->charset());
    }

    public function test_sem_cabecalho_e_tag_ofx_minuscula(): void
    {
        [$header, $body] = HeaderReader::split("  \n<ofx><x></ofx>");

        $this->assertSame([], $header->all());
        $this->assertNull($header->version());
        $this->assertSame('<ofx><x></ofx>', $body);
    }

    public function test_sem_elemento_ofx_nao_e_ofx(): void
    {
        try {
            HeaderReader::split('<html><body>Sessão expirada</body></html>');
            $this->fail('Deveria lançar exceção');
        } catch (PzOfxParseException $e) {
            $this->assertSame(PzOfxErrorEnum::NOT_OFX, $e->error());
        }
    }

    public function test_ofxheader_sozinho_nao_confunde_com_elemento(): void
    {
        $this->expectException(PzOfxParseException::class);

        HeaderReader::split('OFXHEADER:100 <OFXX>');
    }
}
