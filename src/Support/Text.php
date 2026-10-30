<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Support;

/**
 * @internal
 */
final class Text
{
    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C', 'Ñ' => 'N', 'Ý' => 'Y',
    ];

    /** `&` solto (`AGUA & ESGOTO`) fica como está. */
    public static function decodeEntities(string $value): string
    {
        return str_contains($value, '&')
            ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : $value;
    }

    /** Colapsa espaços, inclusive NBSP. */
    public static function squish(string $value): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $value) ?? $value);
    }

    public static function nullIfBlank(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = self::squish($value);

        return $value === '' ? null : $value;
    }

    /** Sem acento, maiúscula, espaços colapsados. */
    public static function comparable(string $value): string
    {
        return mb_strtoupper(self::squish(strtr($value, self::ACCENTS)), 'UTF-8');
    }
}
