<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use Puzl\PzOfx\Config\PzOfxConfig;
use Puzl\PzOfx\Enums\PzOfxAmountSignEnum;
use Puzl\PzOfx\Enums\PzOfxDescriptionEnum;
use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use Puzl\PzOfx\Enums\PzOfxStatementTypeEnum;
use Puzl\PzOfx\Enums\PzOfxTimezoneModeEnum;
use Puzl\PzOfx\Exceptions\PzOfxConfigException;
use Puzl\PzOfx\Tests\TestCase;

final class PzOfxConfigTest extends TestCase
{
    public function test_padroes(): void
    {
        $config = PzOfxConfig::default();

        $this->assertTrue($config->strict);
        $this->assertNull($config->timezone);
        $this->assertSame(PzOfxTimezoneModeEnum::LOCAL, $config->timezoneMode);
        $this->assertSame(10 * 1024 * 1024, $config->maxFileSize);
        $this->assertNull($config->encoding);
        $this->assertSame(PzOfxDescriptionEnum::MEMO_OR_NAME, $config->defaults->description);
        $this->assertSame(PzOfxAmountSignEnum::AS_IS, $config->defaults->amountSign);
        $this->assertTrue($config->defaults->trustFitId);
        $this->assertTrue($config->defaults->skipBalanceRows);
        $this->assertCount(2, $config->profiles, 'perfis de fábrica (Caixa e Unicred)');
        $this->assertFalse($config->profileFor('0104', null, null, PzOfxStatementTypeEnum::BANK)->trustFitId);
        $this->assertFalse($config->profileFor('136', null, null, PzOfxStatementTypeEnum::BANK)->trustFitId);
        $this->assertSame([], PzOfxConfig::fromArray(['profiles' => []])->profiles, 'lista vazia desliga');
        $this->assertSame('America/Sao_Paulo', $config->dateTimeZone()->getName());
    }

    public function test_arquivo_de_config_do_pacote_equivale_aos_padroes(): void
    {
        $file = require dirname(__DIR__, 3).'/config/pzofx.php';
        unset($file['locale']);

        $this->assertEquals(PzOfxConfig::default()->toArray(), PzOfxConfig::fromArray($file)->toArray());
    }

    public function test_defaults_parcial_herda_o_resto(): void
    {
        $config = PzOfxConfig::fromArray(['defaults' => ['description' => 'name_and_memo']]);

        $this->assertSame(PzOfxDescriptionEnum::NAME_AND_MEMO, $config->defaults->description);
        $this->assertSame(PzOfxAmountSignEnum::AS_IS, $config->defaults->amountSign);
        $this->assertTrue($config->defaults->trustFitId);
    }

    public function test_with_aplica_ajustes_sem_perder_o_resto(): void
    {
        $base = PzOfxConfig::fromArray(['timezone' => 'UTC', 'profiles' => [['match' => ['bank_id' => '1'], 'trust_fit_id' => false]]]);

        $changed = $base->with(['strict' => false, 'defaults' => ['amount_sign' => 'invert']]);

        $this->assertFalse($changed->strict);
        $this->assertSame('UTC', $changed->timezone);
        $this->assertSame(PzOfxAmountSignEnum::INVERT, $changed->defaults->amountSign);
        $this->assertSame(PzOfxDescriptionEnum::MEMO_OR_NAME, $changed->defaults->description);
        $this->assertCount(1, $changed->profiles);
        $this->assertTrue($base->strict, 'original intacto');
    }

    public function test_perfil_por_banco_compara_codigo_normalizado(): void
    {
        $config = PzOfxConfig::fromArray(['profiles' => [
            ['match' => ['bank_id' => '237'], 'description' => 'name'],
            ['match' => ['bank_id' => ['260', '336'], 'statement' => 'credit_card'], 'amount_sign' => 'invert'],
            ['match' => ['org' => 'Banco Exemplo S.A.'], 'trust_fit_id' => false],
            ['match' => ['fid' => '999'], 'skip_balance_rows' => false],
        ]]);
        $bank = PzOfxStatementTypeEnum::BANK;
        $card = PzOfxStatementTypeEnum::CREDIT_CARD;

        $this->assertSame(PzOfxDescriptionEnum::NAME, $config->profileFor('0237', null, null, $bank)->description);
        $this->assertSame(PzOfxAmountSignEnum::AS_IS, $config->profileFor('0237', null, null, $bank)->amountSign);
        $this->assertSame(PzOfxAmountSignEnum::INVERT, $config->profileFor('336', null, null, $card)->amountSign);
        $this->assertSame(PzOfxAmountSignEnum::AS_IS, $config->profileFor('336', null, null, $bank)->amountSign, 'statement precisa bater');
        $this->assertFalse($config->profileFor(null, 'banco exemplo s.a.', null, $bank)->trustFitId);
        $this->assertFalse($config->profileFor(null, null, '999', $bank)->skipBalanceRows);
        $this->assertSame($config->defaults, $config->profileFor('341', 'Outro', '1', $bank));
    }

    public function test_primeiro_perfil_que_bate_vence(): void
    {
        $config = PzOfxConfig::fromArray(['profiles' => [
            ['match' => ['bank_id' => '1'], 'description' => 'name'],
            ['match' => ['bank_id' => '001'], 'description' => 'memo'],
        ]]);

        $this->assertSame(PzOfxDescriptionEnum::NAME, $config->profileFor('001', null, null, PzOfxStatementTypeEnum::BANK)->description);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidConfigs(): array
    {
        return [
            'fuso inexistente' => [['timezone' => 'Marte/Olympus']],
            'modo de fuso' => [['timezone_mode' => 'utc']],
            'tamanho negativo' => [['max_file_size' => -1]],
            'tamanho texto' => [['max_file_size' => '10MB']],
            'regex inválida' => [['balance_row_pattern' => '/(/']],
            'defaults não array' => [['defaults' => 'memo']],
            'descrição desconhecida' => [['defaults' => ['description' => 'tudo']]],
            'opção desconhecida' => [['defaults' => ['descripton' => 'memo']]],
            'bool inválido' => [['defaults' => ['trust_fit_id' => 'sim']]],
            'perfil sem match' => [['profiles' => [['description' => 'memo']]]],
            'match vazio' => [['profiles' => [['match' => [], 'description' => 'memo']]]],
            'critério desconhecido' => [['profiles' => [['match' => ['banco' => '1']]]]],
            'statement inválido' => [['profiles' => [['match' => ['statement' => 'poupanca']]]]],
            'perfil não array' => [['profiles' => ['237']]],
            'profiles não lista' => [['profiles' => 'x']],
            'perfil de fábrica com tipo errado' => [['profiles' => [['match' => ['bank_id' => '104'], 'trust_fit_id' => 'no']]]],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('invalidConfigs')]
    public function test_config_invalida_falha_cedo_com_motivo(array $data): void
    {
        try {
            PzOfxConfig::fromArray($data);
            $this->fail('Deveria lançar exceção');
        } catch (PzOfxConfigException $e) {
            $this->assertSame(PzOfxErrorEnum::INVALID_CONFIG, $e->error());
            $this->assertNotEmpty($e->context()['reason']);
        }
    }

    public function test_aceita_enums_alem_de_strings(): void
    {
        $config = PzOfxConfig::fromArray([
            'timezone_mode' => PzOfxTimezoneModeEnum::CONVERT,
            'defaults' => ['description' => PzOfxDescriptionEnum::NAME, 'amount_sign' => PzOfxAmountSignEnum::BY_TYPE],
            'profiles' => [['match' => ['statement' => PzOfxStatementTypeEnum::CREDIT_CARD], 'amount_sign' => 'INVERT']],
        ]);

        $this->assertSame(PzOfxTimezoneModeEnum::CONVERT, $config->timezoneMode);
        $this->assertSame(PzOfxDescriptionEnum::NAME, $config->defaults->description);
        $this->assertSame(PzOfxAmountSignEnum::INVERT, $config->profileFor(null, null, null, PzOfxStatementTypeEnum::CREDIT_CARD)->amountSign);
    }
}
