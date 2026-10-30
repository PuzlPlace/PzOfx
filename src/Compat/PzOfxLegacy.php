<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Compat;

use Exception;
use Puzl\PzOfx\Enums\PzOfxDirectionEnum;
use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use Puzl\PzOfx\Exceptions\PzOfxException;
use Puzl\PzOfx\Data\PzOfxDocument;
use Puzl\PzOfx\Data\PzOfxStatement;
use Puzl\PzOfx\Data\PzOfxTransaction;
use Puzl\PzOfx\PzOfx;

/**
 * Substituto do leitor OFX legado: mesmo construtor, mesmas propriedades e
 * chaves de tradução nos erros. Por baixo usa o parser do PzOfx.
 */
class PzOfxLegacy
{
    public const FILE_NOT_INFORMED = 'exceptions.file_not_informed';

    /** Também para arquivo sem extrato de conta. */
    public const TRANSACTIONS_EMPTY = 'validation_financial.bank_reconciliations.ofx_file_transactions_is_empty';

    private const PAYMENT_METHOD_CREDIT = 'CREDIT';

    private const PAYMENT_METHOD_DEBIT = 'DEBIT';

    /** Limites das colunas onde as linhas costumam ser gravadas. */
    private const CHECK_NUM_LENGTH = 55;

    private const TEXT_LENGTH = 255;

    /**
     * `value` é string decimal exata (`"-35.70"`).
     *
     * @var list<array{type: string, date_at: string, value: string, fit_id: string, check_num: string, ref_number: string, description: string}>
     */
    public array $bankTransactions = [];

    public string $startAt;

    public string $endAt;

    public string $bankCode;

    public string $account;

    public string $accountDigit;

    public string $org;

    private PzOfxDocument $document;

    /**
     * @param mixed $file caminho, `UploadedFile`/`SplFileInfo`, stream ou conteúdo
     *
     * @throws Exception com chave de tradução como mensagem, ou {@see PzOfxException}
     */
    public function __construct(
        public $file,
    ) {
        $this->readFile();
    }

    /**
     * @throws Exception
     */
    public function readFile(): static
    {
        if (! isset($this->file) || $this->file === '') {
            throw new Exception(self::FILE_NOT_INFORMED);
        }

        try {
            $this->document = PzOfx::parse($this->file);
        } catch (PzOfxException $e) {
            if ($e->error() === PzOfxErrorEnum::FILE_NOT_INFORMED) {
                throw new Exception(self::FILE_NOT_INFORMED, 0, $e);
            }
            throw $e;
        }

        $statement = $this->document->bankStatements()[0] ?? null;
        if ($statement === null || $statement->isEmpty()) {
            throw new Exception(self::TRANSACTIONS_EMPTY);
        }

        $this->fill($statement);

        return $this;
    }

    /** Documento completo, para migrar aos poucos. */
    public function document(): PzOfxDocument
    {
        return $this->document;
    }

    private function fill(PzOfxStatement $statement): void
    {
        $this->bankTransactions = array_map(
            static fn (PzOfxTransaction $t): array => [
                'type' => $t->direction() === PzOfxDirectionEnum::CREDIT ? self::PAYMENT_METHOD_CREDIT : self::PAYMENT_METHOD_DEBIT,
                'date_at' => $t->postedAt->format('Y-m-d H:i:s'),
                'value' => $t->amount,
                'fit_id' => (string) $t->fitId,
                'check_num' => self::column((string) $t->checkNumber, self::CHECK_NUM_LENGTH),
                'ref_number' => self::column((string) $t->referenceNumber),
                'description' => self::column($t->description),
            ],
            $statement->transactions,
        );

        // Há banco que manda do mais novo ao mais antigo.
        $this->startAt = (string) $statement->firstDate()?->format('Y-m-d H:i:s');
        $this->endAt = (string) $statement->lastDate()?->format('Y-m-d H:i:s');

        $this->bankCode = (string) $statement->account->bankId;
        $this->org = (string) $this->document->signOn?->organization;

        // Corte do leitor legado: antes do primeiro "-" é a conta.
        $parts = explode('-', $statement->account->accountId);
        $this->account = $parts[0];
        $this->accountDigit = $parts[1] ?? '0';
    }

    /** Sem caracteres de 4 bytes (colunas `utf8mb3` rejeitam) e no tamanho da coluna. */
    private static function column(string $value, int $length = self::TEXT_LENGTH): string
    {
        $value = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $value) ?? $value;

        return mb_substr(trim(preg_replace('/\s{2,}/u', ' ', $value) ?? $value), 0, $length);
    }
}
