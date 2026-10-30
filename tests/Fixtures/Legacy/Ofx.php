<?php

// Leitor legado, sem alterações além do namespace: referência dos testes de equivalência.

namespace Puzl\PzOfx\Tests\Fixtures\Legacy;

use Puzl\PzOfx\Tests\Fixtures\Legacy\General;
use Carbon\Carbon;
use SimpleXMLElement;

class Ofx
{
    private const PAYMENT_METHOD_CREDIT = "CREDIT"; 
    private const PAYMENT_METHOD_DEBIT = "DEBIT";
    public array $bankTransactions = [];
    public string $startAt;
    public string $endAt;
    public string $bankCode;
    public string $account;
    public string $accountDigit;
    public string $org;

    public function __construct(
        public $file,
    ) {
        return $this->readFile();
    }

    /**
     * @param $file
     *
     * @return string
     *  Transforma o arquivo em uma string com tags fechadas
     *  Antes: OFXHEADER:100 e Depois: OFXHEADER:100</FXHEADER:100>
     */
    public function closeTags($file = null)
    {
        // buffer é o arquivo que receberá o arquivo alterado com as tags fechadas
        $buffer = '';
        $handle = fopen($file, 'r');
        if ($handle !== false) {
            // Percorre linha por linha de handle (Arquivo aberto)
            while (! feof($handle)) {
                $line = trim(iconv('ASCII', 'UTF-8//IGNORE', fgets($handle)));
                if ($line === '') {
                    continue;
                }

                // Tratar campo TRNTYPE sem valor e sem fechamento de tag que deveria estar na mesma linha
                if ($line === '<TRNTYPE>') {
                    $line = '<TRNTYPE></TRNTYPE>';
                }

                // Adiciona a tag de fechamento na linha se não existir
                if (substr($line, -1, 1) !== '>') {
                    [$tag] = explode('>', $line, 2);
                    $line .= '</' . substr($tag, 1) . '>';
                }

                // Adiciona linha alterada com tag de fechamento em buffer
                $buffer .= $this->removeSpecialChars($line) . "\n";
            }
        }

        // Separa o cabeçalho e as transações do arquivo
        // xmlOut[0] = Cabeçalho - xmlOut[1] = transações
        $xmlOut = explode('<OFX>', $buffer);
        return isset($xmlOut[1]) ? '<OFX>' . $xmlOut[1] : $buffer;
    }

    /**
     * @return $this
     *
     * @throws \Exception
     * Faz a leitura do arquivo e carrega as informações nas propriedades da classe
     */
    public function readFile()
    {
        // Verificar existência do arquivo
        if (! isset($this->file)) {
            throw new \Exception('exceptions.file_not_informed');
        }
        // Retorna conteúdo do arquivo em string com tags fechadas
        $fileStringWithCloseTags = $this->closeTags($this->file);

        // Converte string em Elemento XML para facilitar leitura abaixo
        $ofxTags = new SimpleXMLElement($fileStringWithCloseTags);

        // Verificar a existência de transações no arquivo
        if ((is_null($ofxTags->BANKMSGSRSV1->STMTTRNRS->STMTRS->BANKTRANLIST->STMTTRN)) || (!$ofxTags->BANKMSGSRSV1->STMTTRNRS->STMTRS->BANKTRANLIST->STMTTRN))
            throw new \Exception('validation_financial.bank_reconciliations.ofx_file_transactions_is_empty');

        // Percorre STMTTRN e adiciona as transações em bankTransactions
        foreach ($ofxTags->BANKMSGSRSV1->STMTTRNRS->STMTRS->BANKTRANLIST->STMTTRN as $value) {
            // Formatar Data
            $dtPostedFormated = Carbon::parse(
                substr($value->DTPOSTED[0], 0, 14)
            )->toDateTimeString();

            // Formatar Valor
            $trnamtFormated = $this->objToNumber($value->TRNAMT[0]);

            array_push(
                $this->bankTransactions,
                [
                    'type' => $trnamtFormated >= 0 
                        ? self::PAYMENT_METHOD_CREDIT 
                        : self::PAYMENT_METHOD_DEBIT,
                    'date_at' => $dtPostedFormated,
                    'value' => $trnamtFormated,
                    'fit_id' => trim((string) $value->FITID[0]),
                    'check_num' => trim((string) $value->CHECKNUM[0]),
                    'ref_number' => trim((string) $value->REFNUM[0]),
                    'description' => preg_replace('/\s{2,}/', ' ', trim((string) $value->MEMO[0])),
                ]
            );
        }

        // Período Inicial e Final com base nas transações
        $this->startAt = $this->bankTransactions[0]['date_at'];
        $this->endAt = end($this->bankTransactions)['date_at'];

        // Código do Banco e Organização
        $this->bankCode = $ofxTags->BANKMSGSRSV1->STMTTRNRS->STMTRS->BANKACCTFROM->BANKID;
        $this->org = $ofxTags->SIGNONMSGSRSV1->SONRS->FI->ORG ?? '';

        // Conta e Digito da Conta
        $accountExplode = explode('-', $ofxTags->BANKMSGSRSV1->STMTTRNRS->STMTRS->BANKACCTFROM->ACCTID);
        $this->account = $accountExplode[0];
        $this->accountDigit = $accountExplode[1] ?? '0';

        // Retorna a própria classe
        return $this;
    }

    /**
     * @param object_xml $value
     *
     * @return float
     *  Converte objeto xml em numero formatado
     *  Formatar o número apenas se for necessário
     */
    public function objToNumber($value)
    {
        $strValue = (string) $value;

        if (substr($strValue, -3, 1) === '.') {
            return $strValue;
        }
        return General::unformatMoney($strValue);
    }

    /**
     * Remover caracteres especiais
     *
     * @param string $value
     *
     * @return string
     */
    public function removeSpecialChars(string $value): string
    {
        return str_replace(['&', '#'], '', $value);
    }
}
