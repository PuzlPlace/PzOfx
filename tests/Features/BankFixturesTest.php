<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Tests\Features;

use Puzl\PzOfx\Enums\PzOfxAccountTypeEnum;
use Puzl\PzOfx\Enums\PzOfxDirectionEnum;
use Puzl\PzOfx\Enums\PzOfxStatementTypeEnum;
use Puzl\PzOfx\Enums\PzOfxTransactionTypeEnum;
use Puzl\PzOfx\Enums\PzOfxWarningEnum;
use Puzl\PzOfx\Data\PzOfxTransaction;
use Puzl\PzOfx\PzOfx;
use Puzl\PzOfx\Tests\TestCase;

/** Um arquivo por estilo de banco. */
final class BankFixturesTest extends TestCase
{
    /**
     * @param list<PzOfxTransaction> $transactions
     * @return list<string>
     */
    private static function amounts(array $transactions): array
    {
        return array_map(static fn (PzOfxTransaction $t): string => $t->amount, $transactions);
    }

    public function test_bradesco_cp1252_crlf_virgula_decimal_e_fitid_repetido(): void
    {
        $document = PzOfx::fromFile(self::fixture('bradesco_cp1252.ofx'));
        $statement = $document->statement();

        $this->assertSame('102', $document->header->version());
        $this->assertSame('237', $statement->account->bankCode());
        $this->assertSame('0237', $statement->account->bankId);
        $this->assertSame('Bradesco', $statement->account->bankName());
        $this->assertSame('1234', $statement->account->branchId);
        $this->assertSame('98765', $statement->account->number());
        $this->assertSame('4', $statement->account->digit());
        $this->assertSame(PzOfxAccountTypeEnum::CHECKING, $statement->account->type);
        $this->assertSame('BRL', $statement->currency);
        $this->assertSame(['-1234.56', '-50.00', '-50.00', '3500.00'], self::amounts($statement->transactions));

        $first = $statement->transactions[0];
        $this->assertSame('PAGTO ELETRON COBRANCA ÁGUA & ESGOTO', $first->description);
        $this->assertSame('000123', $first->checkNumber);
        $this->assertSame('2024-01-02 00:00:00', $first->postedAt->format('Y-m-d H:i:s'));
        $this->assertSame('TARIFA BANCÁRIA CESTA', $statement->transactions[1]->memo, 'espaços colapsados');
        $this->assertSame('TED RECEBIDA JOSÉ DA SILVA – #REF 77', $statement->transactions[3]->memo, 'travessão cp1252 e # preservados');

        $this->assertSame(['20240105001' => 2], $statement->duplicateFitIds());
        $this->assertNotSame($statement->transactions[1]->uniqueId, $statement->transactions[2]->uniqueId);
        $this->assertSame('20240102001', $first->uniqueId);
        $this->assertCount(1, $document->warningsOf(PzOfxWarningEnum::DUPLICATE_FIT_ID));

        $this->assertSame('2165.44', $statement->ledgerBalance?->amount);
        $this->assertSame('2024-01-01', $statement->startDate?->format('Y-m-d'));
        $this->assertSame('2024-01-31', $statement->endDate?->format('Y-m-d'));
        $this->assertSame('2165.44', $statement->total());
    }

    public function test_itau_numa_linha_com_lixo_html_e_menor_que_no_memo(): void
    {
        $document = PzOfx::fromFile(self::fixture('itau_single_line.ofx'));
        $statement = $document->statement();

        $this->assertCount(2, $statement);
        [$debit, $credit] = $statement->transactions;

        $this->assertSame('PAG BOLETO <LOJA X> LTDA', $debit->description);
        $this->assertSame('-89.90', $debit->amount);
        $this->assertSame(PzOfxDirectionEnum::DEBIT, $debit->direction());

        $this->assertSame(PzOfxTransactionTypeEnum::OTHER, $credit->type, 'TRNTYPE vazio');
        $this->assertNull($credit->rawType);
        $this->assertNull($credit->memo);
        $this->assertSame('PIX RECEBIDO MARIA', $credit->description, 'MEMO vazio cai no NAME');
        $this->assertSame('1500.00', $credit->amount);

        $this->assertNull($statement->account->branchId);
        $this->assertSame('1234/56789-0', $statement->account->accountId);
        $this->assertSame('2024-02-03', $statement->firstDate()?->format('Y-m-d'), 'ordem decrescente no arquivo');
        $this->assertSame('2024-02-14', $statement->lastDate()?->format('Y-m-d'));
        $this->assertSame([1, 0], array_map(static fn (PzOfxTransaction $t): int => $t->index, $statement->sortedByDate()));
        $this->assertSame('1410.10', $statement->total());
        $this->assertFalse($document->hasWarnings());
    }

    public function test_nubank_ofx2_xml_com_bom_emoji_e_data_perto_da_meia_noite(): void
    {
        $document = PzOfx::fromFile(self::fixture('nubank_v2_bom.ofx'));
        $statement = $document->statement();

        $this->assertTrue($document->header->isXml);
        $this->assertSame('211', $document->header->version());
        $this->assertSame('Nu Pagamentos S.A.', $document->signOn?->organization);
        $this->assertSame('260', $document->signOn?->fid);
        $this->assertSame('2024-03-01 09:30:00', $document->signOn?->serverDate?->format('Y-m-d H:i:s'));
        $this->assertSame('Nubank', $statement->account->bankName());

        [$pix, $income] = $statement->transactions;
        $this->assertSame('2024-02-29 23:30:00', $pix->postedAt->format('Y-m-d H:i:s'), 'sem virar 01/03');
        $this->assertSame('Transferência enviada pelo Pix - João Ñandú & Cia', $pix->description);
        $this->assertSame('Rendimento líquido 💰 <tag>', $income->description, 'CDATA literal');
        $this->assertSame('75.00', $statement->ledgerBalance?->amount);
        $this->assertSame('75.00', $statement->availableBalance?->amount);
        $this->assertSame('0', (string) $statement->status?->code);
        $this->assertSame('1', $statement->transactionUid);
    }

    public function test_cartao_de_credito(): void
    {
        $document = PzOfx::fromFile(self::fixture('credit_card.ofx'));
        $statement = $document->statement();

        $this->assertSame(PzOfxStatementTypeEnum::CREDIT_CARD, $statement->type);
        $this->assertTrue($statement->isCreditCard());
        $this->assertSame([], $document->bankStatements());
        $this->assertSame(PzOfxAccountTypeEnum::CREDITCARD, $statement->account->type);
        $this->assertNull($statement->account->bankId);
        $this->assertSame('5502XXXXXXXX1234', $statement->account->accountId);
        $this->assertSame(['149.90', '-800.00'], self::amounts($statement->transactions), 'sinal do arquivo por padrão');
        $this->assertSame('Parcela 1/3', $statement->transactions[0]->description);
        $this->assertSame('PAGAMENTO RECEBIDO', $statement->transactions[1]->description);
        $this->assertSame('-1520.33', $statement->ledgerBalance?->amount);
    }

    public function test_cartao_com_perfil_que_corrige_o_sinal(): void
    {
        $byType = PzOfx::fromFile(self::fixture('credit_card.ofx'), [
            'profiles' => [['match' => ['statement' => 'credit_card'], 'amount_sign' => 'by_type', 'description' => 'name_and_memo']],
        ])->statement();
        $inverted = PzOfx::fromFile(self::fixture('credit_card.ofx'), [
            'profiles' => [['match' => ['statement' => 'credit_card'], 'amount_sign' => 'invert']],
        ])->statement();

        $this->assertSame(['-149.90', '800.00'], self::amounts($byType->transactions));
        $this->assertSame(['-149.90', '800.00'], self::amounts($inverted->transactions));
        $this->assertSame('SUPERMERCADO EXEMPLO - Parcela 1/3', $byType->transactions[0]->description);
    }

    public function test_varias_contas_e_cartao_no_mesmo_arquivo(): void
    {
        $document = PzOfx::fromFile(self::fixture('multi_account.ofx'));

        $this->assertCount(3, $document->statements);
        $this->assertCount(2, $document->bankStatements());
        $this->assertCount(1, $document->creditCardStatements());
        $this->assertCount(3, $document->transactions());

        [$checking, $savings, $card] = $document->statements;
        $this->assertSame('756', $checking->account->bankCode());
        $this->assertSame('756', $savings->account->bankCode(), '0756 normalizado');
        $this->assertSame(PzOfxAccountTypeEnum::SAVINGS, $savings->account->type);
        $this->assertNull($savings->ledgerBalance);
        $this->assertSame('RENDIMENTO POUPANÇA', $savings->transactions[0]->description);
        $this->assertSame(PzOfxTransactionTypeEnum::INT, $savings->transactions[0]->type);
        $this->assertSame('STREAMING', $card->transactions[0]->description);

        // Mesmo FITID em contas diferentes não é duplicidade.
        $this->assertSame('X1', $checking->transactions[0]->uniqueId);
        $this->assertSame('X1', $savings->transactions[0]->uniqueId);
        $this->assertSame([], $document->warningsOf(PzOfxWarningEnum::DUPLICATE_FIT_ID));
        $this->assertSame('COOP EXEMPLO', $document->signOn?->organization);
    }

    public function test_sicoob_sem_dtposted_sem_cabecalho_e_dois_blocos_ofx(): void
    {
        $document = PzOfx::fromFile(self::fixture('sicoob_no_dtposted.ofx'));

        $this->assertCount(2, $document->statements);
        [$fromFitId, $fromUserDate] = $document->statements[0]->transactions;
        $this->assertSame('2024-01-15', $fromFitId->date(), 'prefixo AAAAMMDD do FITID');
        $this->assertSame('200.00', $fromFitId->amount);
        $this->assertSame('2024-01-14', $fromUserDate->date(), 'DTUSER');
        $this->assertSame('-20.50', $document->statements[1]->transactions[0]->amount);

        $fallbacks = $document->warningsOf(PzOfxWarningEnum::DATE_FALLBACK);
        $this->assertCount(2, $fallbacks);
        $this->assertSame('FITID', $fallbacks[0]->context['field']);
        $this->assertSame('DTUSER', $fallbacks[1]->context['field']);
        $this->assertSame([], $document->header->all());
    }

    public function test_banco_do_brasil_linhas_de_saldo_e_fitid_reaproveitado(): void
    {
        $document = PzOfx::fromFile(self::fixture('bb_balance_rows.ofx'));
        $statement = $document->statement();

        $this->assertSame('001', $statement->account->bankCode());
        $this->assertSame('Banco do Brasil', $statement->account->bankName());
        $this->assertSame(['-150.00', '-150.00', '1000.00', '-12.34'], self::amounts($statement->transactions));
        $this->assertSame([1, 2, 3, 5], array_map(static fn (PzOfxTransaction $t): int => $t->index, $statement->transactions), 'índice = posição no arquivo');
        $this->assertSame('Transferência recebida', $statement->transactions[2]->description, 'ISO-8859-1');
        $this->assertSame('Saldo Aplic Aut Resgate', $statement->transactions[3]->description, 'transação de verdade que começa com "Saldo" fica');

        $skipped = $document->warningsOf(PzOfxWarningEnum::BALANCE_ROW_SKIPPED);
        $this->assertSame(['Saldo Anterior', 'S A L D O'], array_map(static fn ($w) => $w->context['value'], $skipped));

        // FITID repetido: cada uma cai no próprio fingerprint.
        $ids = array_map(static fn (PzOfxTransaction $t): string => $t->uniqueId, $statement->transactions);
        $this->assertCount(4, array_unique($ids));
        $this->assertSame('20240305', $ids[3]);
        $this->assertSame($statement->ledgerBalance?->amount, $statement->total());
    }

    /** Linhas de saldo vêm com FITID e valor; "SALDO PAGAMENTO…" é transação. */
    public function test_itau_linhas_de_saldo_com_fitid_e_hora_fixa(): void
    {
        $document = PzOfx::fromFile(self::fixture('itau_balance_rows.ofx'));
        $statement = $document->statement();

        $this->assertSame(
            ['PIX TRANSF FORNEC23/07', 'TED 341.1234.CLIENTE SA', 'SALDO PAGAMENTO COLABORADORES REF. 07/2025', 'TAR CESTA MÊS 07'],
            array_map(static fn (PzOfxTransaction $t): string => $t->description, $statement->transactions),
        );
        $this->assertSame(
            ['SALDO ANTERIOR', 'SALDO TOTAL DISPONÍVEL DIA', 'SALDO BLOQUEADO', 'SALDO TOTAL'],
            array_map(static fn ($w) => $w->context['value'], $document->warningsOf(PzOfxWarningEnum::BALANCE_ROW_SKIPPED)),
        );
        $this->assertSame('2025-07-23 10:00:00', $statement->transactions[0]->postedAt->format('Y-m-d H:i:s'));
        $this->assertSame('6437.10', $statement->total());
    }

    public function test_banco_do_brasil_sem_pular_linhas_de_saldo(): void
    {
        $statement = PzOfx::fromFile(self::fixture('bb_balance_rows.ofx'), ['defaults' => ['skip_balance_rows' => false]])->statement();

        $this->assertCount(6, $statement);
    }

    public function test_todas_as_fixtures_leem_sem_excecao_e_com_ids_unicos_por_extrato(): void
    {
        foreach (glob(self::fixture('*.ofx')) ?: [] as $file) {
            foreach (PzOfx::fromFile($file)->statements as $statement) {
                $ids = array_map(static fn (PzOfxTransaction $t): string => $t->uniqueId, $statement->transactions);
                $this->assertSame(count($ids), count(array_unique($ids)), basename($file));
                $this->assertNotEmpty($ids, basename($file));
            }
        }
    }
}
