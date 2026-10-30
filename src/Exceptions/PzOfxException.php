<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Exceptions;

use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use RuntimeException;
use Throwable;

/** Base das exceções. Decida por `error()`; a mensagem é traduzida. */
class PzOfxException extends RuntimeException
{
    /**
     * @param array<string, scalar|null> $context
     */
    final public function __construct(
        private readonly PzOfxErrorEnum $error,
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($error->message($context), 0, $previous);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public static function because(PzOfxErrorEnum $error, array $context = [], ?Throwable $previous = null): static
    {
        return new static($error, $context, $previous);
    }

    public function error(): PzOfxErrorEnum
    {
        return $this->error;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }
}
