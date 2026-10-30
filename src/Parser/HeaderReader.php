<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Parser;

use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use Puzl\PzOfx\Exceptions\PzOfxParseException;
use Puzl\PzOfx\Data\PzOfxHeader;

/**
 * Separa cabeçalho e corpo; o corpo começa no primeiro `<OFX>`. Cabeçalho é opcional.
 *
 * @internal
 */
final class HeaderReader
{
    /**
     * @return array{0: PzOfxHeader, 1: string} cabeçalho e corpo (a partir de `<OFX`)
     */
    public static function split(string $content): array
    {
        if (! preg_match('/<OFX\b[^<>]*>/i', $content, $match, PREG_OFFSET_CAPTURE)) {
            throw PzOfxParseException::because(PzOfxErrorEnum::NOT_OFX);
        }

        $position = $match[0][1];
        $head = substr($content, 0, $position);

        return [self::parse($head), substr($content, $position)];
    }

    private static function parse(string $head): PzOfxHeader
    {
        $fields = [];

        if (preg_match('/<\?OFX\b(.*?)\?>/is', $head, $pi)) {
            $fields = self::attributes($pi[1]);
            if (preg_match('/<\?xml\b(.*?)\?>/is', $head, $xml)) {
                $encoding = self::attributes($xml[1])['ENCODING'] ?? null;
                if ($encoding !== null) {
                    $fields['CHARSET'] ??= $encoding;
                }
            }

            return new PzOfxHeader($fields, true);
        }

        if (preg_match_all('/([A-Za-z]+)[ \t]*:[ \t]*([^\s<]*)/', $head, $pairs, PREG_SET_ORDER)) {
            foreach ($pairs as [, $key, $value]) {
                $fields[strtoupper($key)] ??= $value;
            }
        }

        $isXml = (bool) preg_match('/<\?xml\b/i', $head);

        return new PzOfxHeader($fields, $isXml);
    }

    /**
     * @return array<string, string>
     */
    private static function attributes(string $source): array
    {
        $attributes = [];
        preg_match_all('/([A-Za-z_][\w.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $source, $pairs, PREG_SET_ORDER);
        foreach ($pairs as $pair) {
            $attributes[strtoupper($pair[1])] = ($pair[3] ?? '') !== '' ? $pair[3] : $pair[2];
        }

        return $attributes;
    }
}
