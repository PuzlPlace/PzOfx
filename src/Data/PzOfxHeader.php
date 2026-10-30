<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Data;

/** `CHAVE:VALOR` do OFX 1.x ou atributos do `<?OFX?>` do 2.x, em maiúsculas. */
final class PzOfxHeader
{
    /**
     * @param array<string, string> $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly bool $isXml,
    ) {
    }

    public function get(string $name): ?string
    {
        return $this->fields[strtoupper($name)] ?? null;
    }

    public function version(): ?string
    {
        return $this->get('VERSION');
    }

    public function encoding(): ?string
    {
        return $this->get('ENCODING');
    }

    /** No 2.x, o `encoding` do `<?xml?>`. */
    public function charset(): ?string
    {
        return $this->get('CHARSET');
    }

    public function isSgml(): bool
    {
        return ! $this->isXml;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->fields;
    }
}
