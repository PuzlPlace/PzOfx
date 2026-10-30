<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Laravel;

use Illuminate\Support\ServiceProvider;
use Puzl\PzOfx\Config\PzOfxConfig;
use Puzl\PzOfx\Contracts\PzOfxParserInterface;
use Puzl\PzOfx\PzOfx;
use Puzl\PzOfx\PzOfxParser;

/**
 * Interface e classe concreta resolvem para o mesmo singleton, e o
 * `PzOfx::` estático usa o container: mock ou fake valem para os dois estilos.
 */
final class PzOfxServiceProvider extends ServiceProvider
{
    public const CONFIG_PATH = __DIR__.'/../../config/pzofx.php';

    public const PUBLISH_TAG = 'pzofx-config';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'pzofx');

        $this->app->singleton(PzOfxConfig::class, static function ($app): PzOfxConfig {
            $config = (array) $app->make('config')->get('pzofx', []);
            unset($config['locale']);
            $config['timezone'] ??= $app->make('config')->get('app.timezone');

            return PzOfxConfig::fromArray($config);
        });

        $this->app->singleton(PzOfxParser::class, static fn ($app): PzOfxParser => new PzOfxParser($app->make(PzOfxConfig::class)));

        // Closure, não alias: mock da concreta também alcança a interface.
        $this->app->bind(PzOfxParserInterface::class, static fn ($app): PzOfxParserInterface => $app->make(PzOfxParser::class));

        $app = $this->app;
        PzOfx::resolveConfigUsing(static fn (): PzOfxConfig => $app->make(PzOfxConfig::class));
        PzOfx::resolveParserUsing(static fn (): PzOfxParserInterface => $app->make(PzOfxParserInterface::class));
    }

    public function boot(): void
    {
        $app = $this->app;
        $locale = $app->make('config')->get('pzofx.locale');
        PzOfx::useLocale(is_string($locale) && $locale !== ''
            ? $locale
            : static fn (): ?string => $app->make('config')->get('app.locale'));

        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::CONFIG_PATH => $this->configPath('pzofx.php'),
            ], self::PUBLISH_TAG);
        }
    }

    /** Sem `config_path()` (container puro em testes), cai no `basePath()`. */
    protected function configPath(string $file): string
    {
        return function_exists('config_path')
            ? config_path($file)
            : $this->app->basePath('config/'.$file);
    }
}
