<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Enums;

use Puzl\PzOfx\Support\Messages;

/** Desvios que não impedem a leitura. */
enum PzOfxWarningEnum: string
{
    /** Vira `OTHER`. */
    case UNKNOWN_TRANSACTION_TYPE = 'unknown_transaction_type';
    /** O identificador passa a ser o fingerprint. */
    case MISSING_FIT_ID = 'missing_fit_id';
    case DUPLICATE_FIT_ID = 'duplicate_fit_id';
    /** Linha "SALDO ANTERIOR"/"SALDO DO DIA" ignorada. */
    case BALANCE_ROW_SKIPPED = 'balance_row_skipped';
    /** Sem `<DTPOSTED>`: data de `<DTUSER>` ou do prefixo do FITID. */
    case DATE_FALLBACK = 'date_fallback';
    /** Só com `strict` desligado. */
    case TRANSACTION_SKIPPED = 'transaction_skipped';
    case STATUS_NOT_OK = 'status_not_ok';
    /** DTSTART depois de DTEND; as datas foram trocadas. */
    case PERIOD_INVERTED = 'period_inverted';
    /** DTSTART, DTEND, saldo… ilegível; ignorado. */
    case INVALID_OPTIONAL_FIELD = 'invalid_optional_field';

    /**
     * @param array<string, scalar|null> $params
     */
    public function message(array $params = [], ?string $locale = null): string
    {
        return Messages::get('warning.'.$this->value, $params, $locale);
    }
}
