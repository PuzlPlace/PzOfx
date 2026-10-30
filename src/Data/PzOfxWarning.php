<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Data;

use Puzl\PzOfx\Enums\PzOfxWarningEnum;

final class PzOfxWarning
{
    /**
     * @param array<string, scalar|null> $context
     */
    public function __construct(
        public readonly PzOfxWarningEnum $code,
        public readonly array $context = [],
    ) {
    }

    public function message(?string $locale = null): string
    {
        return $this->code->message($this->context, $locale);
    }

    /**
     * @return array{code: string, message: string, context: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return ['code' => $this->code->value, 'message' => $this->message(), 'context' => $this->context];
    }
}
