<?php

declare(strict_types=1);

// Toda chave é opcional: o que faltar cai no padrão.
// Publicar: php artisan vendor:publish --tag=pzofx-config
// Ajuste numa leitura só: PzOfx::fromFile($arquivo, ['strict' => false]);

return [
    // false: transação sem data/valor legível vira aviso em vez de exceção.
    'strict' => true,

    // null = fuso da aplicação.
    'timezone' => null,

    // 'local': data e hora como no arquivo (o dia do banco).
    // 'convert': aplica o offset do arquivo — pode mudar o dia do lançamento.
    'timezone_mode' => 'local',

    // Bytes; 0 desliga. A leitura usa até ~35× o tamanho em memória.
    'max_file_size' => 10 * 1024 * 1024,

    // null = detectar. Force (ex.: 'ISO-8859-15') só se um banco vier com acento errado.
    'encoding' => null,

    // false: DTSTART depois de DTEND vira exceção em vez de ser invertido com aviso.
    'fix_inverted_period' => true,

    // Linhas "SALDO ANTERIOR", "SALDO TOTAL DISPONÍVEL DIA"… que alguns bancos mandam
    // como transação. Compara MEMO (ou NAME) inteiro, sem acento e em maiúsculas.
    'balance_row_pattern' => '/^S\s?A\s?L\s?D\s?O(\s+(ANTERIOR|TOTAL|DISPONI?VEL|BLOQUEADO|DO|DIA|FINAL|ATUAL|INICIAL|EM|C\/?C|CONTA|CORRENTE))*$/',

    'defaults' => [
        // memo_or_name | name_or_memo | name_and_memo | memo | name
        'description' => 'memo_or_name',
        // as_is | invert | by_type (o TRNTYPE decide o sinal)
        'amount_sign' => 'as_is',
        // false: uniqueId é sempre o fingerprint.
        'trust_fit_id' => true,
        'skip_balance_rows' => true,
    ],

    // O primeiro que bate é aplicado sobre 'defaults'.
    // match: bank_id (código normalizado; em cartão, o FID), org, fid, statement (bank|credit_card).
    // Ex.: ['match' => ['bank_id' => ['260', '336'], 'statement' => 'credit_card'], 'amount_sign' => 'invert'],
    'profiles' => [
        // FITID que não identifica a transação: Caixa manda a data, Unicred o tipo.
        ['match' => ['bank_id' => '104'], 'trust_fit_id' => false],
        ['match' => ['bank_id' => '136'], 'trust_fit_id' => false],
    ],

    // 'pt_BR' | 'en'; null = idioma da aplicação.
    'locale' => null,
];
