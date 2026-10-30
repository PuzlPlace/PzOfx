<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Tests\Features\Testing;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use PHPUnit\Framework\AssertionFailedError;
use Puzl\PzOfx\Compat\PzOfxLegacy;
use Puzl\PzOfx\Contracts\PzOfxParserInterface;
use Puzl\PzOfx\Data\PzOfxDocument;
use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use Puzl\PzOfx\Exceptions\PzOfxFileException;
use Puzl\PzOfx\Exceptions\PzOfxParseException;
use Puzl\PzOfx\Laravel\PzOfxServiceProvider;
use Puzl\PzOfx\Laravel\Rules\PzOfxFileRule;
use Puzl\PzOfx\PzOfx;
use Puzl\PzOfx\PzOfxParser;
use Puzl\PzOfx\Testing\PzOfxDocumentBuilder;
use Puzl\PzOfx\Testing\PzOfxFake;
use Puzl\PzOfx\Tests\Features\Laravel\Fixtures\AppStub;
use Puzl\PzOfx\Tests\Features\Testing\Fixtures\ImportService;
use Puzl\PzOfx\Tests\TestCase;
use RuntimeException;

/** Cada forma de testar código que usa o PzOfx. */
final class TestabilityTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function app(): AppStub
    {
        $app = new AppStub();
        Container::setInstance($app);
        $app->instance('config', new ConfigRepository(['app' => ['timezone' => 'America/Sao_Paulo', 'locale' => 'en']]));
        (new PzOfxServiceProvider($app))->register();

        return $app;
    }

    private static function document(string ...$amounts): PzOfxDocument
    {
        $builder = PzOfxDocumentBuilder::bank();
        foreach ($amounts as $amount) {
            $builder->transaction($amount);
        }

        return $builder->build();
    }

    // ---------------------------------------------------------------- lib real

    public function test_lib_real_pelo_container_nos_tres_estilos(): void
    {
        $service = $this->app()->make(ImportService::class);
        $ofx = PzOfxDocumentBuilder::bank()->transaction('-10.00')->transaction('25,50')->toOfx();

        $this->assertSame('15.50', $service->total($ofx));
        $this->assertSame('15.50', $service->totalWithConcrete($ofx));
        $this->assertSame('15.50', $service->totalWithStatic($ofx));
    }

    public function test_interface_e_concreta_resolvem_o_mesmo_singleton(): void
    {
        $app = $this->app();

        $this->assertSame($app->make(PzOfxParser::class), $app->make(PzOfxParserInterface::class));
        $this->assertSame($app->make(PzOfxParserInterface::class), PzOfx::parser());
    }

    // --------------------------------------------------------------- mocks

    public function test_mock_da_interface_vale_para_injecao_e_para_o_estatico(): void
    {
        $app = $this->app();
        $mock = $this->createMock(PzOfxParserInterface::class);
        $mock->expects($this->exactly(2))->method('parseString')->with('conteudo')->willReturn(self::document('-7.00'));
        $app->instance(PzOfxParserInterface::class, $mock);

        $service = $app->make(ImportService::class);

        $this->assertSame('-7.00', $service->total('conteudo'));
        $this->assertSame('-7.00', $service->totalWithStatic('conteudo'));
    }

    public function test_mock_da_classe_concreta(): void
    {
        $app = $this->app();
        $mock = $this->createMock(PzOfxParser::class);
        $mock->method('parseString')->willReturn(self::document('3.00'));
        $app->instance(PzOfxParser::class, $mock);

        $service = $app->make(ImportService::class);

        $this->assertSame('3.00', $service->totalWithConcrete('x'));
        $this->assertSame('3.00', $service->total('x'), 'a interface segue a concreta');
    }

    public function test_mock_sem_container_injetado_a_mao(): void
    {
        $mock = $this->createMock(PzOfxParserInterface::class);
        $mock->method('parseString')->willThrowException(PzOfxParseException::because(PzOfxErrorEnum::NOT_OFX));

        $this->expectException(PzOfxParseException::class);

        (new ImportService($mock, new PzOfxParser()))->total('x');
    }

    // ---------------------------------------------------------------- fake

    public function test_fake_devolve_documentos_em_ordem_e_repete_o_ultimo(): void
    {
        $fake = PzOfx::fake([self::document('1.00'), self::document('2.00')]);

        $this->assertSame('1.00', PzOfx::fromString('a')->statement()->total());
        $this->assertSame('2.00', PzOfx::fromFile('/qualquer.ofx')->statement()->total());
        $this->assertSame('2.00', PzOfx::parse('c')->statement()->total());

        PzOfx::assertParsedTimes(3);
        PzOfx::assertParsed(static fn (mixed $input, string $method): bool => $input === '/qualquer.ofx' && $method === 'parseFile');
        $this->assertCount(3, $fake->calls());
    }

    public function test_fake_lanca_erros_com_a_excecao_certa(): void
    {
        PzOfx::fake(PzOfxErrorEnum::FILE_TOO_LARGE)->throw(PzOfxErrorEnum::NOT_OFX)->throw(new RuntimeException('disco'));

        foreach ([PzOfxFileException::class, PzOfxParseException::class, RuntimeException::class] as $expected) {
            try {
                PzOfx::fromString('x');
                $this->fail('Deveria lançar');
            } catch (\Throwable $e) {
                $this->assertInstanceOf($expected, $e);
            }
        }
    }

    public function test_fake_sem_respostas_le_de_verdade_e_registra(): void
    {
        PzOfx::fake();

        $document = PzOfx::fromFile(self::fixture('legacy_classic.ofx'), ['strict' => false]);

        $this->assertCount(4, $document->statement());
        PzOfx::assertParsed(static fn (mixed $input, string $method, mixed $config): bool => $config === ['strict' => false]);
    }

    public function test_fake_entra_no_container_para_quem_injeta(): void
    {
        $app = $this->app();
        PzOfx::fake([self::document('9.99')]);

        $service = $app->make(ImportService::class);

        $this->assertInstanceOf(PzOfxFake::class, $service->parser);
        $this->assertSame('9.99', $service->total('x'));
        $this->assertSame('9.99', $service->totalWithConcrete('x'));
        PzOfx::assertParsedTimes(2);
    }

    public function test_fake_cobre_o_compat_e_a_regra_de_validacao(): void
    {
        PzOfx::fake([self::document('-35.70')]);

        $legacy = new PzOfxLegacy('qualquer coisa');
        $this->assertSame('-35.70', $legacy->bankTransactions[0]['value']);

        $errors = [];
        (new PzOfxFileRule())->validate('file', 'x', static function (string $m) use (&$errors): void {
            $errors[] = $m;
        });
        $this->assertSame([], $errors);
        PzOfx::assertParsedTimes(2);
    }

    public function test_asserts_do_fake_falham_de_verdade(): void
    {
        PzOfx::fake();
        PzOfx::assertNothingParsed();

        $this->expectException(AssertionFailedError::class);
        PzOfx::assertParsed();
    }

    public function test_assert_sem_fake_avisa(): void
    {
        $this->expectException(\LogicException::class);

        PzOfx::assertParsed();
    }

    public function test_reset_desliga_o_fake(): void
    {
        PzOfx::fake([self::document('1.00')]);
        PzOfx::reset();

        $this->assertNotInstanceOf(PzOfxFake::class, PzOfx::parser());
    }

    // ------------------------------------------------------------- builder

    public function test_builder_gera_documento_igual_ao_de_producao(): void
    {
        $document = PzOfxDocumentBuilder::bank('0237', '98765-4', '1234')
            ->institution('Bradesco', '237')
            ->transaction('-1.234,56', '2024-01-15 10:00:00', 'ÁGUA & ESGOTO <CONTA>', checkNumber: '123')
            ->transaction(1500.5, new \DateTimeImmutable('2024-01-16'), name: 'PIX RECEBIDO', fitId: 'ABC')
            ->transaction('-10.00', '2024-01-17', 'SEM FITID', fitId: '')
            ->ledgerBalance('255.94')
            ->build();

        $statement = $document->statement();
        $this->assertSame('237', $statement->account->bankCode());
        $this->assertSame('1234', $statement->account->branchId);
        $this->assertSame('Bradesco', $document->signOn?->organization);
        $this->assertSame(['-1234.56', '1500.50', '-10.00'], array_map(static fn ($t) => $t->amount, $statement->transactions));
        $this->assertSame('ÁGUA & ESGOTO <CONTA>', $statement->transactions[0]->description);
        $this->assertSame('2024-01-15 10:00:00', $statement->transactions[0]->postedAt->format('Y-m-d H:i:s'));
        $this->assertSame('123', $statement->transactions[0]->checkNumber);
        $this->assertSame('DEBIT', $statement->transactions[0]->type->value);
        $this->assertSame('PIX RECEBIDO', $statement->transactions[1]->description);
        $this->assertSame('ABC', $statement->transactions[1]->uniqueId);
        $this->assertNull($statement->transactions[2]->fitId);
        $this->assertSame($statement->transactions[2]->fingerprint, $statement->transactions[2]->uniqueId);
        $this->assertSame('255.94', $statement->ledgerBalance?->amount);
        $this->assertSame($statement->total(), $statement->ledgerBalance?->amount);
    }

    public function test_builder_varios_extratos_cartao_e_perfis(): void
    {
        $document = PzOfxDocumentBuilder::bank('341', '111-1')
            ->transaction('-1.00')
            ->addBankStatement('104', '222-2')
            ->transaction('-2.00')
            ->addCreditCardStatement('4111XXXX9999')
            ->transaction('59.90', type: 'DEBIT')
            ->institution('Banco', '336')
            ->build(['profiles' => [['match' => ['bank_id' => '336', 'statement' => 'credit_card'], 'amount_sign' => 'invert']]]);

        $this->assertCount(2, $document->bankStatements());
        $this->assertCount(1, $document->creditCardStatements());
        $this->assertSame('-59.90', $document->creditCardStatements()[0]->transactions[0]->amount);
        $this->assertTrue($document->statements[1]->profile->trustFitId, '"profiles" informado substitui os de fábrica');

        $default = PzOfxDocumentBuilder::bank('104', '222-2')->transaction('-2.00')->build();
        $this->assertFalse($default->statement()->profile->trustFitId, 'perfil de fábrica da Caixa');
    }

    public function test_builder_respeita_a_config_global(): void
    {
        PzOfx::configure(['defaults' => ['description' => 'name']]);

        $transaction = PzOfxDocumentBuilder::bank()->transaction('1.00', memo: 'M', name: 'N')->build()->transactions()[0];

        $this->assertSame('N', $transaction->description);
    }

    public function test_builder_sem_extrato_avisa(): void
    {
        $empty = (new \ReflectionClass(PzOfxDocumentBuilder::class))->newInstanceWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);

        $empty->transaction('1.00');
    }
}
