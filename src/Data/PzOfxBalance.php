<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Data;

use DateTimeImmutable;
use Puzl\PzOfx\Support\Decimal;

final class PzOfxBalance
{
    public function __construct(
        public readonly string $amount,
        public readonly ?DateTimeImmutable $asOf,
    ) {
    }

    public function amountFloat(): float
    {
        return (float) $this->amount;
    }

    public function amountCents(): int
    {
        return Decimal::toMinor($this->amount);
    }

    /**
     * @return array{amount: string, as_of: ?string}
     */
    public function toArray(): array
    {
        return ['amount' => $this->amount, 'as_of' => $this->asOf?->format('Y-m-d H:i:s')];
    }
}
