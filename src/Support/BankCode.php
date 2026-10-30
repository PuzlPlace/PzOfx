<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Support;

/**
 * Código COMPE (3 dígitos) a partir do `<BANKID>`; ISPB ou nome dão `null`.
 *
 * @internal
 */
final class BankCode
{
    public const NAMES = [
        '001' => 'Banco do Brasil',
        '003' => 'Banco da Amazônia',
        '004' => 'Banco do Nordeste',
        '021' => 'Banestes',
        '033' => 'Santander',
        '037' => 'Banpará',
        '041' => 'Banrisul',
        '047' => 'Banese',
        '070' => 'BRB',
        '077' => 'Inter',
        '085' => 'Ailos',
        '104' => 'Caixa Econômica Federal',
        '121' => 'Agibank',
        '133' => 'Cresol',
        '136' => 'Unicred',
        '197' => 'Stone',
        '208' => 'BTG Pactual',
        '212' => 'Banco Original',
        '218' => 'BS2',
        '237' => 'Bradesco',
        '260' => 'Nubank',
        '290' => 'PagBank',
        '323' => 'Mercado Pago',
        '336' => 'C6 Bank',
        '341' => 'Itaú',
        '364' => 'Efí',
        '380' => 'PicPay',
        '403' => 'Cora',
        '422' => 'Safra',
        '461' => 'Asaas',
        '623' => 'Banco Pan',
        '655' => 'Banco Votorantim',
        '707' => 'Daycoval',
        '745' => 'Citibank',
        '748' => 'Sicredi',
        '756' => 'Sicoob',
    ];

    public static function normalize(?string $bankId): ?string
    {
        $digits = preg_replace('/[\s.\-]/', '', (string) $bankId) ?? '';
        if (! ctype_digit($digits)) {
            return null;
        }

        $digits = ltrim($digits, '0');
        if ($digits === '' || strlen($digits) > 3) {
            return null;
        }

        return str_pad($digits, 3, '0', STR_PAD_LEFT);
    }

    public static function name(?string $code): ?string
    {
        return $code === null ? null : (self::NAMES[$code] ?? null);
    }
}
