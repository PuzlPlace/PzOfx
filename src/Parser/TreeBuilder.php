<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Parser;

use Generator;
use Puzl\PzOfx\Support\Text;

/**
 * Tokenizador próprio para OFX 1.x (SGML) e 2.x (XML), sem libxml.
 * Tag seguida de texto é folha; aggregate fecha implícito pelo ancestral.
 *
 * @internal
 */
final class TreeBuilder
{
    private const OPEN = 1;
    private const CLOSE = 2;
    private const TEXT = 3;

    private const MAX_DEPTH = 64;

    private const TAG = '/\G<(\/?)\s*([A-Za-z][A-Za-z0-9._:-]*)((?:\s+[A-Za-z_:][\w.:-]*\s*=\s*(?:"[^"]*"|\'[^\']*\'))*)\s*(\/?)>/';

    /** Abrem mesmo com texto solto depois. */
    private const STRUCTURAL = [
        'OFX' => true, 'SIGNONMSGSRSV1' => true, 'SONRS' => true, 'STATUS' => true, 'FI' => true,
        'BANKMSGSRSV1' => true, 'STMTTRNRS' => true, 'STMTRS' => true, 'BANKACCTFROM' => true,
        'BANKACCTTO' => true, 'BANKTRANLIST' => true, 'BANKTRANLISTP' => true, 'STMTTRN' => true,
        'STMTTRNP' => true, 'LEDGERBAL' => true, 'AVAILBAL' => true, 'BALLIST' => true, 'BAL' => true,
        'CREDITCARDMSGSRSV1' => true, 'CCSTMTTRNRS' => true, 'CCSTMTRS' => true, 'CCACCTFROM' => true,
        'CCACCTTO' => true, 'STMTENDTRNRS' => true, 'STMTENDRS' => true, 'CLOSING' => true,
        'CCSTMTENDTRNRS' => true, 'CCSTMTENDRS' => true, 'CCCLOSING' => true,
        'INVSTMTMSGSRSV1' => true, 'INVSTMTTRNRS' => true, 'INVSTMTRS' => true, 'INVACCTFROM' => true,
        'INVTRANLIST' => true, 'INVPOSLIST' => true, 'INVBAL' => true, 'INVBANKTRAN' => true,
        'SECLISTMSGSRSV1' => true, 'SECLIST' => true, 'SIGNUPMSGSRSV1' => true, 'ACCTINFOTRNRS' => true,
        'ACCTINFORS' => true, 'ACCTINFO' => true, 'BANKACCTINFO' => true, 'CCACCTINFO' => true,
    ];

    /**
     * Há banco que manda como folha (`<CURRENCY>BRL`) ou vazio sem fechar:
     * só abre se o próximo elemento for um filho esperado.
     */
    private const AMBIGUOUS = [
        'CURRENCY' => ['CURRATE' => true, 'CURSYM' => true],
        'ORIGCURRENCY' => ['CURRATE' => true, 'CURSYM' => true],
        'PAYEE' => ['NAME' => true, 'ADDR1' => true, 'ADDR2' => true, 'ADDR3' => true, 'CITY' => true, 'STATE' => true, 'POSTALCODE' => true, 'COUNTRY' => true, 'PHONE' => true],
    ];

    /** Teto de tags soltas absorvidas por folha (evita custo quadrático). */
    private const MAX_STRAY_TAGS = 32;

    /** Sem texto, são folha vazia — nunca aggregate. */
    private const LEAVES = [
        'TRNTYPE' => true, 'DTPOSTED' => true, 'DTUSER' => true, 'DTAVAIL' => true, 'TRNAMT' => true,
        'FITID' => true, 'CORRECTFITID' => true, 'CORRECTACTION' => true, 'SRVRTID' => true,
        'CHECKNUM' => true, 'REFNUM' => true, 'SIC' => true, 'PAYEEID' => true, 'NAME' => true,
        'EXTDNAME' => true, 'MEMO' => true, 'INV401KSOURCE' => true, 'CURRATE' => true, 'CURSYM' => true,
        'CURDEF' => true, 'BANKID' => true, 'BRANCHID' => true, 'ACCTID' => true, 'ACCTTYPE' => true,
        'ACCTKEY' => true, 'DTSTART' => true, 'DTEND' => true, 'BALAMT' => true, 'DTASOF' => true,
        'CODE' => true, 'SEVERITY' => true, 'MESSAGE' => true, 'DTSERVER' => true, 'LANGUAGE' => true,
        'DTPROFUP' => true, 'DTACCTUP' => true, 'ORG' => true, 'FID' => true, 'TRNUID' => true,
        'CLTCOOKIE' => true, 'SESSCOOKIE' => true, 'USERKEY' => true, 'TSKEYEXPIRE' => true,
        'ADDR1' => true, 'ADDR2' => true, 'ADDR3' => true, 'CITY' => true, 'STATE' => true,
        'POSTALCODE' => true, 'COUNTRY' => true, 'PHONE' => true, 'MKTGINFO' => true, 'DESC' => true,
        'BALTYPE' => true, 'VALUE' => true, 'DTTRAN' => true, 'DTEXPIRE' => true,
    ];

    /** Onde `<` aparece de verdade no conteúdo. */
    private const FREE_TEXT = ['MEMO' => true, 'NAME' => true, 'EXTDNAME' => true, 'MESSAGE' => true, 'DESC' => true];

    /** @var Generator<int, array{0: int, 1: string, 2: string}> */
    private Generator $tokens;

    /** @var array{0: int, 1: string, 2: string}|null */
    private ?array $peeked = null;

    private function __construct(string $body)
    {
        $this->tokens = self::tokenize($body);
    }

    /** Raiz sintética com um filho `OFX` por bloco. */
    public static function build(string $body): Node
    {
        return (new self($body))->run(self::closedNames($body));
    }

    /**
     * @param array<string, true> $closed nomes que aparecem fechados (`</X>`) no arquivo
     */
    private function run(array $closed): Node
    {
        $root = new Node('#document');
        /** @var list<Node> $stack */
        $stack = [$root];

        while (($token = $this->next()) !== null) {
            [$kind, $name] = $token;

            if ($kind === self::TEXT) {
                continue;
            }

            if ($kind === self::CLOSE) {
                self::closeUpTo($stack, $name);
                continue;
            }

            if (isset(self::STRUCTURAL[$name])) {
                self::open($stack, $name);
                continue;
            }

            $current = $stack[count($stack) - 1];
            $next = $this->peek();
            if ($next !== null && $next[0] === self::TEXT) {
                self::setField($current, $name, $this->readLeaf($name));
                continue;
            }
            if ($next !== null && $next[0] === self::CLOSE && $next[1] === $name) {
                $this->next();
                self::setField($current, $name, '');
                continue;
            }

            $isAggregate = isset(self::AMBIGUOUS[$name])
                ? $next !== null && $next[0] === self::OPEN && isset(self::AMBIGUOUS[$name][$next[1]])
                : ! isset(self::LEAVES[$name]) && isset($closed[$name]);
            if ($isAggregate) {
                self::open($stack, $name);
            } else {
                self::setField($current, $name, '');
            }
        }

        return $root;
    }

    /** Campo repetido: vale o primeiro valor não vazio. */
    private static function setField(Node $node, string $name, string $value): void
    {
        if (($node->fields[$name] ?? '') === '') {
            $node->fields[$name] = $value;
        }
    }

    /**
     * Aggregate nunca contém a si mesmo: abrir outro `<STMTTRN>` fecha o anterior.
     *
     * @param list<Node> $stack
     */
    private static function open(array &$stack, string $name): void
    {
        self::closeUpTo($stack, $name);
        if (count($stack) >= self::MAX_DEPTH) {
            return;
        }

        $node = new Node($name);
        $stack[count($stack) - 1]->children[] = $node;
        $stack[] = $node;
    }

    /**
     * Fechamento órfão é ignorado.
     *
     * @param list<Node> $stack
     */
    private static function closeUpTo(array &$stack, string $name): void
    {
        for ($i = count($stack) - 1; $i > 0; $i--) {
            if ($stack[$i]->name === $name) {
                $stack = array_slice($stack, 0, $i);

                return;
            }
        }
    }

    private function readLeaf(string $name): string
    {
        $value = $this->next()[2] ?? '';

        if (isset(self::FREE_TEXT[$name])) {
            $merged = 0;
            while ($merged++ < self::MAX_STRAY_TAGS && $this->isStrayTag($value)) {
                $value .= $this->next()[2] ?? '';
                if (($this->peek()[0] ?? null) === self::TEXT) {
                    $value .= $this->next()[2] ?? '';
                }
            }
        }

        $next = $this->peek();
        if ($next !== null && $next[0] === self::CLOSE && $next[1] === $name) {
            $this->next();
        }

        return trim($value);
    }

    /** Tag desconhecida na mesma linha do texto é conteúdo (`PAG <BOLETO> LTDA`). */
    private function isStrayTag(string $valueSoFar): bool
    {
        $next = $this->peek();
        if ($next === null || $next[0] === self::TEXT || str_contains($next[1], '.')) {
            return false;
        }
        if (isset(self::STRUCTURAL[$next[1]]) || isset(self::AMBIGUOUS[$next[1]]) || isset(self::LEAVES[$next[1]])) {
            return false;
        }

        // Tag logo após quebra de linha é estrutura.
        $tail = rtrim($valueSoFar, " \t");

        return $tail !== '' && $tail[-1] !== "\n" && $tail[-1] !== "\r";
    }

    /**
     * @return array{0: int, 1: string, 2: string}|null
     */
    private function next(): ?array
    {
        if ($this->peeked !== null) {
            $token = $this->peeked;
            $this->peeked = null;

            return $token;
        }
        if (! $this->tokens->valid()) {
            return null;
        }
        $token = $this->tokens->current();
        $this->tokens->next();

        return $token;
    }

    /**
     * @return array{0: int, 1: string, 2: string}|null
     */
    private function peek(): ?array
    {
        return $this->peeked ??= $this->next();
    }

    /**
     * Texto só de espaços é descartado; entidades decodificadas (exceto CDATA).
     *
     * @return Generator<int, array{0: int, 1: string, 2: string}>
     */
    private static function tokenize(string $body): Generator
    {
        $length = strlen($body);
        $offset = 0;
        $text = '';

        while ($offset < $length) {
            $lt = strpos($body, '<', $offset);
            if ($lt === false) {
                $text .= Text::decodeEntities(substr($body, $offset));
                break;
            }
            if ($lt > $offset) {
                $text .= Text::decodeEntities(substr($body, $offset, $lt - $offset));
            }
            $offset = $lt;

            // Sem terminador é texto: um `<!--` perdido no MEMO não engole o arquivo.
            if (substr_compare($body, '<!--', $offset, 4) === 0 && ($end = strpos($body, '-->', $offset + 4)) !== false) {
                $offset = $end + 3;
                continue;
            }
            if (substr_compare($body, '<![CDATA[', $offset, 9) === 0 && ($end = strpos($body, ']]>', $offset + 9)) !== false) {
                $text .= substr($body, $offset + 9, $end - $offset - 9);
                $offset = $end + 3;
                continue;
            }
            if (preg_match('/\G<(?:\?[^<>]*+|![A-Za-z][^<>\[]*+(?:\[[^\]]*+\])?[^<>]*+)>/', $body, $m, 0, $offset)) {
                $offset += strlen($m[0]);
                continue;
            }

            if (preg_match(self::TAG, $body, $m, 0, $offset)) {
                if (trim($text) !== '') {
                    yield [self::TEXT, '', $text];
                }
                $text = '';
                $name = strtoupper($m[2]);
                if ($m[1] === '/') {
                    yield [self::CLOSE, $name, $m[0]];
                } else {
                    yield [self::OPEN, $name, $m[0]];
                    if ($m[4] === '/') {
                        yield [self::CLOSE, $name, ''];
                    }
                }
                $offset += strlen($m[0]);
                continue;
            }

            $text .= '<';
            $offset++;
        }

        if (trim($text) !== '') {
            yield [self::TEXT, '', $text];
        }
    }

    /**
     * Separa aggregate desconhecido de folha vazia desconhecida.
     *
     * @return array<string, true>
     */
    private static function closedNames(string $body): array
    {
        $names = [];
        $offset = 0;
        while (($position = strpos($body, '</', $offset)) !== false) {
            if (preg_match('/\G<\/\s*([A-Za-z][A-Za-z0-9._:-]*)\s*>/', $body, $m, 0, $position)) {
                $names[strtoupper($m[1])] = true;
            }
            $offset = $position + 2;
        }

        return $names;
    }
}
