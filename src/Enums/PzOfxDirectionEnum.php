<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Enums;

/** Pelo sinal do valor: `>= 0` é crédito. */
enum PzOfxDirectionEnum: string
{
    case CREDIT = 'CREDIT';
    case DEBIT = 'DEBIT';
}
