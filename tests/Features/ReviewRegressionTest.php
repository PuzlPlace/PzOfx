<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Tests\Features;

use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use Puzl\PzOfx\Enums\PzOfxWarningEnum;
use Puzl\PzOfx\Exceptions\PzOfxException;
use Puzl\PzOfx\Laravel\Rules\PzOfxFileRule;
use Puzl\PzOfx\PzOfx;
use Puzl\PzOfx\Support\Decimal;
use Puzl\PzOfx\Support\Encoding;
use Puzl\PzOfx\Tests\TestCase;

/** Um teste por defeito encontrado em revisão. */
final class ReviewRegressionTest extends TestCase
{
    public function test_perfil_por_bank_id_alcanca_cartao_pelo_fid(): void
    {
        $content = str_replace('<LANGUAGE>POR', '<LANGUAGE>POR<FI><ORG>NU<FID>260</FI>', (string) file_get_contents(self::fixture('credit_card.ofx')));

        $statement = PzOfx::fromString($content, [
            'profiles' => [['match' => ['bank_id' => ['260', '336'], 'statement' => 'credit_card'], 'amount_sign' => 'invert']],
        ])->statement();

        $this->assertSame('-149.90', $statement->transactions[0]->amount);
    }

    public function test_comentario_cdata_e_marcacao_sem_fechamento_nao_engolem_o_arquivo(): void
    {
        foreach (['PIX <!-- teste', 'X <![CDATA[ Y', 'LOJA <!X', 'A <?b'] as $memo) {
            $document = PzOfx::fromString(self::ofx(self::trn(fitId: '1', memo: $memo).self::trn('-2.00', fitId: '2').self::trn('-3.00', fitId: '3')));

            $this->assertCount(3, $document->transactions(), $memo);
            $this->assertSame($memo, $document->transactions()[0]->memo);
        }
    }

    public function test_currency_vazio_sem_fechamento_nao_engole_os_irmaos(): void
    {
        $transaction = PzOfx::fromString(self::ofx("<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20240101<CURRENCY>\n<TRNAMT>-9.00\n<FITID>1\n<MEMO>X</STMTTRN>"))->transactions()[0];

        $this->assertSame('-9.00', $transaction->amount);
        $this->assertSame('X', $transaction->memo);
    }

    public function test_valores_absurdos_sao_rejeitados_e_somas_nao_estouram(): void
    {
        $this->assertNull(Decimal::parse('0.00000000000000000001'));
        $this->assertNull(Decimal::parse('99999999999999999999.00'));
        $this->assertSame('1.50', Decimal::parse('1.500000000000'), 'zeros à direita não contam');
        $this->assertSame('999999999999999.99', Decimal::parse('999999999999999.99'));
        $this->assertSame('1999999999999999.98', Decimal::sum('999999999999999.99', '999999999999999.99'));
        $this->assertSame('0.00000001', Decimal::sum('0.00000002', '-0.00000001'));
        $this->assertSame('-0.99', Decimal::sum('0.01', '-1.00'));
        $this->assertSame(1, Decimal::compare('1000000000000000.00', '999999999999999.99'));
    }

    public function test_muitas_tags_soltas_no_memo_nao_tem_custo_quadratico(): void
    {
        $memo = 'x'.str_repeat("<FOO> y", 50000);

        $start = microtime(true);
        $document = PzOfx::fromString(self::ofx(self::trn(memo: $memo)));

        $this->assertLessThan(5, microtime(true) - $start);
        $this->assertCount(1, $document->transactions());
    }

    public function test_campo_saldo_so_no_name_com_memo_de_pagamento_nao_e_pulado(): void
    {
        $document = PzOfx::fromString(self::ofx(self::trn('-500.00', memo: 'PAGAMENTO FORNECEDOR XYZ', extra: "<NAME>SALDO\n")));

        $this->assertCount(1, $document->transactions());
    }

    public function test_saldo_sobrevive_a_banktranlist_sem_fechamento(): void
    {
        $content = str_replace(['</BANKTRANLIST>', '</STMTRS>'], ['', '<LEDGERBAL><BALAMT>10.00<DTASOF>20240131</LEDGERBAL><AVAILBAL><BALAMT>8,00</AVAILBAL></STMTRS>'], self::ofx(self::trn()));

        $statement = PzOfx::fromString($content)->statement();

        $this->assertSame('10.00', $statement->ledgerBalance?->amount);
        $this->assertSame('8.00', $statement->availableBalance?->amount);
        $this->assertCount(1, $statement);
    }

    public function test_regra_nunca_le_caminho_de_arquivo_do_servidor(): void
    {
        $errors = [];
        (new PzOfxFileRule())->validate('file', self::fixture('legacy_classic.ofx'), static function (string $m) use (&$errors): void {
            $errors[] = $m;
        });

        $this->assertSame([PzOfxErrorEnum::NOT_OFX->message()], $errors);
    }

    public function test_tag_ofx_com_atributos(): void
    {
        $this->assertCount(1, PzOfx::fromString(str_replace('<OFX>', '<OFX xmlns="http://ofx.net/types/2003/04">', self::ofx(self::trn())))->transactions());
    }

    public function test_placeholder_vindo_do_arquivo_nao_e_substituido_de_novo(): void
    {
        $message = PzOfxErrorEnum::INVALID_AMOUNT->message(['index' => 1, 'field' => 'TRNAMT', 'value' => 'abc:index']);

        $this->assertStringContainsString('"abc:index"', $message);
    }

    public function test_utf8_com_byte_latin1_solto_so_conserta_o_byte(): void
    {
        $this->assertSame('CAFÉ JOSÉ', Encoding::toUtf8("CAF\xC3\x89 JOS\xC9", null, null));
        $this->assertSame('JOSÉ', Encoding::toUtf8("JOS\xC9", null, null), 'Latin-1 puro continua Latin-1');
    }

    public function test_segundos_invalidos_e_memo_repetido(): void
    {
        $this->assertSame(PzOfxErrorEnum::INVALID_DATE, $this->errorOf(self::ofx(self::trn(date: '20240115120075'))));

        $transaction = PzOfx::fromString(self::ofx(self::trn(memo: '', extra: "<MEMO>REAL\n")))->transactions()[0];
        $this->assertSame('REAL', $transaction->memo);
    }

    public function test_fingerprint_nao_muda_com_fuso_nem_modo(): void
    {
        $content = self::ofx(self::trn(date: '20240229233000[-3:BRT]'));

        $a = PzOfx::fromString($content)->transactions()[0];
        $b = PzOfx::fromString($content, ['timezone' => 'UTC', 'timezone_mode' => 'convert'])->transactions()[0];

        $this->assertSame('2024-03-01', $b->date());
        $this->assertSame($a->fingerprint, $b->fingerprint);
    }

    public function test_aviso_de_linha_de_saldo_usa_o_memo(): void
    {
        $document = PzOfx::fromString(self::ofx(self::trn(memo: 'SALDO ANTERIOR')));

        $this->assertCount(1, $document->warningsOf(PzOfxWarningEnum::BALANCE_ROW_SKIPPED));
    }

    private function errorOf(string $content): ?PzOfxErrorEnum
    {
        try {
            PzOfx::fromString($content);
        } catch (PzOfxException $e) {
            return $e->error();
        }

        return null;
    }
}
