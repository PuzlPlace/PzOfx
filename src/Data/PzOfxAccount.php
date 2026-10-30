<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Data;

use Puzl\PzOfx\Enums\PzOfxAccountTypeEnum;
use Puzl\PzOfx\Support\BankCode;

/** Conta do extrato. Valores brutos preservados; os métodos normalizam. */
final class PzOfxAccount
{
    public function __construct(
        /** `null` em cartão. */
        public readonly ?string $bankId,
        public readonly ?string $branchId,
        public readonly string $accountId,
        public readonly ?PzOfxAccountTypeEnum $type,
        public readonly ?string $rawType,
        public readonly ?string $accountKey,
    ) {
    }

    /** COMPE com 3 dígitos (`0341` → `341`); `null` se não for um. */
    public function bankCode(): ?string
    {
        return BankCode::normalize($this->bankId);
    }

    public function bankName(): ?string
    {
        return BankCode::name($this->bankCode());
    }

    /** Antes do último `-`. */
    public function number(): string
    {
        $position = strrpos($this->accountId, '-');

        return $position === false ? $this->accountId : substr($this->accountId, 0, $position);
    }

    /** Depois do último `-`. */
    public function digit(): ?string
    {
        $position = strrpos($this->accountId, '-');
        $digit = $position === false ? '' : substr($this->accountId, $position + 1);

        return $digit === '' ? null : $digit;
    }

    /** Escopo de deduplicação: FITID só é único por conta. */
    public function key(): string
    {
        return implode('|', [$this->bankCode() ?? (string) $this->bankId, (string) $this->branchId, $this->accountId]);
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'bank_id' => $this->bankId,
            'bank_code' => $this->bankCode(),
            'bank_name' => $this->bankName(),
            'branch_id' => $this->branchId,
            'account_id' => $this->accountId,
            'number' => $this->number(),
            'digit' => $this->digit(),
            'type' => $this->type?->value,
            'raw_type' => $this->rawType,
            'account_key' => $this->accountKey,
        ];
    }
}
