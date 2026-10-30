<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Enums;

/** De onde vem a descrição: cada banco usa NAME e MEMO de um jeito. */
enum PzOfxDescriptionEnum: string
{
    case MEMO = 'memo';
    /** Inclui `<PAYEE><NAME>`. */
    case NAME = 'name';
    /** Padrão. */
    case MEMO_OR_NAME = 'memo_or_name';
    case NAME_OR_MEMO = 'name_or_memo';
    /** `NAME - MEMO`, sem repetir quando um contém o outro. */
    case NAME_AND_MEMO = 'name_and_memo';
}
