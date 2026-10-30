<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Data;

use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use Puzl\PzOfx\Enums\PzOfxWarningEnum;
use Puzl\PzOfx\Exceptions\PzOfxParseException;

/** Resultado da leitura: um arquivo pode ter vários extratos (contas e cartões). */
final class PzOfxDocument
{
    /**
     * @param list<PzOfxStatement> $statements
     * @param list<PzOfxWarning> $warnings
     */
    public function __construct(
        public readonly PzOfxHeader $header,
        public readonly ?PzOfxSignOn $signOn,
        public readonly array $statements,
        public readonly array $warnings,
    ) {
    }

    /**
     * Primeiro extrato.
     *
     * @throws PzOfxParseException quando o arquivo não tem nenhum extrato
     */
    public function statement(): PzOfxStatement
    {
        return $this->statements[0] ?? throw PzOfxParseException::because(PzOfxErrorEnum::NO_STATEMENT);
    }

    /**
     * @return list<PzOfxStatement>
     */
    public function bankStatements(): array
    {
        return array_values(array_filter($this->statements, static fn (PzOfxStatement $s): bool => $s->isBank()));
    }

    /**
     * @return list<PzOfxStatement>
     */
    public function creditCardStatements(): array
    {
        return array_values(array_filter($this->statements, static fn (PzOfxStatement $s): bool => $s->isCreditCard()));
    }

    public function hasStatements(): bool
    {
        return $this->statements !== [];
    }

    /**
     * De todos os extratos.
     *
     * @return list<PzOfxTransaction>
     */
    public function transactions(): array
    {
        return array_merge([], ...array_map(static fn (PzOfxStatement $s): array => $s->transactions, $this->statements));
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /**
     * @return list<PzOfxWarning>
     */
    public function warningsOf(PzOfxWarningEnum $code): array
    {
        return array_values(array_filter($this->warnings, static fn (PzOfxWarning $w): bool => $w->code === $code));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'header' => $this->header->all(),
            'sign_on' => $this->signOn?->toArray(),
            'statements' => array_map(static fn (PzOfxStatement $s): array => $s->toArray(), $this->statements),
            'warnings' => array_map(static fn (PzOfxWarning $w): array => $w->toArray(), $this->warnings),
        ];
    }
}
