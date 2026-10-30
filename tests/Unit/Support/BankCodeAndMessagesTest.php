<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use Puzl\PzOfx\Enums\PzOfxTransactionTypeEnum;
use Puzl\PzOfx\Enums\PzOfxWarningEnum;
use Puzl\PzOfx\Exceptions\PzOfxConfigException;
use Puzl\PzOfx\Exceptions\PzOfxException;
use Puzl\PzOfx\Exceptions\PzOfxFileException;
use Puzl\PzOfx\Exceptions\PzOfxParseException;
use Puzl\PzOfx\PzOfx;
use Puzl\PzOfx\Support\BankCode;
use Puzl\PzOfx\Support\Messages;
use Puzl\PzOfx\Tests\TestCase;
use RuntimeException;

final class BankCodeAndMessagesTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function bankIds(): array
    {
        return [
            '3 dígitos' => ['341', '341'],
            'zero à esquerda' => ['0341', '341'],
            'um dígito' => ['1', '001'],
            'muitos zeros' => ['00001', '001'],
            'com espaço' => [' 237 ', '237'],
            'ISPB' => ['18236120', null],
            'nome' => ['ITAU', null],
            'só zeros' => ['0000', null],
            'vazio' => ['', null],
            'nulo' => [null, null],
        ];
    }

    #[DataProvider('bankIds')]
    public function test_codigo_compe_normalizado(?string $bankId, ?string $expected): void
    {
        $this->assertSame($expected, BankCode::normalize($bankId));
    }

    public function test_nome_do_banco(): void
    {
        $this->assertSame('Itaú', BankCode::name('341'));
        $this->assertSame('Banco do Brasil', BankCode::name('001'));
        $this->assertNull(BankCode::name('999'));
        $this->assertNull(BankCode::name(null));
    }

    public function test_todas_as_mensagens_existem_nos_dois_idiomas(): void
    {
        $this->assertSame(array_keys(Messages::LOCALES['en']), array_keys(Messages::LOCALES['pt_BR']));

        foreach (PzOfxErrorEnum::cases() as $case) {
            $this->assertArrayHasKey('error.'.$case->value, Messages::LOCALES['en']);
        }
        foreach (PzOfxWarningEnum::cases() as $case) {
            $this->assertArrayHasKey('warning.'.$case->value, Messages::LOCALES['en']);
        }
    }

    public function test_idioma_fixo_closure_e_normalizacao(): void
    {
        $this->assertSame('The OFX file is empty.', PzOfxErrorEnum::FILE_EMPTY->message());

        PzOfx::useLocale('pt-BR');
        $this->assertSame('O arquivo OFX está vazio.', PzOfxErrorEnum::FILE_EMPTY->message());

        PzOfx::useLocale(static fn (): string => 'pt');
        $this->assertSame('O arquivo OFX está vazio.', PzOfxErrorEnum::FILE_EMPTY->message());

        PzOfx::useLocale('fr');
        $this->assertSame('The OFX file is empty.', PzOfxErrorEnum::FILE_EMPTY->message());

        $this->assertSame('O arquivo OFX está vazio.', PzOfxErrorEnum::FILE_EMPTY->message(locale: 'pt_BR'));
    }

    public function test_placeholders(): void
    {
        $message = PzOfxErrorEnum::INVALID_AMOUNT->message(['index' => 3, 'field' => 'TRNAMT', 'value' => 'abc'], 'pt_BR');

        $this->assertSame('A transação 3 tem TRNAMT inválido: "abc".', $message);
    }

    public function test_hierarquia_e_dados_das_excecoes(): void
    {
        $e = PzOfxParseException::because(PzOfxErrorEnum::INVALID_DATE, ['index' => 1, 'field' => 'DTPOSTED', 'value' => 'x']);

        $this->assertInstanceOf(PzOfxException::class, $e);
        $this->assertInstanceOf(RuntimeException::class, $e);
        $this->assertSame(PzOfxErrorEnum::INVALID_DATE, $e->error());
        $this->assertSame(['index' => 1, 'field' => 'DTPOSTED', 'value' => 'x'], $e->context());
        $this->assertInstanceOf(PzOfxException::class, PzOfxFileException::because(PzOfxErrorEnum::FILE_EMPTY));
        $this->assertInstanceOf(PzOfxException::class, PzOfxConfigException::because(PzOfxErrorEnum::INVALID_CONFIG));
    }

    public function test_tipo_de_transacao_tolerante(): void
    {
        $this->assertSame(PzOfxTransactionTypeEnum::DEBIT, PzOfxTransactionTypeEnum::fromOfx(' debit '));
        $this->assertSame(PzOfxTransactionTypeEnum::OTHER, PzOfxTransactionTypeEnum::fromOfx(''));
        $this->assertSame(PzOfxTransactionTypeEnum::OTHER, PzOfxTransactionTypeEnum::fromOfx('PIX'));
        $this->assertNull(PzOfxTransactionTypeEnum::tryFromOfx('PIX'));
        $this->assertNull(PzOfxTransactionTypeEnum::tryFromOfx(null));
    }
}
