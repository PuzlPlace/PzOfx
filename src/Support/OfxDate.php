<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Support;

use DateTimeImmutable;
use DateTimeZone;
use Puzl\PzOfx\Enums\PzOfxTimezoneModeEnum;

/**
 * Data OFX `YYYYMMDD[HHMM[SS[.XXX]]][[±H[.MM]][:TZ]]`. O nome do fuso é ignorado
 * (bancos mandam `[-03:EST]`). Data impossível devolve `null`.
 *
 * @internal
 */
final class OfxDate
{
    private const PATTERN = '/^(\d{4})(\d{2})(\d{2})(?:(\d{2})(\d{2})?(\d{2})?)?(?:[.,]\d{1,6})?$/';

    private const OFFSET_PATTERN = '/^([+-]?)(\d{1,2})(?:[.,](\d{1,2}))?$/';

    public static function parse(?string $raw, DateTimeZone $timezone, PzOfxTimezoneModeEnum $mode): ?DateTimeImmutable
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $offset = null;
        $bracket = strpos($raw, '[');
        if ($bracket !== false) {
            $offset = self::offset(substr($raw, $bracket + 1));
            $raw = substr($raw, 0, $bracket);
        }

        $main = preg_replace('/[-:T\s\/]/', '', $raw) ?? '';
        if (! preg_match(self::PATTERN, $main, $m)) {
            return null;
        }

        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        $hour = (int) ($m[4] ?? 0);
        $minute = (int) ($m[5] ?? 0);
        $second = (int) ($m[6] ?? 0);

        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 60) {
            return null;
        }
        $second = min($second, 59); // 60 = segundo bissexto

        $local = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);

        if ($mode === PzOfxTimezoneModeEnum::CONVERT && $offset !== null) {
            return (new DateTimeImmutable($local, new DateTimeZone($offset)))->setTimezone($timezone);
        }

        return new DateTimeImmutable($local, $timezone);
    }

    /** Fração em minutos (`.30`), exceto as decimais `.5`, `.25`, `.75`. */
    private static function offset(string $content): ?string
    {
        $content = trim(explode(']', $content, 2)[0]);
        $number = trim(explode(':', $content, 2)[0]);
        if (! preg_match(self::OFFSET_PATTERN, $number, $m)) {
            return null;
        }

        $hours = (int) $m[2];
        $fraction = $m[3] ?? '';
        $minutes = match ($fraction) {
            '' => 0,
            '5', '50' => 30,
            '25' => 15,
            '75' => 45,
            default => (int) $fraction,
        };
        if ($hours > 14 || $minutes > 59) {
            return null;
        }

        return sprintf('%s%02d:%02d', $m[1] === '-' ? '-' : '+', $hours, $minutes);
    }
}
