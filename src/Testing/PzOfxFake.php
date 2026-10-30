<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Testing;

use PHPUnit\Framework\Assert;
use Puzl\PzOfx\Config\PzOfxConfig;
use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use Puzl\PzOfx\Exceptions\PzOfxConfigException;
use Puzl\PzOfx\Exceptions\PzOfxFileException;
use Puzl\PzOfx\Exceptions\PzOfxParseException;
use Puzl\PzOfx\Data\PzOfxDocument;
use Puzl\PzOfx\PzOfxParser;
use SplFileInfo;
use Throwable;

/**
 * Respostas em ordem (repete a última); sem respostas, lê de verdade.
 * Estende `PzOfxParser` para substituir a interface e a classe concreta.
 */
class PzOfxFake extends PzOfxParser
{
    /** @var list<PzOfxDocument|Throwable> */
    private array $responses;

    private int $served = 0;

    /** @var list<array{method: string, input: mixed, config: array<string, mixed>|PzOfxConfig|null}> */
    private array $calls = [];

    /** @var array<string, mixed>|PzOfxConfig|null */
    private array|PzOfxConfig|null $pendingConfig = null;

    /**
     * @param PzOfxDocument|Throwable|PzOfxErrorEnum|list<PzOfxDocument|Throwable|PzOfxErrorEnum> $responses
     */
    public function __construct(PzOfxDocument|Throwable|PzOfxErrorEnum|array $responses = [], ?PzOfxConfig $config = null)
    {
        parent::__construct($config);
        $this->responses = array_map(self::normalize(...), is_array($responses) ? array_values($responses) : [$responses]);
    }

    public function push(PzOfxDocument $document): static
    {
        $this->responses[] = $document;

        return $this;
    }

    public function throw(Throwable|PzOfxErrorEnum $error): static
    {
        $this->responses[] = self::normalize($error);

        return $this;
    }

    /** Registra a config na próxima leitura; o fake continua o mesmo. */
    public function withConfig(array|PzOfxConfig $config): static
    {
        $this->pendingConfig = $config;

        return $this;
    }

    public function parseFile(string|SplFileInfo|null $file): PzOfxDocument
    {
        return $this->serve('parseFile', $file, static fn (PzOfxParser $parser): PzOfxDocument => $parser->parseFile($file));
    }

    public function parseString(string $content): PzOfxDocument
    {
        return $this->serve('parseString', $content, static fn (PzOfxParser $parser): PzOfxDocument => $parser->parseString($content));
    }

    public function parseStream($stream): PzOfxDocument
    {
        return $this->serve('parseStream', $stream, static fn (PzOfxParser $parser): PzOfxDocument => $parser->parseStream($stream));
    }

    public function parse(mixed $input): PzOfxDocument
    {
        return $this->serve('parse', $input, static fn (PzOfxParser $parser): PzOfxDocument => $parser->parse($input));
    }

    /**
     * @return list<array{method: string, input: mixed, config: array<string, mixed>|PzOfxConfig|null}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @param (callable(mixed $input, string $method, array<string, mixed>|PzOfxConfig|null $config): bool)|null $callback
     */
    public function assertParsed(?callable $callback = null): void
    {
        $matching = $callback === null
            ? $this->calls
            : array_filter($this->calls, static fn (array $call): bool => (bool) $callback($call['input'], $call['method'], $call['config']));

        Assert::assertNotEmpty($matching, 'Nenhuma leitura de OFX correspondente foi feita.');
    }

    public function assertParsedTimes(int $times): void
    {
        Assert::assertCount($times, $this->calls, sprintf('Esperava %d leitura(s) de OFX, houve %d.', $times, count($this->calls)));
    }

    public function assertNothingParsed(): void
    {
        $this->assertParsedTimes(0);
    }

    /**
     * @param \Closure(PzOfxParser): PzOfxDocument $real leitura de verdade (modo espião)
     */
    private function serve(string $method, mixed $input, \Closure $real): PzOfxDocument
    {
        $config = $this->pendingConfig;
        $this->pendingConfig = null;
        $this->calls[] = ['method' => $method, 'input' => $input, 'config' => $config];

        if ($this->responses === []) {
            return $real($this->real($config));
        }

        $response = $this->responses[min($this->served++, count($this->responses) - 1)];
        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }

    /**
     * @param array<string, mixed>|PzOfxConfig|null $config
     */
    private function real(array|PzOfxConfig|null $config = null): PzOfxParser
    {
        $parser = new PzOfxParser($this->config());

        return $config === null ? $parser : $parser->withConfig($config);
    }

    private static function normalize(PzOfxDocument|Throwable|PzOfxErrorEnum $response): PzOfxDocument|Throwable
    {
        if (! $response instanceof PzOfxErrorEnum) {
            return $response;
        }

        return match (true) {
            str_starts_with($response->value, 'file_') => PzOfxFileException::because($response),
            $response === PzOfxErrorEnum::INVALID_CONFIG => PzOfxConfigException::because($response),
            default => PzOfxParseException::because($response),
        };
    }
}
