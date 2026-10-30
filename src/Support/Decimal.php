<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Support;

/**
 * Valores como string decimal exata: `"-1234.56"`, no mínimo 2 casas.
 * Aritmética em string, sem float nem limite de int.
 *
 * @internal
 */
final class Decimal
{
    /** Acima disso não é valor de extrato. */
    private const MAX_INTEGER_DIGITS = 15;

    private const MAX_FRACTION_DIGITS = 8;

    /**
     * Com `.` e `,`, o último é o decimal; com um só tipo, uma ocorrência é decimal
     * (a especificação aceita vírgula) e várias são milhar. `null` se não for número.
     */
    public static function parse(string $raw): ?string
    {
        $value = preg_replace('/[\s\x{00A0}]+/u', '', $raw) ?? $raw;
        $value = str_ireplace(['R$', 'US$', 'BRL', '$'], '', $value);
        if ($value === '') {
            return null;
        }

        $negative = false;
        if (str_starts_with($value, '(') && str_ends_with($value, ')')) {
            $negative = true;
            $value = substr($value, 1, -1);
        }
        if (str_ends_with($value, '-') || str_ends_with($value, '+')) {
            $negative = ($negative xor str_ends_with($value, '-'));
            $value = substr($value, 0, -1);
        }
        if (str_starts_with($value, '-') || str_starts_with($value, '+')) {
            $negative = ($negative xor str_starts_with($value, '-'));
            $value = substr($value, 1);
        }

        if (! preg_match('/^[0-9.,]+$/', $value) || ! preg_match('/\d/', $value)) {
            return null;
        }

        $lastDot = strrpos($value, '.');
        $lastComma = strrpos($value, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $decimal = $lastDot > $lastComma ? '.' : ',';
        } elseif ($lastDot !== false) {
            $decimal = substr_count($value, '.') === 1 ? '.' : null;
        } elseif ($lastComma !== false) {
            $decimal = substr_count($value, ',') === 1 ? ',' : null;
        } else {
            $decimal = null;
        }

        if ($decimal === null) {
            $integer = str_replace(['.', ','], '', $value);
            $fraction = '';
        } else {
            $position = strrpos($value, $decimal);
            $thousands = $decimal === '.' ? ',' : '.';
            $integer = str_replace($thousands, '', substr($value, 0, $position));
            $fraction = substr($value, $position + 1);
        }

        if (! ctype_digit($integer.$fraction)) {
            return null;
        }
        if (strlen($fraction) > self::MAX_FRACTION_DIGITS) {
            $fraction = rtrim($fraction, '0');
        }
        if (strlen(ltrim($integer, '0')) > self::MAX_INTEGER_DIGITS || strlen($fraction) > self::MAX_FRACTION_DIGITS) {
            return null;
        }

        return self::build($negative, $integer, $fraction);
    }

    public static function negate(string $amount): string
    {
        if (self::isZero($amount)) {
            return $amount;
        }

        return str_starts_with($amount, '-') ? substr($amount, 1) : '-'.$amount;
    }

    public static function abs(string $amount): string
    {
        return ltrim($amount, '-');
    }

    public static function isNegative(string $amount): bool
    {
        return str_starts_with($amount, '-');
    }

    public static function isZero(string $amount): bool
    {
        return trim(str_replace(['-', '.'], '', $amount), '0') === '';
    }

    public static function sum(string ...$amounts): string
    {
        $total = '0.00';
        foreach ($amounts as $amount) {
            $total = self::add($total, $amount);
        }

        return $total;
    }

    /** -1, 0 ou 1. */
    public static function compare(string $a, string $b): int
    {
        $difference = self::add($a, self::negate($b));

        return self::isZero($difference) ? 0 : (self::isNegative($difference) ? -1 : 1);
    }

    /** Arredonda metade para longe do zero. */
    public static function toMinor(string $amount, int $scale = 2): int
    {
        $negative = self::isNegative($amount);
        [$integer, $fraction] = explode('.', self::abs($amount).'.');
        $kept = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $minor = (int) ($integer.$kept);
        if (strlen($fraction) > $scale && (int) $fraction[$scale] >= 5) {
            $minor++;
        }

        return $negative ? -$minor : $minor;
    }

    private static function build(bool $negative, string $integer, string $fraction): string
    {
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = str_pad($fraction, 2, '0');
        $result = $integer.'.'.$fraction;

        return $negative && ! self::isZero($result) ? '-'.$result : $result;
    }

    private static function add(string $a, string $b): string
    {
        [$integerA, $fractionA] = explode('.', self::abs($a).'.');
        [$integerB, $fractionB] = explode('.', self::abs($b).'.');
        $scale = max(strlen($fractionA), strlen($fractionB));
        $width = max(strlen($integerA), strlen($integerB)) + $scale;
        $x = str_pad($integerA.str_pad($fractionA, $scale, '0'), $width, '0', STR_PAD_LEFT);
        $y = str_pad($integerB.str_pad($fractionB, $scale, '0'), $width, '0', STR_PAD_LEFT);

        if (self::isNegative($a) === self::isNegative($b)) {
            [$digits, $negative] = [self::addDigits($x, $y), self::isNegative($a)];
        } elseif (strcmp($x, $y) >= 0) {
            [$digits, $negative] = [self::subtractDigits($x, $y), self::isNegative($a)];
        } else {
            [$digits, $negative] = [self::subtractDigits($y, $x), self::isNegative($b)];
        }

        $integer = $scale === 0 ? $digits : substr($digits, 0, -$scale);

        return self::build($negative, $integer, $scale === 0 ? '' : substr($digits, -$scale));
    }

    /** Naturais em string de mesmo tamanho. */
    private static function addDigits(string $x, string $y): string
    {
        $result = '';
        $carry = 0;
        for ($i = strlen($x) - 1; $i >= 0; $i--) {
            $sum = (int) $x[$i] + (int) $y[$i] + $carry;
            $result = ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return ($carry > 0 ? (string) $carry : '').$result;
    }

    /** `$x - $y`, com `$x >= $y` e mesmo tamanho. */
    private static function subtractDigits(string $x, string $y): string
    {
        $result = '';
        $borrow = 0;
        for ($i = strlen($x) - 1; $i >= 0; $i--) {
            $difference = (int) $x[$i] - (int) $y[$i] - $borrow;
            $borrow = $difference < 0 ? 1 : 0;
            $result = ($difference + 10 * $borrow).$result;
        }

        return $result;
    }
}
