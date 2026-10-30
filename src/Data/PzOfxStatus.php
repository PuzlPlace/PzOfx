<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Data;

final class PzOfxStatus
{
    public function __construct(
        public readonly int $code,
        public readonly ?string $severity,
        public readonly ?string $message,
    ) {
    }

    public function isOk(): bool
    {
        return $this->code === 0;
    }

    /**
     * @return array{code: int, severity: ?string, message: ?string}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'severity' => $this->severity, 'message' => $this->message];
    }
}
