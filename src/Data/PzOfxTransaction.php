<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Data;

use DateTimeImmutable;
use Puzl\PzOfx\Enums\PzOfxDirectionEnum;
use Puzl\PzOfx\Enums\PzOfxTransactionTypeEnum;
use Puzl\PzOfx\Support\Decimal;

/**
 * `amount` já vem com a política de sinal do perfil; `uniqueId` é o FITID
 * confiável ou o fingerprint, único dentro da conta.
 */
final class PzOfxTransaction
{
    /**
     * @param array<string, string> $raw
     */
    public function __construct(
        /** Posição no extrato, na ordem do arquivo. */
        public readonly int $index,
        public readonly PzOfxTransactionTypeEnum $type,
        public readonly ?string $rawType,
        public readonly DateTimeImmutable $postedAt,
        public readonly ?DateTimeImmutable $userDate,
        public readonly ?DateTimeImmutable $availableAt,
        public readonly string $amount,
        public readonly ?string $fitId,
        public readonly ?string $name,
        public readonly ?string $memo,
        /** Pela estratégia do perfil; `''` se não houver MEMO nem NAME. */
        public readonly string $description,
        public readonly ?string $checkNumber,
        public readonly ?string $referenceNumber,
        public readonly ?string $serverTransactionId,
        public readonly ?string $correctFitId,
        public readonly ?string $correctAction,
        public readonly ?string $payeeId,
        public readonly ?string $sic,
        public readonly ?string $currency,
        public readonly string $fingerprint,
        public readonly string $uniqueId,
        public readonly array $raw,
    ) {
    }

    /** `>= 0` é crédito. */
    public function direction(): PzOfxDirectionEnum
    {
        return Decimal::isNegative($this->amount) ? PzOfxDirectionEnum::DEBIT : PzOfxDirectionEnum::CREDIT;
    }

    public function isCredit(): bool
    {
        return $this->direction() === PzOfxDirectionEnum::CREDIT;
    }

    public function isDebit(): bool
    {
        return $this->direction() === PzOfxDirectionEnum::DEBIT;
    }

    public function absoluteAmount(): string
    {
        return Decimal::abs($this->amount);
    }

    /** Prefira `amount` ou `amountCents()`. */
    public function amountFloat(): float
    {
        return (float) $this->amount;
    }

    /** Arredonda metade para longe do zero. */
    public function amountCents(): int
    {
        return Decimal::toMinor($this->amount);
    }

    public function date(): string
    {
        return $this->postedAt->format('Y-m-d');
    }

    /** Inclui campos proprietários (`BANCO.XYZ`). */
    public function rawField(string $name): ?string
    {
        return $this->raw[strtoupper($name)] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'type' => $this->type->value,
            'raw_type' => $this->rawType,
            'direction' => $this->direction()->value,
            'posted_at' => $this->postedAt->format('Y-m-d H:i:s'),
            'user_date' => $this->userDate?->format('Y-m-d H:i:s'),
            'available_at' => $this->availableAt?->format('Y-m-d H:i:s'),
            'amount' => $this->amount,
            'fit_id' => $this->fitId,
            'name' => $this->name,
            'memo' => $this->memo,
            'description' => $this->description,
            'check_number' => $this->checkNumber,
            'reference_number' => $this->referenceNumber,
            'server_transaction_id' => $this->serverTransactionId,
            'correct_fit_id' => $this->correctFitId,
            'correct_action' => $this->correctAction,
            'payee_id' => $this->payeeId,
            'sic' => $this->sic,
            'currency' => $this->currency,
            'fingerprint' => $this->fingerprint,
            'unique_id' => $this->uniqueId,
        ];
    }
}
