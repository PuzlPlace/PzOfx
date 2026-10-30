<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Tests\Features\Testing\Fixtures;

use Puzl\PzOfx\Contracts\PzOfxParserInterface;
use Puzl\PzOfx\PzOfx;
use Puzl\PzOfx\PzOfxParser;

/** Consumidor típico, um estilo de uso por método. */
final class ImportService
{
    public function __construct(
        public readonly PzOfxParserInterface $parser,
        public readonly PzOfxParser $concrete,
    ) {
    }

    public function total(string $content): string
    {
        return $this->parser->parseString($content)->statement()->total();
    }

    public function totalWithConcrete(string $content): string
    {
        return $this->concrete->parseString($content)->statement()->total();
    }

    public function totalWithStatic(string $content): string
    {
        return PzOfx::fromString($content)->statement()->total();
    }
}
