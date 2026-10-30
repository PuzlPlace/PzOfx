<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Config;

use BackedEnum;
use Puzl\PzOfx\Enums\PzOfxAmountSignEnum;
use Puzl\PzOfx\Enums\PzOfxDescriptionEnum;
use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use Puzl\PzOfx\Enums\PzOfxStatementTypeEnum;
use Puzl\PzOfx\Exceptions\PzOfxConfigException;
use Puzl\PzOfx\Support\BankCode;
use Puzl\PzOfx\Support\Text;

/**
 * Regras de leitura de um banco (descrição, sinal, FITID, linhas de saldo).
 * O primeiro perfil cujo `match` bate é aplicado sobre o padrão.
 */
final class PzOfxProfile
{
    private const MATCH_KEYS = ['bank_id', 'org', 'fid', 'statement'];

    private const OPTION_KEYS = ['description', 'amount_sign', 'trust_fit_id', 'skip_balance_rows'];

    /**
     * @param array<string, list<string>> $match
     */
    private function __construct(
        public readonly array $match,
        public readonly ?PzOfxDescriptionEnum $description,
        public readonly ?PzOfxAmountSignEnum $amountSign,
        public readonly ?bool $trustFitId,
        public readonly ?bool $skipBalanceRows,
    ) {
    }

    public static function defaults(): self
    {
        return new self([], PzOfxDescriptionEnum::MEMO_OR_NAME, PzOfxAmountSignEnum::AS_IS, true, true);
    }

    /**
     * @param array<string, mixed> $data
     * @param bool $isDefault perfil padrão não tem `match`; perfil específico precisa ter
     */
    public static function fromArray(array $data, bool $isDefault = false): self
    {
        $unknown = array_diff(array_keys($data), [...self::OPTION_KEYS, ...($isDefault ? [] : ['match'])]);
        if ($unknown !== []) {
            throw self::invalid('unknown profile option(s) '.implode(', ', $unknown));
        }

        $match = $isDefault ? [] : self::parseMatch($data['match'] ?? null);

        return new self(
            $match,
            self::toEnum(PzOfxDescriptionEnum::class, $data['description'] ?? null, 'description'),
            self::toEnum(PzOfxAmountSignEnum::class, $data['amount_sign'] ?? null, 'amount_sign'),
            self::toBool($data['trust_fit_id'] ?? null, 'trust_fit_id'),
            self::toBool($data['skip_balance_rows'] ?? null, 'skip_balance_rows'),
        );
    }

    /** O que não foi definido aqui vem de `$base`. */
    public function over(self $base): self
    {
        return new self(
            $this->match,
            $this->description ?? $base->description,
            $this->amountSign ?? $base->amountSign,
            $this->trustFitId ?? $base->trustFitId,
            $this->skipBalanceRows ?? $base->skipBalanceRows,
        );
    }

    /** Todos os critérios precisam bater; numa lista, basta um valor. */
    public function matches(?string $bankId, ?string $org, ?string $fid, PzOfxStatementTypeEnum $statement): bool
    {
        if ($this->match === []) {
            return false;
        }

        foreach ($this->match as $key => $values) {
            $ok = match ($key) {
                'bank_id' => self::matchesBankId($bankId, $values),
                'org' => $org !== null && in_array(Text::comparable($org), array_map(Text::comparable(...), $values), true),
                'fid' => $fid !== null && in_array(trim($fid), $values, true),
                'statement' => in_array($statement->value, $values, true),
                default => false,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'match' => $this->match === [] ? null : $this->match,
            'description' => $this->description?->value,
            'amount_sign' => $this->amountSign?->value,
            'trust_fit_id' => $this->trustFitId,
            'skip_balance_rows' => $this->skipBalanceRows,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param list<string> $values
     */
    private static function matchesBankId(?string $bankId, array $values): bool
    {
        if ($bankId === null) {
            return false;
        }
        $code = BankCode::normalize($bankId);
        foreach ($values as $value) {
            if (trim($bankId) === $value || ($code !== null && $code === BankCode::normalize($value))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function parseMatch(mixed $match): array
    {
        if (! is_array($match) || $match === []) {
            throw self::invalid('every profile needs a non-empty "match" (bank_id, org, fid and/or statement)');
        }

        $unknown = array_diff(array_keys($match), self::MATCH_KEYS);
        if ($unknown !== []) {
            throw self::invalid('unknown match key(s) '.implode(', ', $unknown));
        }

        $parsed = [];
        foreach ($match as $key => $values) {
            $values = array_values(array_map(
                static fn (mixed $value): string => trim($value instanceof BackedEnum ? (string) $value->value : (string) $value),
                is_array($values) ? $values : [$values],
            ));
            if ($key === 'statement') {
                foreach ($values as $value) {
                    if (PzOfxStatementTypeEnum::tryFrom($value) === null) {
                        throw self::invalid('match.statement must be "bank" or "credit_card", got "'.$value.'"');
                    }
                }
            }
            $parsed[$key] = $values;
        }

        return $parsed;
    }

    /**
     * @template T of BackedEnum
     *
     * @param class-string<T> $enum
     * @return T|null
     */
    private static function toEnum(string $enum, mixed $value, string $key): ?BackedEnum
    {
        if ($value === null || $value instanceof $enum) {
            return $value;
        }

        $case = is_string($value) ? $enum::tryFrom(strtolower(trim($value))) : null;
        if ($case === null) {
            $allowed = implode(', ', array_map(static fn (BackedEnum $c): string => (string) $c->value, $enum::cases()));
            throw self::invalid(sprintf('"%s" must be one of: %s', $key, $allowed));
        }

        return $case;
    }

    private static function toBool(mixed $value, string $key): ?bool
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }

        throw self::invalid(sprintf('"%s" must be true or false', $key));
    }

    private static function invalid(string $reason): PzOfxConfigException
    {
        return PzOfxConfigException::because(PzOfxErrorEnum::INVALID_CONFIG, ['reason' => $reason]);
    }
}
