<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Enums;

/** `<ACCTTYPE>`; extrato de cartão é sempre `CREDITCARD`. */
enum PzOfxAccountTypeEnum: string
{
    case CHECKING = 'CHECKING';
    case SAVINGS = 'SAVINGS';
    case MONEYMRKT = 'MONEYMRKT';
    case CREDITLINE = 'CREDITLINE';
    case CD = 'CD';
    case CREDITCARD = 'CREDITCARD';

    /** Ausente ou fora da especificação vira `null`. */
    public static function tryFromOfx(?string $value): ?self
    {
        $value = strtoupper(trim((string) $value));

        return $value === '' ? null : self::tryFrom($value);
    }
}
