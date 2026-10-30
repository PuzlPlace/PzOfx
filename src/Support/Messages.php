<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Support;

use Closure;

/**
 * Textos de erros e avisos. Idioma sem tradução cai no inglês.
 *
 * @internal
 */
final class Messages
{
    public const DEFAULT_LOCALE = 'en';

    /** @var array<string, array<string, string>> */
    public const LOCALES = [
        'en' => [
            'error.file_not_informed' => 'No OFX file was informed.',
            'error.file_not_found' => 'OFX file not found: :path.',
            'error.file_not_readable' => 'The OFX file could not be read: :path.',
            'error.file_too_large' => 'The OFX file has :size bytes, above the limit of :max bytes.',
            'error.file_empty' => 'The OFX file is empty.',
            'error.not_ofx' => 'The file is not a valid OFX: the <OFX> element was not found.',
            'error.unsupported_encoding' => 'Unsupported encoding ":encoding".',
            'error.missing_field' => 'Transaction :index has no :field.',
            'error.invalid_date' => 'Transaction :index has an invalid :field: ":value".',
            'error.invalid_amount' => 'Transaction :index has an invalid :field: ":value".',
            'error.no_statement' => 'The OFX file has no bank or credit card statement.',
            'error.inverted_period' => 'The statement period is inverted: DTSTART :start is after DTEND :end.',
            'error.invalid_config' => 'Invalid PzOfx configuration: :reason.',
            'error.no_transactions' => 'The OFX file has no transactions.',
            'warning.unknown_transaction_type' => 'Transaction :index has an unknown TRNTYPE ":value"; treated as OTHER.',
            'warning.missing_fit_id' => ':count transaction(s) without FITID; the fingerprint is used as identifier.',
            'warning.duplicate_fit_id' => 'FITID ":value" appears in :count transactions; the fingerprint is used as identifier.',
            'warning.balance_row_skipped' => 'Transaction :index skipped: balance row ":value", not a transaction.',
            'warning.date_fallback' => 'Transaction :index has no DTPOSTED; date taken from :field (":value").',
            'warning.transaction_skipped' => 'Transaction :index skipped: :reason',
            'warning.status_not_ok' => 'The bank returned status :code (:severity) in :field: :value',
            'warning.invalid_optional_field' => 'Ignored invalid :field: ":value".',
            'warning.period_inverted' => 'DTSTART :start is after DTEND :end; the dates were swapped.',
        ],
        'pt_BR' => [
            'error.file_not_informed' => 'Nenhum arquivo OFX foi informado.',
            'error.file_not_found' => 'Arquivo OFX não encontrado: :path.',
            'error.file_not_readable' => 'Não foi possível ler o arquivo OFX: :path.',
            'error.file_too_large' => 'O arquivo OFX tem :size bytes, acima do limite de :max bytes.',
            'error.file_empty' => 'O arquivo OFX está vazio.',
            'error.not_ofx' => 'O arquivo não é um OFX válido: o elemento <OFX> não foi encontrado.',
            'error.unsupported_encoding' => 'Codificação ":encoding" não suportada.',
            'error.missing_field' => 'A transação :index não tem :field.',
            'error.invalid_date' => 'A transação :index tem :field inválido: ":value".',
            'error.invalid_amount' => 'A transação :index tem :field inválido: ":value".',
            'error.no_statement' => 'O arquivo OFX não tem extrato de conta ou de cartão.',
            'error.inverted_period' => 'O período do extrato está invertido: DTSTART :start é depois de DTEND :end.',
            'error.invalid_config' => 'Configuração do PzOfx inválida: :reason.',
            'error.no_transactions' => 'O arquivo OFX não tem transações.',
            'warning.unknown_transaction_type' => 'A transação :index tem TRNTYPE desconhecido ":value"; tratada como OTHER.',
            'warning.missing_fit_id' => ':count transação(ões) sem FITID; o fingerprint é usado como identificador.',
            'warning.duplicate_fit_id' => 'O FITID ":value" aparece em :count transações; o fingerprint é usado como identificador.',
            'warning.balance_row_skipped' => 'Transação :index ignorada: linha de saldo ":value", não é transação.',
            'warning.date_fallback' => 'A transação :index não tem DTPOSTED; data obtida de :field (":value").',
            'warning.transaction_skipped' => 'Transação :index ignorada: :reason',
            'warning.status_not_ok' => 'O banco retornou status :code (:severity) em :field: :value',
            'warning.invalid_optional_field' => ':field inválido ignorado: ":value".',
            'warning.period_inverted' => 'DTSTART :start é depois de DTEND :end; as datas foram trocadas.',
        ],
    ];

    /** @var string|Closure(): ?string */
    private static string|Closure $locale = self::DEFAULT_LOCALE;

    /**
     * @param string|Closure(): ?string $locale
     */
    public static function useLocale(string|Closure $locale): void
    {
        self::$locale = $locale;
    }

    public static function locale(): string
    {
        $locale = self::$locale instanceof Closure ? (self::$locale)() : self::$locale;

        return self::normalize((string) $locale);
    }

    /**
     * @param array<string, scalar|null> $params
     */
    public static function get(string $key, array $params = [], ?string $locale = null): string
    {
        $locale = $locale === null ? self::locale() : self::normalize($locale);
        $text = self::LOCALES[$locale][$key] ?? self::LOCALES[self::DEFAULT_LOCALE][$key] ?? $key;

        // Uma passada só: valor com ":index" dentro não é substituído de novo.
        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements[':'.$name] = (string) $value;
        }

        return strtr($text, $replacements);
    }

    /** `pt-BR`, `pt` → `pt_BR`; desconhecido → `en`. */
    private static function normalize(string $locale): string
    {
        $locale = str_replace('-', '_', trim($locale));
        if (isset(self::LOCALES[$locale])) {
            return $locale;
        }

        return match (strtolower(substr($locale, 0, 2))) {
            'pt' => 'pt_BR',
            default => self::DEFAULT_LOCALE,
        };
    }
}
