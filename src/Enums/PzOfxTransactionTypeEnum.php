<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Enums;

/** `<TRNTYPE>`. Informativo: a direção vem do sinal de `<TRNAMT>`. */
enum PzOfxTransactionTypeEnum: string
{
    case CREDIT = 'CREDIT';
    case DEBIT = 'DEBIT';
    /** Juros. */
    case INT = 'INT';
    /** Dividendos. */
    case DIV = 'DIV';
    /** Tarifa. */
    case FEE = 'FEE';
    /** Taxa de serviço. */
    case SRVCHG = 'SRVCHG';
    /** Depósito. */
    case DEP = 'DEP';
    /** Caixa eletrônico. */
    case ATM = 'ATM';
    /** Compra no débito. */
    case POS = 'POS';
    /** Transferência. */
    case XFER = 'XFER';
    case CHECK = 'CHECK';
    case PAYMENT = 'PAYMENT';
    /** Saque. */
    case CASH = 'CASH';
    /** Depósito direto (ex.: salário). */
    case DIRECTDEP = 'DIRECTDEP';
    /** Débito automático. */
    case DIRECTDEBIT = 'DIRECTDEBIT';
    /** Pagamento recorrente. */
    case REPEATPMT = 'REPEATPMT';
    /** Valor retido (OFX 2.x). */
    case HOLD = 'HOLD';
    /** Também para tag vazia ou desconhecida. */
    case OTHER = 'OTHER';

    /** Vazio ou desconhecido vira `OTHER`. */
    public static function fromOfx(?string $value): self
    {
        return self::tryFromOfx($value) ?? self::OTHER;
    }

    /** `null` se não for tipo OFX. */
    public static function tryFromOfx(?string $value): ?self
    {
        $value = strtoupper(trim((string) $value));

        return $value === '' ? null : self::tryFrom($value);
    }
}
