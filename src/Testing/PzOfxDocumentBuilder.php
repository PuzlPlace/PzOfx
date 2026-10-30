<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Testing;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Puzl\PzOfx\Config\PzOfxConfig;
use Puzl\PzOfx\Data\PzOfxDocument;
use Puzl\PzOfx\PzOfx;
use Puzl\PzOfx\PzOfxParser;

/**
 * Gera OFX para testes e o lê com o parser real: o documento sai igual ao
 * de uma leitura de verdade.
 */
final class PzOfxDocumentBuilder
{
    /** @var list<array{type: string, account: array<string, ?string>, transactions: list<array<string, ?string>>, ledger: ?array{0: string, 1: string}, currency: string}> */
    private array $statements = [];

    private ?string $org = null;

    private ?string $fid = null;

    private int $sequence = 0;

    private function __construct()
    {
    }

    public static function bank(string $bankId = '341', string $accountId = '12345-6', ?string $branchId = null, string $accountType = 'CHECKING'): self
    {
        return (new self())->addBankStatement($bankId, $accountId, $branchId, $accountType);
    }

    public static function creditCard(string $accountId = '5502XXXXXXXX1234'): self
    {
        return (new self())->addCreditCardStatement($accountId);
    }

    /** As próximas transações vão para este extrato. */
    public function addBankStatement(string $bankId = '341', string $accountId = '12345-6', ?string $branchId = null, string $accountType = 'CHECKING'): self
    {
        $this->statements[] = [
            'type' => 'bank',
            'account' => ['BANKID' => $bankId, 'BRANCHID' => $branchId, 'ACCTID' => $accountId, 'ACCTTYPE' => $accountType],
            'transactions' => [],
            'ledger' => null,
            'currency' => 'BRL',
        ];

        return $this;
    }

    public function addCreditCardStatement(string $accountId = '5502XXXXXXXX1234'): self
    {
        $this->statements[] = ['type' => 'credit_card', 'account' => ['ACCTID' => $accountId], 'transactions' => [], 'ledger' => null, 'currency' => 'BRL'];

        return $this;
    }

    /**
     * `fitId: null` gera um único; `''` omite. `type: null` decide pelo sinal.
     *
     * @param string|int|float $amount `-50.00`, `"-1.234,56"`, `1500`…
     * @param string|DateTimeInterface $date `2024-01-15`, `2024-01-15 10:00:00` ou data
     * @param string|null $fitId `null` gera um único (`F1`, `F2`…); `''` omite o FITID
     * @param string|null $type TRNTYPE; `null` = CREDIT/DEBIT pelo sinal
     */
    public function transaction(
        string|int|float $amount,
        string|DateTimeInterface $date = '2024-01-15',
        ?string $memo = null,
        ?string $fitId = null,
        ?string $name = null,
        ?string $type = null,
        ?string $checkNumber = null,
        ?string $referenceNumber = null,
    ): self {
        $amount = is_float($amount) ? number_format($amount, 2, '.', '') : (string) $amount;

        $this->current()['transactions'][] = [
            'TRNTYPE' => $type ?? (str_starts_with(ltrim($amount), '-') ? 'DEBIT' : 'CREDIT'),
            'DTPOSTED' => self::date($date),
            'TRNAMT' => $amount,
            'FITID' => $fitId ?? 'F'.(++$this->sequence),
            'CHECKNUM' => $checkNumber,
            'REFNUM' => $referenceNumber,
            'NAME' => $name,
            'MEMO' => $memo,
        ];

        return $this;
    }

    public function ledgerBalance(string|int|float $amount, string|DateTimeInterface $asOf = '2024-01-31'): self
    {
        $this->current()['ledger'] = [is_float($amount) ? number_format($amount, 2, '.', '') : (string) $amount, self::date($asOf)];

        return $this;
    }

    public function currency(string $currency): self
    {
        $this->current()['currency'] = $currency;

        return $this;
    }

    /** Usados na escolha de perfil. */
    public function institution(?string $org, ?string $fid = null): self
    {
        $this->org = $org;
        $this->fid = $fid;

        return $this;
    }

    public function toOfx(): string
    {
        $fi = $this->org === null && $this->fid === null ? '' : '<FI>'.self::leaf('ORG', $this->org).self::leaf('FID', $this->fid).'</FI>';
        $bank = '';
        $card = '';
        foreach ($this->statements as $index => $statement) {
            $isBank = $statement['type'] === 'bank';
            $body = '<'.($isBank ? 'STMTTRNRS' : 'CCSTMTTRNRS').'><TRNUID>'.($index + 1).'<STATUS><CODE>0<SEVERITY>INFO</STATUS>'
                .'<'.($isBank ? 'STMTRS' : 'CCSTMTRS').'><CURDEF>'.$statement['currency']
                .'<'.($isBank ? 'BANKACCTFROM' : 'CCACCTFROM').'>'.self::leaves($statement['account']).'</'.($isBank ? 'BANKACCTFROM' : 'CCACCTFROM').'>'
                ."\n<BANKTRANLIST>\n";
            foreach ($statement['transactions'] as $transaction) {
                $body .= '<STMTTRN>'.self::leaves($transaction)."</STMTTRN>\n";
            }
            $body .= '</BANKTRANLIST>';
            if ($statement['ledger'] !== null) {
                $body .= '<LEDGERBAL><BALAMT>'.$statement['ledger'][0].'<DTASOF>'.$statement['ledger'][1].'</LEDGERBAL>';
            }
            $body .= '</'.($isBank ? 'STMTRS' : 'CCSTMTRS').'></'.($isBank ? 'STMTTRNRS' : 'CCSTMTTRNRS').">\n";

            $isBank ? $bank .= $body : $card .= $body;
        }

        return "OFXHEADER:100\nDATA:OFXSGML\nVERSION:102\nSECURITY:NONE\nENCODING:UTF-8\nCHARSET:NONE\nCOMPRESSION:NONE\nOLDFILEUID:NONE\nNEWFILEUID:NONE\n\n"
            .'<OFX><SIGNONMSGSRSV1><SONRS><STATUS><CODE>0<SEVERITY>INFO</STATUS><LANGUAGE>POR'.$fi."</SONRS></SIGNONMSGSRSV1>\n"
            .($bank === '' ? '' : "<BANKMSGSRSV1>\n{$bank}</BANKMSGSRSV1>\n")
            .($card === '' ? '' : "<CREDITCARDMSGSRSV1>\n{$card}</CREDITCARDMSGSRSV1>\n")
            ."</OFX>\n";
    }

    /**
     * Com a config global (perfis inclusos).
     *
     * @param array<string, mixed>|PzOfxConfig|null $config ajustes desta leitura
     */
    public function build(array|PzOfxConfig|null $config = null): PzOfxDocument
    {
        $parser = new PzOfxParser(PzOfx::config());

        return ($config === null ? $parser : $parser->withConfig($config))->parseString($this->toOfx());
    }

    /**
     * @return array{type: string, account: array<string, ?string>, transactions: list<array<string, ?string>>, ledger: ?array{0: string, 1: string}, currency: string}
     */
    private function &current(): array
    {
        if ($this->statements === []) {
            throw new InvalidArgumentException('Comece por PzOfxDocumentBuilder::bank() ou ::creditCard().');
        }

        return $this->statements[count($this->statements) - 1];
    }

    /**
     * @param array<string, ?string> $fields
     */
    private static function leaves(array $fields): string
    {
        $out = '';
        foreach ($fields as $name => $value) {
            $out .= self::leaf($name, $value);
        }

        return $out;
    }

    private static function leaf(string $name, ?string $value): string
    {
        return $value === null ? '' : "<{$name}>".htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."\n";
    }

    private static function date(string|DateTimeInterface $date): string
    {
        $date = $date instanceof DateTimeInterface ? $date : new DateTimeImmutable($date);

        return $date->format('YmdHis');
    }
}
