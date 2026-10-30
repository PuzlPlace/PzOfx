<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use Puzl\PzOfx\Support\Decimal;
use Puzl\PzOfx\Tests\TestCase;

final class DecimalTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function amounts(): array
    {
        return [
            'ponto decimal' => ['-50.00', '-50.00'],
            'vírgula decimal' => ['-50,00', '-50.00'],
            'milhar BR' => ['-1.234,56', '-1234.56'],
            'milhar US' => ['1,234.56', '1234.56'],
            'milhar BR sem centavos' => ['1.234.567', '1234567.00'],
            'milhar US sem centavos' => ['1,234,567', '1234567.00'],
            'inteiro' => ['1500', '1500.00'],
            'mais explícito' => ['+3.500,00', '3500.00'],
            'menos no fim' => ['50.00-', '-50.00'],
            'parênteses' => ['(50.00)', '-50.00'],
            'parênteses e menos' => ['(-50.00)', '50.00'],
            'fração sem inteiro' => [',5', '0.50'],
            'ponto no fim' => ['50.', '50.00'],
            'três casas' => ['0.123', '0.123'],
            'um ponto é decimal (spec)' => ['1.234', '1.234'],
            'espaços e NBSP' => [" - 1\u{00A0}234,56 ", '-1234.56'],
            'símbolo de moeda' => ['R$ -10,00', '-10.00'],
            'zeros à esquerda' => ['000012.30', '12.30'],
            'menos zero' => ['-0.00', '0.00'],
            'zero' => ['0', '0.00'],
            'vazio' => ['', null],
            'só sinal' => ['-', null],
            'texto' => ['abc', null],
            'notação científica' => ['1E3', null],
            'separadores bagunçados' => ['1,2,3.4,5', null],
            'só ponto' => ['.', null],
        ];
    }

    #[DataProvider('amounts')]
    public function test_parse_normaliza_formatos_reais_de_valor(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, Decimal::parse($raw));
    }

    public function test_soma_exata_sem_erro_de_float(): void
    {
        $this->assertSame('0.30', Decimal::sum('0.10', '0.20'));
        $this->assertSame('-1234.45', Decimal::sum('-1234.56', '0.11'));
        $this->assertSame('1.123', Decimal::sum('1.00', '0.123'));
        $this->assertSame('0.00', Decimal::sum('10.00', '-10.00'));
        $this->assertSame('0.00', Decimal::sum());
    }

    public function test_centavos_arredondam_metade_para_longe_do_zero(): void
    {
        $this->assertSame(-123456, Decimal::toMinor('-1234.56'));
        $this->assertSame(13, Decimal::toMinor('0.125'));
        $this->assertSame(-13, Decimal::toMinor('-0.125'));
        $this->assertSame(12, Decimal::toMinor('0.124'));
        $this->assertSame(150000, Decimal::toMinor('1500.00'));
    }

    public function test_sinal_e_comparacao(): void
    {
        $this->assertSame('-5.00', Decimal::negate('5.00'));
        $this->assertSame('5.00', Decimal::negate('-5.00'));
        $this->assertSame('0.00', Decimal::negate('0.00'));
        $this->assertSame('5.00', Decimal::abs('-5.00'));
        $this->assertTrue(Decimal::isZero('0.000'));
        $this->assertFalse(Decimal::isZero('0.01'));
        $this->assertSame(1, Decimal::compare('10.10', '10.09'));
        $this->assertSame(0, Decimal::compare('10.10', '10.100'));
        $this->assertSame(-1, Decimal::compare('-1.00', '0.00'));
    }
}
