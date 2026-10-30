<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Enums;

enum PzOfxTimezoneModeEnum: string
{
    /**
     * Padrão: data e hora como no arquivo, ignorando o offset — converter
     * pode mudar o dia do lançamento.
     */
    case LOCAL = 'local';

    /** Aplica o offset; sem offset, a data já é do fuso configurado. */
    case CONVERT = 'convert';
}
