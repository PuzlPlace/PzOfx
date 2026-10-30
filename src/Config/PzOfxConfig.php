<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Config;

use DateTimeZone;
use Exception;
use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use Puzl\PzOfx\Enums\PzOfxStatementTypeEnum;
use Puzl\PzOfx\Enums\PzOfxTimezoneModeEnum;
use Puzl\PzOfx\Exceptions\PzOfxConfigException;

/** Configuração imutável da leitura. Chave ausente cai no padrão; valor inválido falha cedo. */
final class PzOfxConfig
{
    /** ≈ 85 mil transações; a leitura usa até ~35× o tamanho em memória. */
    public const DEFAULT_MAX_FILE_SIZE = 10 * 1024 * 1024;

    /**
     * "SALDO" seguido só de qualificadores, comparado sem acento com o texto inteiro.
     * "SALDO APLIC AUT" e "JUROS SALDO DEVEDOR" são transações e não casam.
     */
    public const DEFAULT_BALANCE_ROW_PATTERN = '/^S\s?A\s?L\s?D\s?O(\s+(ANTERIOR|TOTAL|DISPONI?VEL|BLOQUEADO|DO|DIA|FINAL|ATUAL|INICIAL|EM|C\/?C|CONTA|CORRENTE))*$/';

    /**
     * Bancos cujo FITID não identifica a transação: Caixa manda a data, Unicred
     * o tipo (`DEB PIX`). `'profiles' => []` desliga.
     */
    public const KNOWN_PROFILES = [
        ['match' => ['bank_id' => '104'], 'trust_fit_id' => false],
        ['match' => ['bank_id' => '136'], 'trust_fit_id' => false],
    ];

    /**
     * @param list<PzOfxProfile> $profiles
     */
    private function __construct(
        /** `false`: transação ilegível vira aviso em vez de exceção. */
        public readonly bool $strict,
        /** `null` = fuso padrão do PHP. */
        public readonly ?string $timezone,
        public readonly PzOfxTimezoneModeEnum $timezoneMode,
        /** Bytes; `0` desliga. */
        public readonly int $maxFileSize,
        /** `null` = detectar. */
        public readonly ?string $encoding,
        /** `false`: DTSTART depois de DTEND vira exceção em vez de ser invertido com aviso. */
        public readonly bool $fixInvertedPeriod,
        public readonly string $balanceRowPattern,
        public readonly PzOfxProfile $defaults,
        public readonly array $profiles,
    ) {
    }

    public static function default(): self
    {
        return self::fromArray([]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $timezone = $data['timezone'] ?? null;
        if ($timezone !== null) {
            $timezone = (string) $timezone;
            try {
                new DateTimeZone($timezone);
            } catch (Exception) {
                throw self::invalid(sprintf('timezone "%s" does not exist', $timezone));
            }
        }

        $mode = $data['timezone_mode'] ?? PzOfxTimezoneModeEnum::LOCAL;
        if (! $mode instanceof PzOfxTimezoneModeEnum) {
            $mode = PzOfxTimezoneModeEnum::tryFrom(strtolower(trim((string) $mode)))
                ?? throw self::invalid('"timezone_mode" must be "local" or "convert"');
        }

        $maxFileSize = $data['max_file_size'] ?? self::DEFAULT_MAX_FILE_SIZE;
        if (! is_int($maxFileSize) || $maxFileSize < 0) {
            throw self::invalid('"max_file_size" must be an integer >= 0 (bytes; 0 = no limit)');
        }

        $pattern = (string) ($data['balance_row_pattern'] ?? self::DEFAULT_BALANCE_ROW_PATTERN);
        if (@preg_match($pattern, '') === false) {
            throw self::invalid('"balance_row_pattern" is not a valid regular expression');
        }

        $defaults = $data['defaults'] ?? [];
        if (! is_array($defaults)) {
            throw self::invalid('"defaults" must be an array');
        }

        $profiles = array_key_exists('profiles', $data) ? $data['profiles'] : self::KNOWN_PROFILES;
        if (! is_array($profiles) && $profiles !== null) {
            throw self::invalid('"profiles" must be a list of profiles');
        }

        $encoding = $data['encoding'] ?? null;

        return new self(
            strict: (bool) ($data['strict'] ?? true),
            timezone: $timezone,
            timezoneMode: $mode,
            maxFileSize: $maxFileSize,
            encoding: $encoding === null || $encoding === '' ? null : (string) $encoding,
            fixInvertedPeriod: (bool) ($data['fix_inverted_period'] ?? true),
            balanceRowPattern: $pattern,
            defaults: PzOfxProfile::fromArray($defaults, true)->over(PzOfxProfile::defaults()),
            profiles: array_values(array_map(
                static fn (mixed $profile): PzOfxProfile => is_array($profile)
                    ? PzOfxProfile::fromArray($profile)
                    : throw self::invalid('each profile must be an array'),
                $profiles ?? [],
            )),
        );
    }

    /**
     * `defaults` é mesclado; `profiles` substitui a lista.
     *
     * @param array<string, mixed> $overrides
     */
    public function with(array $overrides): self
    {
        $data = $this->toArray();
        foreach ($overrides as $key => $value) {
            $data[$key] = $key === 'defaults' && is_array($value) ? array_replace($data['defaults'], $value) : $value;
        }

        return self::fromArray($data);
    }

    public function dateTimeZone(): DateTimeZone
    {
        return new DateTimeZone($this->timezone ?? date_default_timezone_get());
    }

    /** Primeiro perfil que bate, sobre o padrão. */
    public function profileFor(?string $bankId, ?string $org, ?string $fid, PzOfxStatementTypeEnum $statement): PzOfxProfile
    {
        foreach ($this->profiles as $profile) {
            if ($profile->matches($bankId, $org, $fid, $statement)) {
                return $profile->over($this->defaults);
            }
        }

        return $this->defaults;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'strict' => $this->strict,
            'timezone' => $this->timezone,
            'timezone_mode' => $this->timezoneMode->value,
            'max_file_size' => $this->maxFileSize,
            'encoding' => $this->encoding,
            'fix_inverted_period' => $this->fixInvertedPeriod,
            'balance_row_pattern' => $this->balanceRowPattern,
            'defaults' => $this->defaults->toArray(),
            'profiles' => array_map(static fn (PzOfxProfile $profile): array => $profile->toArray(), $this->profiles),
        ];
    }

    private static function invalid(string $reason): PzOfxConfigException
    {
        return PzOfxConfigException::because(PzOfxErrorEnum::INVALID_CONFIG, ['reason' => $reason]);
    }
}
