<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Tests\Features;

use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use Puzl\PzOfx\Compat\PzOfxLegacy;
use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use Puzl\PzOfx\Exceptions\PzOfxException;
use Puzl\PzOfx\Tests\Fixtures\Legacy\Ofx;
use Puzl\PzOfx\Tests\TestCase;
use Throwable;

/** Contra uma cópia do leitor legado: onde ele lê certo, o resultado é idêntico. */
final class LegacyCompatTest extends TestCase
{
    /**
     * Arquivos que o leitor legado lê corretamente.
     *
     * @return array<string, array{0: string}>
     */
    public static function legacyReadableFixtures(): array
    {
        return [
            'Caixa, ponto decimal' => ['legacy_classic.ofx'],
            'Santander, vírgula decimal' => ['santander_comma.ofx'],
        ];
    }

    #[DataProvider('legacyReadableFixtures')]
    public function test_identico_ao_leitor_antigo_onde_ele_le_certo(string $fixture): void
    {
        $legacy = new Ofx(self::fixture($fixture));
        $compat = new PzOfxLegacy(self::fixture($fixture));

        $this->assertSame(self::normalize($legacy->bankTransactions), $compat->bankTransactions);
        foreach (['startAt', 'endAt', 'bankCode', 'account', 'accountDigit', 'org'] as $property) {
            $this->assertSame((string) $legacy->{$property}, $compat->{$property}, $property);
        }
    }

    public function test_mesmo_formato_e_tipos_das_linhas(): void
    {
        $compat = new PzOfxLegacy(self::fixture('legacy_classic.ofx'));

        $this->assertSame([
            'type' => 'DEBIT',
            'date_at' => '2024-04-03 00:00:00',
            'value' => '-35.70',
            'fit_id' => '202404030001',
            'check_num' => '000002',
            'ref_number' => '',
            'description' => 'DEB TARIFA PACOTE',
        ], $compat->bankTransactions[1]);
        $this->assertSame('CAIXA', $compat->org);
        $this->assertSame('000123456789', $compat->account);
        $this->assertSame('0', $compat->accountDigit);
    }

    public function test_aceita_o_mesmo_tipo_de_entrada_que_o_antigo(): void
    {
        $file = new \SplFileInfo(self::fixture('legacy_classic.ofx'));

        $this->assertCount(4, (new PzOfxLegacy($file))->bankTransactions);
        $this->assertCount(4, (new PzOfxLegacy($file->getPathname()))->readFile()->bankTransactions);
        $this->assertSame(4, (new PzOfxLegacy($file))->document()->statement()->count());
    }

    public function test_bradesco_cp1252_preserva_acentos_e_simbolos_que_o_antigo_apagava(): void
    {
        $legacy = new Ofx(self::fixture('bradesco_cp1252.ofx'));
        $compat = new PzOfxLegacy(self::fixture('bradesco_cp1252.ofx'));

        $this->assertSame('PAGTO ELETRON COBRANCA GUA ESGOTO', $legacy->bankTransactions[0]['description']);
        $this->assertSame('PAGTO ELETRON COBRANCA ÁGUA & ESGOTO', $compat->bankTransactions[0]['description']);

        foreach (['type', 'date_at', 'fit_id', 'check_num', 'ref_number'] as $key) {
            $this->assertSame(array_column($legacy->bankTransactions, $key), array_column($compat->bankTransactions, $key), $key);
        }
        $this->assertSame(
            array_map('floatval', array_column($legacy->bankTransactions, 'value')),
            array_map('floatval', array_column($compat->bankTransactions, 'value')),
        );
    }

    public function test_texto_cabe_em_colunas_utf8mb3(): void
    {
        $long = str_repeat('PAGAMENTO FORNECEDOR ', 20);
        $content = self::ofx(self::trn(memo: 'Rendimento 💰 líquido 🚀', extra: '<CHECKNUM>'.str_repeat('9', 80)."\n<REFNUM>R1\n")
            .self::trn(fitId: 'F2', memo: $long));

        $rows = (new PzOfxLegacy($content))->bankTransactions;

        $this->assertSame('Rendimento líquido', $rows[0]['description'], 'sem emoji (4 bytes), acento preservado');
        $this->assertSame(55, strlen($rows[0]['check_num']));
        $this->assertSame(255, mb_strlen($rows[1]['description']));
        $this->assertSame('R1', $rows[0]['ref_number']);
    }

    public function test_itau_linhas_de_saldo_que_o_antigo_importava_como_credito(): void
    {
        $legacy = new Ofx(self::fixture('itau_balance_rows.ofx'));
        $compat = new PzOfxLegacy(self::fixture('itau_balance_rows.ofx'));

        $this->assertCount(8, $legacy->bankTransactions);
        $this->assertSame('SALDO TOTAL DISPONVEL DIA', $legacy->bankTransactions[3]['description']);
        $this->assertCount(4, $compat->bankTransactions);
        $this->assertSame(['20250723002', '20250723003', '20250724295', '20250724296'], array_column($compat->bankTransactions, 'fit_id'));
    }

    public function test_periodo_pela_menor_e_maior_data_e_nao_pela_ordem_do_arquivo(): void
    {
        $legacy = new Ofx(self::fixture('nubank_v2_bom.ofx'));
        $compat = new PzOfxLegacy(self::fixture('nubank_v2_bom.ofx'));

        $this->assertSame('2024-02-29 23:30:00', $legacy->startAt, 'antigo: início depois do fim');
        $this->assertSame('2024-02-10 00:00:00', $legacy->endAt);
        $this->assertSame('2024-02-10 00:00:00', $compat->startAt);
        $this->assertSame('2024-02-29 23:30:00', $compat->endAt);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function filesTheLegacyCannotRead(): array
    {
        return [
            'Itaú numa linha' => ['itau_single_line.ofx', 2],
            'BB com linhas de saldo' => ['bb_balance_rows.ofx', 4],
            'várias contas' => ['multi_account.ofx', 1],
            'Sicoob sem DTPOSTED' => ['sicoob_no_dtposted.ofx', 2],
        ];
    }

    #[DataProvider('filesTheLegacyCannotRead')]
    public function test_le_arquivos_que_quebravam_o_antigo(string $fixture, int $expected): void
    {
        $previous = libxml_use_internal_errors(true);
        try {
            new Ofx(self::fixture($fixture));
            $this->fail('O leitor antigo deveria falhar neste arquivo');
        } catch (Throwable) {
            // esperado: o legado quebra
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $this->assertCount($expected, (new PzOfxLegacy(self::fixture($fixture)))->bankTransactions);
    }

    public function test_mesmas_chaves_de_traducao_nos_erros(): void
    {
        foreach ([null, ''] as $input) {
            try {
                new PzOfxLegacy($input);
                $this->fail('Deveria lançar exceção');
            } catch (Exception $e) {
                $this->assertSame('exceptions.file_not_informed', $e->getMessage());
            }
        }

        // O legado também recusava.
        foreach (['credit_card.ofx' => null, 'empty' => self::ofx('')] as $fixture => $content) {
            try {
                new PzOfxLegacy($content ?? self::fixture($fixture));
                $this->fail('Deveria lançar exceção');
            } catch (Exception $e) {
                $this->assertSame('validation_financial.bank_reconciliations.ofx_file_transactions_is_empty', $e->getMessage());
            }
        }
    }

    public function test_arquivo_invalido_lanca_mensagem_que_nao_e_chave_de_traducao(): void
    {
        // Mensagem que não é chave de tradução cai na mensagem genérica de quem chama.
        try {
            new PzOfxLegacy('/tmp/nao-existe-'.uniqid().'.ofx');
            $this->fail('Deveria lançar exceção');
        } catch (PzOfxException $e) {
            $this->assertSame(PzOfxErrorEnum::FILE_NOT_FOUND, $e->error());
            $this->assertStringContainsString(' ', $e->getMessage());
        }
    }

    /**
     * O legado devolvia float em arquivo com vírgula; o compat, sempre string.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function normalize(array $rows): array
    {
        return array_map(static function (array $row): array {
            $row['value'] = is_float($row['value']) ? number_format($row['value'], 2, '.', '') : $row['value'];

            return $row;
        }, $rows);
    }
}
