<?php

namespace Puzl\PzOfx\Tests\Fixtures\Legacy;

// Helper usado pelo leitor legado.
class General
{
    public static function unformatMoney($money, $no_final_point_before_comma = false): float
    {
       if (! $money) {
            return 0.00;
        }
        if (! str_contains($money, ',')) {
            return (float) $money;
        }
        if($no_final_point_before_comma) {
            return str_contains($money, '.')
                ? (float) str_replace(',', '', $money)
                : (float) str_replace(',', '.', $money);
        }
        $money = str_replace(['R$ ', '.'], '', $money);
        return (float) str_replace(',', '.', $money);
    }
}
