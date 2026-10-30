<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Enums;

/** Como ler o sinal de `<TRNAMT>`. Alguns emissores de cartão mandam compra positiva. */
enum PzOfxAmountSignEnum: string
{
    /** Padrão: o sinal do arquivo vale. */
    case AS_IS = 'as_is';

    case INVERT = 'invert';

    /**
     * CREDIT/DEP/INT/DIV/DIRECTDEP positivos; DEBIT/FEE/SRVCHG/POS/ATM/PAYMENT/
     * CHECK/CASH/DIRECTDEBIT/REPEATPMT negativos; os demais mantêm o sinal.
     */
    case BY_TYPE = 'by_type';
}
