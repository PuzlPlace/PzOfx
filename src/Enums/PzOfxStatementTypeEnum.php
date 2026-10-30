<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Enums;

enum PzOfxStatementTypeEnum: string
{
    case BANK = 'bank';
    case CREDIT_CARD = 'credit_card';
}
