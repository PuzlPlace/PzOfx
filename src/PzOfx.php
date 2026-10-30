<?php

declare(strict_types=1);

namespace Puzl\PzOfx;

use Closure;
use Illuminate\Container\Container;
use Puzl\PzOfx\Config\PzOfxConfig;
use Puzl\PzOfx\Contracts\PzOfxParserInterface;
use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use Puzl\PzOfx\Exceptions\PzOfxException;
use Puzl\PzOfx\Data\PzOfxDocument;
use Puzl\PzOfx\Support\Messages;
use Puzl\PzOfx\Testing\PzOfxFake;
use SplFileInfo;
use Throwable;

/**
 * Atalho estático. No Laravel usa o parser do container, então mock ou fake
 * valem também aqui. Para injeção, use `PzOfxParserInterface`.
 */
final class PzOfx
{
    private static ?PzOfxConfig $config = null;

    /** @var (Closure(): PzOfxConfig)|null */
    private static ?Closure $configResolver = null;

    /** @var (Closure(): PzOfxParserInterface)|null */
    private static ?Closure $parserResolver = null;

    private static ?PzOfxFake $fake = null;

    /**
     * @param array<string, mixed>|PzOfxConfig|null $config ajustes desta leitura
     *
     * @throws PzOfxException
     */
    public static function fromFile(string|SplFileInfo|null $file, array|PzOfxConfig|null $config = null): PzOfxDocument
    {
        return self::parser($config)->parseFile($file);
    }

    /**
     * @param array<string, mixed>|PzOfxConfig|null $config
     *
     * @throws PzOfxException
     */
    public static function fromString(string $content, array|PzOfxConfig|null $config = null): PzOfxDocument
    {
        return self::parser($config)->parseString($content);
    }

    /**
     * @param resource $stream
     * @param array<string, mixed>|PzOfxConfig|null $config
     *
     * @throws PzOfxException
     */
    public static function fromStream($stream, array|PzOfxConfig|null $config = null): PzOfxDocument
    {
        return self::parser($config)->parseStream($stream);
    }

    /**
     * Detecta stream, arquivo, conteúdo ou caminho.
     *
     * @param string|SplFileInfo|resource|null $input
     * @param array<string, mixed>|PzOfxConfig|null $config
     * @throws PzOfxException
     */
    public static function parse(mixed $input, array|PzOfxConfig|null $config = null): PzOfxDocument
    {
        return self::parser($config)->parse($input);
    }

    /**
     * Fake, container ou config global — nessa ordem.
     *
     * @param array<string, mixed>|PzOfxConfig|null $config
     */
    public static function parser(array|PzOfxConfig|null $config = null): PzOfxParserInterface
    {
        $parser = match (true) {
            self::$fake !== null => self::$fake,
            self::$config === null && self::$parserResolver !== null => (self::$parserResolver)(),
            default => new PzOfxParser(self::config()),
        };

        return $config === null ? $parser : $parser->withConfig($config);
    }

    /**
     * Devolve as respostas em ordem (repete a última) ou, sem respostas, lê de
     * verdade e só registra. No container, vale para quem for resolvido depois.
     *
     * @param PzOfxDocument|Throwable|PzOfxErrorEnum|list<PzOfxDocument|Throwable|PzOfxErrorEnum>|null $responses
     */
    public static function fake(PzOfxDocument|Throwable|PzOfxErrorEnum|array|null $responses = null): PzOfxFake
    {
        self::$fake = new PzOfxFake($responses ?? [], self::config());

        if (class_exists(Container::class)) {
            $container = Container::getInstance();
            if ($container->bound(PzOfxParserInterface::class)) {
                $container->instance(PzOfxParser::class, self::$fake);
                $container->instance(PzOfxParserInterface::class, self::$fake);
            }
        }

        return self::$fake;
    }

    public static function fakeInstance(): PzOfxFake
    {
        return self::$fake ?? throw new \LogicException('PzOfx::fake() não foi chamado.');
    }

    /**
     * @param (callable(mixed $input, string $method): bool)|null $callback
     */
    public static function assertParsed(?callable $callback = null): void
    {
        self::fakeInstance()->assertParsed($callback);
    }

    public static function assertParsedTimes(int $times): void
    {
        self::fakeInstance()->assertParsedTimes($times);
    }

    public static function assertNothingParsed(): void
    {
        self::fakeInstance()->assertNothingParsed();
    }

    /**
     * @param (Closure(): PzOfxParserInterface)|null $resolver
     */
    public static function resolveParserUsing(?Closure $resolver): void
    {
        self::$parserResolver = $resolver;
    }

    /**
     * @param array<string, mixed>|PzOfxConfig $config
     */
    public static function configure(array|PzOfxConfig $config): void
    {
        self::$config = $config instanceof PzOfxConfig ? $config : PzOfxConfig::fromArray($config);
    }

    /**
     * Resolvida sob demanda (o Service Provider lê `config('pzofx')`).
     *
     * @param (Closure(): PzOfxConfig)|null $resolver
     */
    public static function resolveConfigUsing(?Closure $resolver): void
    {
        self::$configResolver = $resolver;
        self::$config = null;
    }

    public static function config(): PzOfxConfig
    {
        if (self::$config === null && self::$configResolver !== null) {
            return (self::$configResolver)();
        }

        return self::$config ??= PzOfxConfig::default();
    }

    /**
     * Closure é avaliada a cada mensagem.
     *
     * @param string|Closure(): ?string $locale
     */
    public static function useLocale(string|Closure $locale): void
    {
        Messages::useLocale($locale);
    }

    public static function reset(): void
    {
        self::$config = null;
        self::$configResolver = null;
        self::$parserResolver = null;
        self::$fake = null;
        Messages::useLocale(Messages::DEFAULT_LOCALE);
    }
}
