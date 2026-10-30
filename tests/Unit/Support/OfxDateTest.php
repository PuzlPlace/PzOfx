<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Tests\Unit\Support;

use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use Puzl\PzOfx\Enums\PzOfxTimezoneModeEnum;
use Puzl\PzOfx\Support\OfxDate;
use Puzl\PzOfx\Tests\TestCase;

final class OfxDateTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function localDates(): array
    {
        return [
            'só data' => ['20240115', '2024-01-15 00:00:00'],
            'data e hora' => ['20240115123456', '2024-01-15 12:34:56'],
            'sem segundos' => ['202401151234', '2024-01-15 12:34:00'],
            'milissegundos' => ['20240115123456.789', '2024-01-15 12:34:56'],
            'fuso BRT' => ['20240115120000[-3:BRT]', '2024-01-15 12:00:00'],
            'fuso com nome errado' => ['20240115000000[-03:EST]', '2024-01-15 00:00:00'],
            'GMT' => ['20240115120000[0:GMT]', '2024-01-15 12:00:00'],
            'offset sem nome' => ['20240115120000[-3]', '2024-01-15 12:00:00'],
            'milissegundos e fuso' => ['20240301093000.000[-3:BRT]', '2024-03-01 09:30:00'],
            'espaço antes do colchete' => ['20240115120000 [-3:BRT]', '2024-01-15 12:00:00'],
            'formato ISO tolerado' => ['2024-01-15 12:00:00', '2024-01-15 12:00:00'],
            'segundo 60' => ['20241231235960', '2024-12-31 23:59:59'],
            'ano bissexto' => ['20240229', '2024-02-29 00:00:00'],
            'zeros' => ['00000000', null],
            '31 de fevereiro' => ['20240231', null],
            'mês 13' => ['20241301', null],
            'hora 24' => ['20240115240000', null],
            'curto' => ['202401', null],
            'texto' => ['ontem', null],
            'vazio' => ['', null],
        ];
    }

    #[DataProvider('localDates')]
    public function test_modo_local_mantem_data_e_hora_do_arquivo(string $raw, ?string $expected): void
    {
        $date = OfxDate::parse($raw, new DateTimeZone('America/Sao_Paulo'), PzOfxTimezoneModeEnum::LOCAL);

        $this->assertSame($expected, $date?->format('Y-m-d H:i:s'));
        if ($date !== null) {
            $this->assertSame('America/Sao_Paulo', $date->getTimezone()->getName());
        }
    }

    public function test_modo_local_nao_empurra_lancamento_da_noite_para_o_dia_seguinte(): void
    {
        $date = OfxDate::parse('20240229233000[-3:BRT]', new DateTimeZone('UTC'), PzOfxTimezoneModeEnum::LOCAL);

        $this->assertSame('2024-02-29 23:30:00', $date?->format('Y-m-d H:i:s'));
    }

    public function test_modo_convert_aplica_o_offset_do_arquivo(): void
    {
        $utc = new DateTimeZone('UTC');

        $this->assertSame('2024-03-01 02:30:00', OfxDate::parse('20240229233000[-3:BRT]', $utc, PzOfxTimezoneModeEnum::CONVERT)?->format('Y-m-d H:i:s'));
        $this->assertSame('2024-01-15 06:30:00', OfxDate::parse('20240115120000[+5.30:IST]', $utc, PzOfxTimezoneModeEnum::CONVERT)?->format('Y-m-d H:i:s'));
        $this->assertSame('2024-01-15 06:30:00', OfxDate::parse('20240115120000[+5.5]', $utc, PzOfxTimezoneModeEnum::CONVERT)?->format('Y-m-d H:i:s'));
        $this->assertSame('2024-01-15 15:30:00', OfxDate::parse('20240115120000[-3.50:NST]', $utc, PzOfxTimezoneModeEnum::CONVERT)?->format('Y-m-d H:i:s'));
    }

    public function test_modo_convert_sem_offset_trata_como_fuso_configurado(): void
    {
        $date = OfxDate::parse('20240115120000', new DateTimeZone('America/Sao_Paulo'), PzOfxTimezoneModeEnum::CONVERT);

        $this->assertSame('2024-01-15 12:00:00', $date?->format('Y-m-d H:i:s'));
    }

    public function test_offset_invalido_e_ignorado_sem_perder_a_data(): void
    {
        $date = OfxDate::parse('20240115120000[XYZ]', new DateTimeZone('UTC'), PzOfxTimezoneModeEnum::CONVERT);

        $this->assertSame('2024-01-15 12:00:00', $date?->format('Y-m-d H:i:s'));
    }
}
