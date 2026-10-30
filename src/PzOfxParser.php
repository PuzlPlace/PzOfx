<?php

declare(strict_types=1);

namespace Puzl\PzOfx;

use Puzl\PzOfx\Config\PzOfxConfig;
use Puzl\PzOfx\Contracts\PzOfxParserInterface;
use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use Puzl\PzOfx\Exceptions\PzOfxException;
use Puzl\PzOfx\Exceptions\PzOfxFileException;
use Puzl\PzOfx\Data\PzOfxDocument;
use Puzl\PzOfx\Parser\DocumentMapper;
use Puzl\PzOfx\Parser\HeaderReader;
use Puzl\PzOfx\Parser\TreeBuilder;
use Puzl\PzOfx\Support\Encoding;
use SplFileInfo;

/**
 * Sem estado entre leituras. Não é `final` para quem injeta a classe concreta
 * poder mockar.
 */
class PzOfxParser implements PzOfxParserInterface
{
    private readonly PzOfxConfig $config;

    public function __construct(?PzOfxConfig $config = null)
    {
        $this->config = $config ?? PzOfxConfig::default();
    }

    public function config(): PzOfxConfig
    {
        return $this->config;
    }

    /**
     * @param array<string, mixed>|PzOfxConfig $config
     */
    public function withConfig(array|PzOfxConfig $config): self
    {
        return new self($config instanceof PzOfxConfig ? $config : $this->config->with($config));
    }

    /**
     * @throws PzOfxException
     */
    public function parseFile(string|SplFileInfo|null $file): PzOfxDocument
    {
        $path = $file instanceof SplFileInfo ? ($file->getRealPath() ?: $file->getPathname()) : (string) $file;
        if (trim($path) === '') {
            throw PzOfxFileException::because(PzOfxErrorEnum::FILE_NOT_INFORMED);
        }
        if (! is_file($path)) {
            throw PzOfxFileException::because(PzOfxErrorEnum::FILE_NOT_FOUND, ['path' => $path]);
        }

        $size = @filesize($path);
        if ($size !== false) {
            $this->assertSize($size);
        }

        $content = is_readable($path) ? @file_get_contents($path) : false;
        if ($content === false) {
            throw PzOfxFileException::because(PzOfxErrorEnum::FILE_NOT_READABLE, ['path' => $path]);
        }

        return $this->parseString($content);
    }

    /**
     * @param resource $stream
     * @throws PzOfxException
     */
    public function parseStream($stream): PzOfxDocument
    {
        if (! is_resource($stream)) {
            throw PzOfxFileException::because(PzOfxErrorEnum::FILE_NOT_INFORMED);
        }

        $limit = $this->config->maxFileSize;
        $content = stream_get_contents($stream, $limit > 0 ? $limit + 1 : -1);
        if ($content === false) {
            throw PzOfxFileException::because(PzOfxErrorEnum::FILE_NOT_READABLE, ['path' => 'stream']);
        }

        return $this->parseString($content);
    }

    /**
     * @throws PzOfxException
     */
    public function parseString(string $content): PzOfxDocument
    {
        $this->assertSize(strlen($content));
        if (trim($content) === '') {
            throw PzOfxFileException::because(PzOfxErrorEnum::FILE_EMPTY);
        }

        [$header, $body] = HeaderReader::split(Encoding::normalizeBom($content));
        $body = Encoding::toUtf8($body, $header->charset(), $this->config->encoding);

        return (new DocumentMapper($this->config))->map(TreeBuilder::build($body), $header);
    }

    /**
     * String com `<`, quebra de linha ou `OFXHEADER` é conteúdo; senão, caminho.
     *
     * @param string|SplFileInfo|resource|null $input
     * @throws PzOfxException
     */
    public function parse(mixed $input): PzOfxDocument
    {
        if (is_resource($input)) {
            return $this->parseStream($input);
        }
        if (is_string($input) && self::looksLikeContent($input)) {
            return $this->parseString($input);
        }
        if ($input === null || is_string($input) || $input instanceof SplFileInfo) {
            return $this->parseFile($input);
        }

        throw PzOfxFileException::because(PzOfxErrorEnum::FILE_NOT_INFORMED);
    }

    private static function looksLikeContent(string $input): bool
    {
        return strpbrk($input, "<\n\r\0") !== false || stripos(ltrim($input, "\xEF\xBB\xBF \t"), 'OFXHEADER') === 0;
    }

    private function assertSize(int $size): void
    {
        $limit = $this->config->maxFileSize;
        if ($limit > 0 && $size > $limit) {
            throw PzOfxFileException::because(PzOfxErrorEnum::FILE_TOO_LARGE, ['size' => $size, 'max' => $limit]);
        }
    }
}
