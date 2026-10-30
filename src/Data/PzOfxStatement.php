<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Data;

use ArrayIterator;
use Countable;
use DateTimeImmutable;
use IteratorAggregate;
use Puzl\PzOfx\Config\PzOfxProfile;
use Puzl\PzOfx\Enums\PzOfxStatementTypeEnum;
use Puzl\PzOfx\Support\Decimal;
use Traversable;

/**
 * Extrato de conta ou cartão, na ordem do arquivo. O período declarado
 * (`startDate`/`endDate`) nem sempre cobre as transações: use `firstDate()`/`lastDate()`.
 *
 * @implements IteratorAggregate<int, PzOfxTransaction>
 */
final class PzOfxStatement implements IteratorAggregate, Countable
{
    /**
     * @param list<PzOfxTransaction> $transactions
     */
    public function __construct(
        public readonly PzOfxStatementTypeEnum $type,
        public readonly PzOfxAccount $account,
        public readonly ?string $currency,
        public readonly ?DateTimeImmutable $startDate,
        public readonly ?DateTimeImmutable $endDate,
        public readonly array $transactions,
        public readonly ?PzOfxBalance $ledgerBalance,
        public readonly ?PzOfxBalance $availableBalance,
        public readonly ?PzOfxStatus $status,
        public readonly ?string $transactionUid,
        /** Perfil aplicado na leitura. */
        public readonly PzOfxProfile $profile,
    ) {
    }

    public function isBank(): bool
    {
        return $this->type === PzOfxStatementTypeEnum::BANK;
    }

    public function isCreditCard(): bool
    {
        return $this->type === PzOfxStatementTypeEnum::CREDIT_CARD;
    }

    public function isEmpty(): bool
    {
        return $this->transactions === [];
    }

    public function count(): int
    {
        return count($this->transactions);
    }

    /**
     * @return Traversable<int, PzOfxTransaction>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->transactions);
    }

    public function firstDate(): ?DateTimeImmutable
    {
        return $this->transactions === [] ? null : min(array_map(static fn (PzOfxTransaction $t) => $t->postedAt, $this->transactions));
    }

    public function lastDate(): ?DateTimeImmutable
    {
        return $this->transactions === [] ? null : max(array_map(static fn (PzOfxTransaction $t) => $t->postedAt, $this->transactions));
    }

    /**
     * Sempre em ordem: das transações ou, sem nenhuma, o período declarado.
     *
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable}
     */
    public function period(): array
    {
        return $this->transactions === [] ? [$this->startDate, $this->endDate] : [$this->firstDate(), $this->lastDate()];
    }

    /**
     * Empate mantém a ordem do arquivo.
     *
     * @return list<PzOfxTransaction>
     */
    public function sortedByDate(): array
    {
        $transactions = $this->transactions;
        usort($transactions, static fn (PzOfxTransaction $a, PzOfxTransaction $b): int => [$a->postedAt, $a->index] <=> [$b->postedAt, $b->index]);

        return $transactions;
    }

    /**
     * @return list<PzOfxTransaction>
     */
    public function credits(): array
    {
        return array_values(array_filter($this->transactions, static fn (PzOfxTransaction $t): bool => $t->isCredit()));
    }

    /**
     * @return list<PzOfxTransaction>
     */
    public function debits(): array
    {
        return array_values(array_filter($this->transactions, static fn (PzOfxTransaction $t): bool => $t->isDebit()));
    }

    public function totalCredits(): string
    {
        return Decimal::sum(...array_map(static fn (PzOfxTransaction $t): string => $t->amount, $this->credits()));
    }

    public function totalDebits(): string
    {
        return Decimal::sum(...array_map(static fn (PzOfxTransaction $t): string => $t->amount, $this->debits()));
    }

    public function total(): string
    {
        return Decimal::sum(...array_map(static fn (PzOfxTransaction $t): string => $t->amount, $this->transactions));
    }

    /**
     * @return array<string, int>
     */
    public function duplicateFitIds(): array
    {
        $fitIds = array_filter(array_map(static fn (PzOfxTransaction $t): ?string => $t->fitId, $this->transactions), static fn (?string $id): bool => $id !== null);

        return array_filter(array_count_values($fitIds), static fn (int $count): bool => $count > 1);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'account' => $this->account->toArray(),
            'currency' => $this->currency,
            'start_date' => $this->startDate?->format('Y-m-d H:i:s'),
            'end_date' => $this->endDate?->format('Y-m-d H:i:s'),
            'first_date' => $this->firstDate()?->format('Y-m-d H:i:s'),
            'last_date' => $this->lastDate()?->format('Y-m-d H:i:s'),
            'ledger_balance' => $this->ledgerBalance?->toArray(),
            'available_balance' => $this->availableBalance?->toArray(),
            'status' => $this->status?->toArray(),
            'transaction_uid' => $this->transactionUid,
            'transactions' => array_map(static fn (PzOfxTransaction $t): array => $t->toArray(), $this->transactions),
        ];
    }
}
