<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Tests\Features\Laravel;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\ServiceProvider;
use Puzl\PzOfx\Config\PzOfxConfig;
use Puzl\PzOfx\Enums\PzOfxDescriptionEnum;
use Puzl\PzOfx\Laravel\PzOfxServiceProvider;
use Puzl\PzOfx\Laravel\Rules\PzOfxFileRule;
use Puzl\PzOfx\PzOfx;
use Puzl\PzOfx\PzOfxParser;
use Puzl\PzOfx\Tests\Features\Laravel\Fixtures\AppStub;
use Puzl\PzOfx\Tests\TestCase;
use SplFileInfo;

final class PzOfxLaravelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ServiceProvider::$publishes = [];
        ServiceProvider::$publishGroups = [];
    }

    protected function tearDown(): void
    {
        ServiceProvider::$publishes = [];
        ServiceProvider::$publishGroups = [];
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config = [], bool $console = true): AppStub
    {
        $app = new AppStub($console);
        Container::setInstance($app);
        $app->instance('config', new ConfigRepository(['app' => ['timezone' => 'UTC', 'locale' => 'pt_BR']] + $config));

        $provider = new PzOfxServiceProvider($app);
        $provider->register();
        $provider->boot();

        return $app;
    }

    public function test_mescla_padroes_e_respeita_o_que_a_aplicacao_definiu(): void
    {
        $app = $this->boot(['pzofx' => ['strict' => false]]);

        $this->assertFalse($app->make('config')->get('pzofx.strict'));
        $this->assertSame('local', $app->make('config')->get('pzofx.timezone_mode'));
        $this->assertFalse($app->make(PzOfxConfig::class)->strict);
    }

    public function test_singletons_e_fuso_da_aplicacao(): void
    {
        $app = $this->boot();

        $this->assertSame($app->make(PzOfxParser::class), $app->make(PzOfxParser::class));
        $this->assertSame($app->make(PzOfxConfig::class), $app->make(PzOfxParser::class)->config());
        $this->assertSame('UTC', $app->make(PzOfxConfig::class)->timezone);
    }

    public function test_atalho_estatico_usa_a_config_da_aplicacao(): void
    {
        $this->boot(['pzofx' => ['defaults' => ['description' => 'name']]]);

        $this->assertSame(PzOfxDescriptionEnum::NAME, PzOfx::config()->defaults->description);
        $transaction = PzOfx::fromFile(self::fixture('itau_single_line.ofx'))->transactions()[1];
        $this->assertSame('UTC', $transaction->postedAt->getTimezone()->getName());
    }

    public function test_idioma_segue_a_aplicacao_ou_a_config(): void
    {
        $app = $this->boot();
        $rule = new PzOfxFileRule();
        $errors = [];
        $rule->validate('file', '', static function (string $message) use (&$errors): void {
            $errors[] = $message;
        });
        $this->assertSame(['Nenhum arquivo OFX foi informado.'], $errors);

        $app->make('config')->set('app.locale', 'en');
        $errors = [];
        $rule->validate('file', '', static function (string $message) use (&$errors): void {
            $errors[] = $message;
        });
        $this->assertSame(['No OFX file was informed.'], $errors);

        $this->boot(['pzofx' => ['locale' => 'en']]);
        $this->assertSame('The OFX file is empty.', \Puzl\PzOfx\Enums\PzOfxErrorEnum::FILE_EMPTY->message());
    }

    public function test_publish_so_em_console(): void
    {
        $this->boot([], false);
        $this->assertSame([], ServiceProvider::pathsToPublish(PzOfxServiceProvider::class, PzOfxServiceProvider::PUBLISH_TAG));

        $this->boot([], true);
        $paths = ServiceProvider::pathsToPublish(PzOfxServiceProvider::class, PzOfxServiceProvider::PUBLISH_TAG);
        $this->assertSame([realpath(PzOfxServiceProvider::CONFIG_PATH)], array_map('realpath', array_keys($paths)));
        $this->assertStringEndsWith('config/pzofx.php', (string) array_values($paths)[0]);
    }

    public function test_regra_de_validacao(): void
    {
        $this->boot(['pzofx' => ['locale' => 'pt_BR']]);
        $validate = static function (mixed $value, ?PzOfxFileRule $rule = null): array {
            $errors = [];
            ($rule ?? new PzOfxFileRule())->validate('file', $value, static function (string $message) use (&$errors): void {
                $errors[] = $message;
            });

            return $errors;
        };

        $rule = new PzOfxFileRule();
        $this->assertSame([], $validate(new SplFileInfo(self::fixture('bb_balance_rows.ofx')), $rule));
        $this->assertCount(4, $rule->document()?->transactions() ?? []);

        $this->assertSame(['O arquivo não é um OFX válido: o elemento <OFX> não foi encontrado.'], $validate('<html></html>'));
        $this->assertSame(['O arquivo OFX não tem transações.'], $validate(self::ofx('')));
        $this->assertSame([], $validate(self::ofx(''), new PzOfxFileRule(requireTransactions: false)));
        $this->assertSame(['O arquivo OFX não tem extrato de conta ou de cartão.'], $validate('<OFX><SIGNONMSGSRSV1></SIGNONMSGSRSV1></OFX>'));
        $this->assertSame([], $validate(self::ofx(self::trn('abc').self::trn()), new PzOfxFileRule(config: ['strict' => false])));

        $failed = new PzOfxFileRule();
        $validate(new SplFileInfo(self::fixture('itau_single_line.ofx')), $failed);
        $validate('<x>', $failed);
        $this->assertNull($failed->document(), 'documento só da última validação bem-sucedida');
    }
}
