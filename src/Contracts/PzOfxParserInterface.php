<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Contracts;

use Puzl\PzOfx\Config\PzOfxConfig;
use Puzl\PzOfx\Exceptions\PzOfxException;
use Puzl\PzOfx\Data\PzOfxDocument;
use SplFileInfo;

/** Tipo para injetar. No Laravel resolve para o singleton do `PzOfxParser`. */
interface PzOfxParserInterface
{
    public function config(): PzOfxConfig;

    /**
     * Cópia com a config ajustada.
     *
     * @param array<string, mixed>|PzOfxConfig $config
     */
    public function withConfig(array|PzOfxConfig $config): self;

    /**
     * Caminho ou `SplFileInfo` (inclui `UploadedFile`).
     *
     * @throws PzOfxException
     */
    public function parseFile(string|SplFileInfo|null $file): PzOfxDocument;

    /**
     * @throws PzOfxException
     */
    public function parseString(string $content): PzOfxDocument;

    /**
     * Lido a partir da posição atual.
     *
     * @param resource $stream
     * @throws PzOfxException
     */
    public function parseStream($stream): PzOfxDocument;

    /**
     * Detecta stream, arquivo, conteúdo ou caminho.
     *
     * @param string|SplFileInfo|resource|null $input
     * @throws PzOfxException
     */
    public function parse(mixed $input): PzOfxDocument;
}
