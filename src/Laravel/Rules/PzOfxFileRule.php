<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Laravel\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Puzl\PzOfx\Config\PzOfxConfig;
use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use Puzl\PzOfx\Exceptions\PzOfxException;
use Puzl\PzOfx\Exceptions\PzOfxFileException;
use Puzl\PzOfx\Data\PzOfxDocument;
use Puzl\PzOfx\PzOfx;
use SplFileInfo;

/**
 * Upload legível, com extrato e (por padrão) com transações. Aceita
 * upload ou conteúdo, nunca caminho: a string vem da requisição.
 */
final class PzOfxFileRule implements ValidationRule
{
    private ?PzOfxDocument $document = null;

    /**
     * @param array<string, mixed>|PzOfxConfig|null $config ajustes para esta leitura
     */
    public function __construct(
        private readonly bool $requireTransactions = true,
        private readonly array|PzOfxConfig|null $config = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $this->document = null;

        try {
            $parser = PzOfx::parser($this->config);
            $document = match (true) {
                $value instanceof SplFileInfo => $parser->parseFile($value),
                is_string($value) && $value !== '' => $parser->parseString($value),
                default => throw PzOfxFileException::because(PzOfxErrorEnum::FILE_NOT_INFORMED),
            };
        } catch (PzOfxException $e) {
            $fail($e->getMessage());

            return;
        }

        if (! $document->hasStatements()) {
            $fail(PzOfxErrorEnum::NO_STATEMENT->message());

            return;
        }
        if ($this->requireTransactions && $document->transactions() === []) {
            $fail(PzOfxErrorEnum::NO_TRANSACTIONS->message());

            return;
        }

        $this->document = $document;
    }

    /** Documento da última validação bem-sucedida, para não ler duas vezes. */
    public function document(): ?PzOfxDocument
    {
        return $this->document;
    }
}
