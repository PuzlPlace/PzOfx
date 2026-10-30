<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Tests\Features;

use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use Puzl\PzOfx\Enums\PzOfxWarningEnum;
use Puzl\PzOfx\Exceptions\PzOfxException;
use Puzl\PzOfx\Exceptions\PzOfxFileException;
use Puzl\PzOfx\Exceptions\PzOfxParseException;
use Puzl\PzOfx\PzOfx;
use Puzl\PzOfx\PzOfxParser;
use Puzl\PzOfx\Tests\TestCase;
use SplFileInfo;

final class ParsingBehaviorTest extends TestCase
{
    private function assertError(PzOfxErrorEnum $expected, callable $callback): PzOfxException
    {
        try {
            $callback();
        } catch (PzOfxException $e) {
            $this->assertSame($expected, $e->error(), $e->getMessage());

            return $e;
        }
        $this->fail('Esperava '.$expected->value);
    }

    public function test_todas_as_formas_de_entrada(): void
    {
        $path = self::fixture('legacy_classic.ofx');
        $stream = fopen($path, 'rb');

        $results = [
            PzOfx::fromFile($path),
            PzOfx::fromFile(new SplFileInfo($path)),
            PzOfx::fromString((string) file_get_contents($path)),
            PzOfx::fromStream($stream),
            PzOfx::parse($path),
            PzOfx::parse(new SplFileInfo($path)),
            PzOfx::parse((string) file_get_contents($path)),
            (new PzOfxParser())->parseFile($path),
        ];
        fclose($stream);

        foreach ($results as $document) {
            $this->assertEquals($results[0]->toArray(), $document->toArray());
        }
        $this->assertCount(4, $results[0]->statement());
    }

    public function test_erros_de_arquivo(): void
    {
        $this->assertError(PzOfxErrorEnum::FILE_NOT_INFORMED, static fn () => PzOfx::fromFile(null));
        $this->assertError(PzOfxErrorEnum::FILE_NOT_INFORMED, static fn () => PzOfx::fromFile(''));
        $this->assertError(PzOfxErrorEnum::FILE_NOT_INFORMED, static fn () => PzOfx::parse(42));
        $e = $this->assertError(PzOfxErrorEnum::FILE_NOT_FOUND, static fn () => PzOfx::fromFile('/nao/existe.ofx'));
        $this->assertInstanceOf(PzOfxFileException::class, $e);
        $this->assertSame('/nao/existe.ofx', $e->context()['path']);
        $this->assertError(PzOfxErrorEnum::FILE_EMPTY, static fn () => PzOfx::fromString("  \n "));
        $this->assertError(PzOfxErrorEnum::NOT_OFX, static fn () => PzOfx::fromString('<html>erro 500</html>'));
    }

    public function test_limite_de_tamanho_em_arquivo_string_e_stream(): void
    {
        $path = self::fixture('legacy_classic.ofx');
        $limit = ['max_file_size' => 100];

        $this->assertError(PzOfxErrorEnum::FILE_TOO_LARGE, static fn () => PzOfx::fromFile($path, $limit));
        $this->assertError(PzOfxErrorEnum::FILE_TOO_LARGE, static fn () => PzOfx::fromString((string) file_get_contents($path), $limit));
        $stream = fopen($path, 'rb');
        $this->assertError(PzOfxErrorEnum::FILE_TOO_LARGE, static fn () => PzOfx::fromStream($stream, $limit));
        fclose($stream);

        $this->assertCount(4, PzOfx::fromFile($path, ['max_file_size' => 0])->statement(), '0 desliga');
    }

    public function test_estrito_derruba_a_leitura_com_contexto(): void
    {
        $e = $this->assertError(PzOfxErrorEnum::INVALID_AMOUNT, static fn () => PzOfx::fromString(self::ofx(self::trn().self::trn('abc'))));
        $this->assertInstanceOf(PzOfxParseException::class, $e);
        $this->assertSame(['index' => 2, 'field' => 'TRNAMT', 'value' => 'abc'], $e->context());

        $this->assertError(PzOfxErrorEnum::INVALID_DATE, static fn () => PzOfx::fromString(self::ofx(self::trn(date: '20241399'))));
        $this->assertError(PzOfxErrorEnum::MISSING_FIELD, static fn () => PzOfx::fromString(self::ofx("<STMTTRN><DTPOSTED>20240101<FITID>1</STMTTRN>")));
        $this->assertError(PzOfxErrorEnum::MISSING_FIELD, static fn () => PzOfx::fromString(self::ofx("<STMTTRN><TRNAMT>1<FITID>ABC</STMTTRN>")));
    }

    public function test_nao_estrito_pula_a_transacao_ruim_com_aviso(): void
    {
        PzOfx::useLocale('pt_BR');
        $document = PzOfx::fromString(self::ofx(self::trn('-1,00', fitId: 'A').self::trn('abc', fitId: 'B').self::trn('2,00', '2024', 'C')), ['strict' => false]);

        $this->assertSame(['-1.00'], array_map(static fn ($t) => $t->amount, $document->transactions()));
        $skipped = $document->warningsOf(PzOfxWarningEnum::TRANSACTION_SKIPPED);
        $this->assertCount(2, $skipped);
        $this->assertSame('Transação 2 ignorada: A transação 2 tem TRNAMT inválido: "abc".', $skipped[0]->message());
    }

    public function test_campos_opcionais_invalidos_viram_aviso_nao_erro(): void
    {
        $content = str_replace('<BANKTRANLIST>', '<BANKTRANLIST><DTSTART>ontem<DTEND>20240131', self::ofx(self::trn(extra: "<DTUSER>99999999\n")));
        $content = str_replace('</STMTRS>', '<LEDGERBAL><BALAMT>muito<DTASOF>20240131</LEDGERBAL></STMTRS>', $content);

        $document = PzOfx::fromString($content);
        $statement = $document->statement();

        $this->assertNull($statement->startDate);
        $this->assertSame('2024-01-31', $statement->endDate?->format('Y-m-d'));
        $this->assertNull($statement->ledgerBalance);
        $this->assertNull($statement->transactions[0]->userDate);
        $this->assertCount(3, $document->warningsOf(PzOfxWarningEnum::INVALID_OPTIONAL_FIELD));
    }

    public function test_periodo_invertido_e_corrigido_com_aviso_ou_vira_erro(): void
    {
        $content = str_replace('<BANKTRANLIST>', '<BANKTRANLIST><DTSTART>20240131<DTEND>20240101', self::ofx(self::trn(date: '20240120').self::trn(date: '20240105', fitId: 'F2')));

        $document = PzOfx::fromString($content);
        $statement = $document->statement();

        $this->assertSame('2024-01-01', $statement->startDate?->format('Y-m-d'));
        $this->assertSame('2024-01-31', $statement->endDate?->format('Y-m-d'));
        $this->assertSame(['start' => '2024-01-31', 'end' => '2024-01-01'], $document->warningsOf(PzOfxWarningEnum::PERIOD_INVERTED)[0]->context);
        $this->assertSame(['2024-01-05', '2024-01-20'], array_map(static fn ($d) => $d?->format('Y-m-d'), $statement->period()));

        $e = $this->assertError(PzOfxErrorEnum::INVERTED_PERIOD, static fn () => PzOfx::fromString($content, ['fix_inverted_period' => false]));
        $this->assertSame('2024-01-31', $e->context()['start']);
    }

    public function test_period_sem_transacoes_usa_o_declarado(): void
    {
        $statement = PzOfx::fromString(str_replace('<BANKTRANLIST>', '<BANKTRANLIST><DTSTART>20240101<DTEND>20240131', self::ofx('')))->statement();

        $this->assertSame(['2024-01-01', '2024-01-31'], array_map(static fn ($d) => $d?->format('Y-m-d'), $statement->period()));
    }

    public function test_tipo_desconhecido_vira_other_com_aviso_e_valor_preservado(): void
    {
        $document = PzOfx::fromString(self::ofx('<STMTTRN><TRNTYPE>PIX<DTPOSTED>20240101<TRNAMT>1<FITID>1</STMTTRN>'));

        $transaction = $document->transactions()[0];
        $this->assertSame('OTHER', $transaction->type->value);
        $this->assertSame('PIX', $transaction->rawType);
        $this->assertCount(1, $document->warningsOf(PzOfxWarningEnum::UNKNOWN_TRANSACTION_TYPE));
    }

    public function test_estrategias_de_descricao(): void
    {
        $content = self::ofx(
            self::trn(fitId: '1', memo: 'Fulano de Tal', extra: "<NAME>PIX RECEBIDO\n")
            .self::trn(fitId: '2', memo: 'PIX RECEBIDO Fulano', extra: "<NAME>PIX RECEBIDO\n")
            .self::trn(fitId: '3', memo: '', extra: "<NAME>Só nome\n")
            .self::trn(fitId: '4', memo: 'Só memo')
            ."<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20240115<TRNAMT>1<FITID>5<PAYEE><NAME>Favorecido<CITY>SP</PAYEE></STMTTRN>"
        );
        $descriptions = static fn (string $strategy): array => array_map(
            static fn ($t) => $t->description,
            PzOfx::fromString($content, ['defaults' => ['description' => $strategy]])->transactions(),
        );

        $this->assertSame(['Fulano de Tal', 'PIX RECEBIDO Fulano', 'Só nome', 'Só memo', 'Favorecido'], $descriptions('memo_or_name'));
        $this->assertSame(['PIX RECEBIDO', 'PIX RECEBIDO', 'Só nome', 'Só memo', 'Favorecido'], $descriptions('name_or_memo'));
        $this->assertSame(['PIX RECEBIDO - Fulano de Tal', 'PIX RECEBIDO Fulano', 'Só nome', 'Só memo', 'Favorecido'], $descriptions('name_and_memo'));
        $this->assertSame(['Fulano de Tal', 'PIX RECEBIDO Fulano', '', 'Só memo', ''], $descriptions('memo'));
        $this->assertSame(['PIX RECEBIDO', 'PIX RECEBIDO', 'Só nome', '', 'Favorecido'], $descriptions('name'));
    }

    public function test_fingerprint_estavel_entre_leituras_e_sensivel_ao_conteudo(): void
    {
        $a = PzOfx::fromString(self::ofx(self::trn('-10,00', fitId: 'X', memo: 'Tarifa  Pacote')))->transactions()[0];
        $b = PzOfx::fromString(self::ofx(self::trn('-10.00', fitId: 'Y', memo: 'TARIFA PACOTE')))->transactions()[0];
        $c = PzOfx::fromString(self::ofx(self::trn('-10.01', fitId: 'X', memo: 'Tarifa Pacote')))->transactions()[0];
        $d = PzOfx::fromString(self::ofx(self::trn('-10.00', memo: 'Tarifa Pacote'), '<BANKID>341<ACCTID>99999-9'))->transactions()[0];

        $this->assertSame($a->fingerprint, $b->fingerprint, 'FITID instável e caixa não mudam o fingerprint');
        $this->assertNotSame($a->fingerprint, $c->fingerprint);
        $this->assertNotSame($a->fingerprint, $d->fingerprint, 'conta faz parte');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $a->fingerprint);
    }

    public function test_transacoes_identicas_no_mesmo_dia_continuam_distintas(): void
    {
        $document = PzOfx::fromString(self::ofx(self::trn(fitId: '').self::trn(fitId: '').self::trn(fitId: '')));

        $ids = array_map(static fn ($t) => $t->uniqueId, $document->transactions());
        $this->assertCount(3, array_unique($ids));
        $this->assertSame(3, $document->warningsOf(PzOfxWarningEnum::MISSING_FIT_ID)[0]->context['count']);
        $this->assertNull($document->transactions()[0]->fitId);
    }

    public function test_trust_fit_id_desligado_usa_sempre_fingerprint(): void
    {
        $transaction = PzOfx::fromString(self::ofx(self::trn(fitId: 'ABC')), ['defaults' => ['trust_fit_id' => false]])->transactions()[0];

        $this->assertSame('ABC', $transaction->fitId);
        $this->assertSame($transaction->fingerprint, $transaction->uniqueId);
    }

    public function test_fuso_configurado_e_modo_convert(): void
    {
        $content = self::ofx(self::trn(date: '20240229233000[-3:BRT]'));

        $local = PzOfx::fromString($content, ['timezone' => 'UTC'])->transactions()[0];
        $converted = PzOfx::fromString($content, ['timezone' => 'UTC', 'timezone_mode' => 'convert'])->transactions()[0];

        $this->assertSame('2024-02-29 23:30:00 UTC', $local->postedAt->format('Y-m-d H:i:s e'));
        $this->assertSame('2024-03-01 02:30:00 UTC', $converted->postedAt->format('Y-m-d H:i:s e'));
    }

    public function test_status_com_erro_do_banco_vira_aviso(): void
    {
        $content = str_replace('<TRNUID>1', '<TRNUID>1<STATUS><CODE>2000<SEVERITY>ERROR<MESSAGE>Falha parcial</STATUS>', self::ofx(self::trn()));

        $document = PzOfx::fromString($content);

        $this->assertSame(2000, $document->statement()->status?->code);
        $this->assertFalse($document->statement()->status?->isOk());
        $this->assertCount(1, $document->warningsOf(PzOfxWarningEnum::STATUS_NOT_OK));
    }

    public function test_extrato_sem_transacoes_e_documento_sem_extrato(): void
    {
        $empty = PzOfx::fromString(self::ofx(''));
        $this->assertTrue($empty->statement()->isEmpty());
        $this->assertNull($empty->statement()->firstDate());
        $this->assertSame('0.00', $empty->statement()->total());

        $none = PzOfx::fromString('<OFX><SIGNONMSGSRSV1><SONRS><STATUS><CODE>0</STATUS></SONRS></SIGNONMSGSRSV1></OFX>');
        $this->assertFalse($none->hasStatements());
        $this->assertSame([], $none->transactions());
        $this->assertError(PzOfxErrorEnum::NO_STATEMENT, static fn () => $none->statement());
    }

    public function test_campos_extras_e_proprietarios_ficam_em_raw(): void
    {
        $transaction = PzOfx::fromString(self::ofx(self::trn(extra: "<REFNUM>R9\n<SRVRTID>S1\n<BANCO.CANAL>APP\n<CURRENCY><CURRATE>5.0<CURSYM>USD</CURRENCY>\n")))->transactions()[0];

        $this->assertSame('APP', $transaction->rawField('banco.canal'));
        $this->assertSame('R9', $transaction->referenceNumber);
        $this->assertSame('S1', $transaction->serverTransactionId);
        $this->assertSame('USD', $transaction->currency);
        $this->assertSame(-1000, $transaction->amountCents());
        $this->assertSame(-10.0, $transaction->amountFloat());
        $this->assertSame('10.00', $transaction->absoluteAmount());
    }

    public function test_config_global_e_ajuste_por_chamada(): void
    {
        PzOfx::configure(['defaults' => ['description' => 'name']]);
        $content = self::ofx(self::trn(memo: 'M', extra: "<NAME>N\n"));

        $this->assertSame('N', PzOfx::fromString($content)->transactions()[0]->description);
        $this->assertSame('M', PzOfx::fromString($content, ['defaults' => ['description' => 'memo']])->transactions()[0]->description);
        $this->assertSame('N', PzOfx::fromString($content)->transactions()[0]->description, 'ajuste pontual não vaza');
    }

    public function test_to_array_serializa_tudo_em_json(): void
    {
        $json = json_encode(PzOfx::fromFile(self::fixture('nubank_v2_bom.ofx'))->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('"amount":"-25.00"', $json);
    }

    public function test_extrato_grande_le_rapido_e_com_pouca_memoria(): void
    {
        $transactions = '';
        for ($i = 0; $i < 20000; $i++) {
            $transactions .= self::trn(sprintf('-%d,%02d', $i, $i % 100), '20240101', 'F'.$i, 'COMPRA CARTAO LOJA '.$i)."\n";
        }
        $content = self::ofx($transactions);

        $start = microtime(true);
        $document = PzOfx::fromString($content);

        $this->assertCount(20000, $document->statement());
        $this->assertLessThan(10, microtime(true) - $start);
    }
}
