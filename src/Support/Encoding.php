<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Support;

use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use Puzl\PzOfx\Exceptions\PzOfxParseException;
use ValueError;

/**
 * Leva o arquivo para UTF-8. O cabeçalho não é confiável: UTF-8 válido fica
 * como está, bytes soltos são reparados e o resto é lido como Windows-1252.
 *
 * @internal
 */
final class Encoding
{
    private const WINDOWS_1252_ALIASES = [
        '', 'NONE', 'USASCII', 'US-ASCII', 'ASCII', '1252', 'CP1252', 'WINDOWS-1252',
        'ISO-8859-1', 'ISO8859-1', '8859-1', 'LATIN1', 'LATIN-1', 'UTF-8', 'UTF8',
    ];

    /** Antes de ler o cabeçalho: remove BOM e converte UTF-16. */
    public static function normalizeBom(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return substr($bytes, 3);
        }
        if (str_starts_with($bytes, "\xFF\xFE")) {
            return mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($bytes, "\xFE\xFF")) {
            return mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        }

        // UTF-16 sem BOM: texto ASCII intercalado com NUL.
        $sample = substr($bytes, 0, 64);
        if (strlen($sample) >= 8 && substr_count($sample, "\0") >= strlen($sample) / 3) {
            return mb_convert_encoding($bytes, 'UTF-8', $sample[0] === "\0" ? 'UTF-16BE' : 'UTF-16LE');
        }

        return $bytes;
    }

    /**
     * @param string|null $charset Charset declarado no cabeçalho (`CHARSET` ou `encoding` do `<?xml?>`)
     * @param string|null $forced  Encoding da config; `null` = detectar
     */
    public static function toUtf8(string $bytes, ?string $charset, ?string $forced): string
    {
        if ($forced !== null && $forced !== '') {
            return self::clean(self::convert($bytes, $forced));
        }

        if (mb_check_encoding($bytes, 'UTF-8')) {
            return self::clean($bytes);
        }

        $repaired = self::repairMixed($bytes);
        if ($repaired !== null) {
            return self::clean($repaired);
        }

        $charset = strtoupper(trim((string) $charset));
        $source = in_array($charset, self::WINDOWS_1252_ALIASES, true) ? 'Windows-1252' : $charset;

        try {
            return self::clean(self::convert($bytes, $source));
        } catch (PzOfxParseException) {
            return self::clean(self::convert($bytes, 'Windows-1252'));
        }
    }

    /** UTF-8 com bytes soltos: converte só eles. `null` se não parecer UTF-8. */
    private static function repairMixed(string $bytes): ?string
    {
        $valid = 0;
        $invalid = 0;
        $repaired = preg_replace_callback(
            '/[\x00-\x7F]++|([\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})|(.)/s',
            static function (array $m) use (&$valid, &$invalid): string {
                if (isset($m[2])) {
                    $invalid++;

                    return mb_convert_encoding($m[2], 'UTF-8', 'Windows-1252');
                }
                if (($m[1] ?? '') !== '') {
                    $valid++;
                }

                return $m[0];
            },
            $bytes,
        );

        return $repaired !== null && $valid > 0 && $valid >= $invalid ? $repaired : null;
    }

    private static function convert(string $bytes, string $from): string
    {
        if (strtoupper($from) === 'UTF-8' || strtoupper($from) === 'UTF8') {
            // Sequências quebradas viram U+FFFD.
            return mb_scrub($bytes, 'UTF-8');
        }

        try {
            $converted = mb_convert_encoding($bytes, 'UTF-8', $from);
        } catch (ValueError $e) {
            throw PzOfxParseException::because(PzOfxErrorEnum::UNSUPPORTED_ENCODING, ['encoding' => $from], $e);
        }

        return $converted;
    }

    /** Remove caracteres de controle, exceto tab e quebras. */
    private static function clean(string $text): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? $text;
    }
}
