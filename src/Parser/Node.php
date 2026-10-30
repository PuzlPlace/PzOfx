<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Parser;

/**
 * Aggregate com seus campos (um valor por nome) e filhos. Folha não vira objeto,
 * para caber extratos grandes na memória.
 *
 * @internal
 */
final class Node
{
    /** @var array<string, string> */
    public array $fields = [];

    /** @var list<Node> */
    public array $children = [];

    public function __construct(
        public readonly string $name,
    ) {
    }

    public function field(string $name): ?string
    {
        return $this->fields[$name] ?? null;
    }

    public function child(string $name): ?self
    {
        foreach ($this->children as $child) {
            if ($child->name === $name) {
                return $child;
            }
        }

        return null;
    }

    /** `value('BANKACCTFROM', 'BANKID')`. */
    public function value(string ...$path): ?string
    {
        $field = array_pop($path);
        $node = $this;
        foreach ($path as $name) {
            $node = $node->child($name);
            if ($node === null) {
                return null;
            }
        }

        return $node->field((string) $field);
    }

    /**
     * Em profundidade, sem descer dentro dos encontrados.
     *
     * @return list<Node>
     */
    public function find(string $name): array
    {
        $found = [];
        foreach ($this->children as $child) {
            if ($child->name === $name) {
                $found[] = $child;
            } else {
                array_push($found, ...$child->find($name));
            }
        }

        return $found;
    }

    /**
     * @return array<string, string>
     */
    public function firstFields(): array
    {
        return $this->fields;
    }
}
