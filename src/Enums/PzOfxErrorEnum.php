<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Enums;

use Puzl\PzOfx\Support\Messages;

/** Código estável do erro; a mensagem é traduzida, o código não muda. */
enum PzOfxErrorEnum: string
{
    case FILE_NOT_INFORMED = 'file_not_informed';
    case FILE_NOT_FOUND = 'file_not_found';
    case FILE_NOT_READABLE = 'file_not_readable';
    case FILE_TOO_LARGE = 'file_too_large';
    case FILE_EMPTY = 'file_empty';
    case NOT_OFX = 'not_ofx';
    case UNSUPPORTED_ENCODING = 'unsupported_encoding';
    case MISSING_FIELD = 'missing_field';
    case INVALID_DATE = 'invalid_date';
    case INVALID_AMOUNT = 'invalid_amount';
    case INVERTED_PERIOD = 'inverted_period';
    case NO_STATEMENT = 'no_statement';
    case NO_TRANSACTIONS = 'no_transactions';
    case INVALID_CONFIG = 'invalid_config';

    /**
     * @param array<string, scalar|null> $params
     */
    public function message(array $params = [], ?string $locale = null): string
    {
        return Messages::get('error.'.$this->value, $params, $locale);
    }
}
