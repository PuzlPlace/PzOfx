<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Parser;

use DateTimeImmutable;
use DateTimeZone;
use Puzl\PzOfx\Config\PzOfxConfig;
use Puzl\PzOfx\Config\PzOfxProfile;
use Puzl\PzOfx\Enums\PzOfxAccountTypeEnum;
use Puzl\PzOfx\Enums\PzOfxAmountSignEnum;
use Puzl\PzOfx\Enums\PzOfxDescriptionEnum;
use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use Puzl\PzOfx\Enums\PzOfxStatementTypeEnum;
use Puzl\PzOfx\Enums\PzOfxTimezoneModeEnum;
use Puzl\PzOfx\Enums\PzOfxTransactionTypeEnum;
use Puzl\PzOfx\Enums\PzOfxWarningEnum;
use Puzl\PzOfx\Exceptions\PzOfxParseException;
use Puzl\PzOfx\Data\PzOfxAccount;
use Puzl\PzOfx\Data\PzOfxBalance;
use Puzl\PzOfx\Data\PzOfxDocument;
use Puzl\PzOfx\Data\PzOfxHeader;
use Puzl\PzOfx\Data\PzOfxSignOn;
use Puzl\PzOfx\Data\PzOfxStatement;
use Puzl\PzOfx\Data\PzOfxStatus;
use Puzl\PzOfx\Data\PzOfxTransaction;
use Puzl\PzOfx\Data\PzOfxWarning;
use Puzl\PzOfx\Support\Decimal;
use Puzl\PzOfx\Support\OfxDate;
use Puzl\PzOfx\Support\Text;

/**
 * Árvore → modelo, aplicando config e perfil. Uma instância por leitura (acumula avisos).
 *
 * @internal
 */
final class DocumentMapper
{
    private const POSITIVE_TYPES = ['CREDIT', 'DEP', 'INT', 'DIV', 'DIRECTDEP'];

    private const NEGATIVE_TYPES = ['DEBIT', 'FEE', 'SRVCHG', 'POS', 'ATM', 'PAYMENT', 'CHECK', 'CASH', 'DIRECTDEBIT', 'REPEATPMT'];

    /** @var list<PzOfxWarning> */
    private array $warnings = [];

    private readonly DateTimeZone $timezone;

    public function __construct(
        private readonly PzOfxConfig $config,
    ) {
        $this->timezone = $config->dateTimeZone();
    }

    public function map(Node $root, PzOfxHeader $header): PzOfxDocument
    {
        $blocks = array_values(array_filter($root->children, static fn (Node $n): bool => $n->name === 'OFX'));
        if ($blocks === []) {
            throw PzOfxParseException::because(PzOfxErrorEnum::NOT_OFX);
        }

        $signOnNode = null;
        foreach ($blocks as $block) {
            $signOnNode ??= $block->find('SONRS')[0] ?? null;
        }
        $signOn = $signOnNode === null ? null : $this->signOn($signOnNode);

        $found = [];
        foreach ($blocks as $block) {
            $this->collectStatements($block, null, $found);
        }

        $statements = [];
        foreach ($found as [$node, $wrapper]) {
            $statements[] = $this->statement($node, $wrapper, $signOn);
        }

        return new PzOfxDocument($header, $signOn, $statements, $this->warnings);
    }

    /**
     * Extratos onde estiverem, com o `*TRNRS` que os envolve (dono de STATUS e TRNUID).
     *
     * @param list<array{0: Node, 1: ?Node}> $found
     */
    private function collectStatements(Node $node, ?Node $wrapper, array &$found): void
    {
        foreach ($node->children as $child) {
            match ($child->name) {
                'STMTRS', 'CCSTMTRS' => $found[] = [$child, $wrapper],
                'STMTTRNRS', 'CCSTMTTRNRS' => $this->collectStatements($child, $child, $found),
                default => $this->collectStatements($child, $wrapper, $found),
            };
        }
    }

    private function signOn(Node $node): PzOfxSignOn
    {
        return new PzOfxSignOn(
            status: $this->status($node, 'SONRS'),
            serverDate: $this->optionalDate($node->field('DTSERVER'), 'DTSERVER'),
            language: Text::nullIfBlank($node->field('LANGUAGE')),
            organization: Text::nullIfBlank($node->value('FI', 'ORG') ?? $node->field('ORG')),
            fid: self::id($node->value('FI', 'FID') ?? $node->field('FID')),
        );
    }

    private function statement(Node $node, ?Node $wrapper, ?PzOfxSignOn $signOn): PzOfxStatement
    {
        $type = $node->name === 'CCSTMTRS' ? PzOfxStatementTypeEnum::CREDIT_CARD : PzOfxStatementTypeEnum::BANK;
        $account = $this->account($node, $type);
        // Cartão não tem BANKID: o emissor só aparece no FID.
        $profile = $this->config->profileFor($account->bankId ?? $signOn?->fid, $signOn?->organization, $signOn?->fid, $type);
        $list = $node->child('BANKTRANLIST');
        [$startDate, $endDate] = $this->period(
            $this->optionalDate($list?->field('DTSTART'), 'DTSTART'),
            $this->optionalDate($list?->field('DTEND'), 'DTEND'),
        );

        return new PzOfxStatement(
            type: $type,
            account: $account,
            currency: self::id($node->field('CURDEF')),
            startDate: $startDate,
            endDate: $endDate,
            transactions: $this->transactions($node->find('STMTTRN'), $account, $type, $profile),
            ledgerBalance: $this->balance(self::section($node, 'LEDGERBAL'), 'LEDGERBAL'),
            availableBalance: $this->balance(self::section($node, 'AVAILBAL'), 'AVAILBAL'),
            status: $wrapper === null ? null : $this->status($wrapper, $wrapper->name),
            transactionUid: self::id($wrapper?->field('TRNUID')),
            profile: $profile,
        );
    }

    /**
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable}
     */
    private function period(?DateTimeImmutable $start, ?DateTimeImmutable $end): array
    {
        if ($start === null || $end === null || $start <= $end) {
            return [$start, $end];
        }

        $context = ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')];
        if (! $this->config->fixInvertedPeriod) {
            throw PzOfxParseException::because(PzOfxErrorEnum::INVERTED_PERIOD, $context);
        }
        $this->warn(PzOfxWarningEnum::PERIOD_INVERTED, $context);

        return [$end, $start];
    }

    private function account(Node $statement, PzOfxStatementTypeEnum $type): PzOfxAccount
    {
        $node = $statement->child($type === PzOfxStatementTypeEnum::BANK ? 'BANKACCTFROM' : 'CCACCTFROM')
            ?? $statement->child('BANKACCTFROM')
            ?? $statement->child('CCACCTFROM')
            ?? new Node('ACCTFROM');

        $rawType = self::id($node->field('ACCTTYPE'));

        return new PzOfxAccount(
            bankId: self::id($node->field('BANKID')),
            branchId: self::id($node->field('BRANCHID')),
            accountId: self::id($node->field('ACCTID')) ?? '',
            type: $type === PzOfxStatementTypeEnum::CREDIT_CARD ? PzOfxAccountTypeEnum::CREDITCARD : PzOfxAccountTypeEnum::tryFromOfx($rawType),
            rawType: $rawType,
            accountKey: self::id($node->field('ACCTKEY')),
        );
    }

    /**
     * @param list<Node> $nodes
     * @return list<PzOfxTransaction>
     */
    private function transactions(array $nodes, PzOfxAccount $account, PzOfxStatementTypeEnum $type, PzOfxProfile $profile): array
    {
        $drafts = [];
        foreach ($nodes as $index => $node) {
            $draft = $this->draft($node, $index, $profile);
            if ($draft !== null) {
                $drafts[] = $draft;
            }
        }

        $fitIdCount = array_count_values(array_filter(array_column($drafts, 'fitId'), static fn (?string $id): bool => $id !== null));
        foreach ($fitIdCount as $fitId => $count) {
            if ($count > 1) {
                $this->warn(PzOfxWarningEnum::DUPLICATE_FIT_ID, ['value' => (string) $fitId, 'count' => $count]);
            }
        }
        $missing = count(array_filter($drafts, static fn (array $d): bool => $d['fitId'] === null));
        if ($missing > 0) {
            $this->warn(PzOfxWarningEnum::MISSING_FIT_ID, ['count' => $missing]);
        }

        $occurrences = [];
        $transactions = [];
        foreach ($drafts as $position => $draft) {
            unset($drafts[$position]);
            $base = implode("\x1F", [
                $type->value,
                $account->key(),
                $draft['localDate'],
                $draft['amount'],
                Text::comparable($draft['description']),
                (string) $draft['checkNumber'],
            ]);
            $occurrence = $occurrences[$base] = ($occurrences[$base] ?? 0) + 1;
            $fingerprint = sha1($base."\x1F".$occurrence);

            $fitId = $draft['fitId'];
            $trusted = $profile->trustFitId && $fitId !== null && $fitIdCount[$fitId] === 1;

            unset($draft['localDate']);
            $transactions[] = new PzOfxTransaction(...[
                ...$draft,
                'fingerprint' => $fingerprint,
                'uniqueId' => $trusted ? $fitId : $fingerprint,
            ]);
        }

        return $transactions;
    }

    /**
     * `null` quando a transação é ignorada (linha de saldo ou inválida sem `strict`).
     *
     * @return array<string, mixed>|null
     */
    private function draft(Node $node, int $index, PzOfxProfile $profile): ?array
    {
        $number = $index + 1;
        $rawType = self::id($node->field('TRNTYPE'));
        $type = PzOfxTransactionTypeEnum::tryFromOfx($rawType);
        if ($type === null && $rawType !== null) {
            $this->warn(PzOfxWarningEnum::UNKNOWN_TRANSACTION_TYPE, ['index' => $number, 'value' => $rawType]);
        }
        $type ??= PzOfxTransactionTypeEnum::OTHER;

        $name = Text::nullIfBlank($node->field('NAME') ?? $node->value('PAYEE', 'NAME'));
        $memo = Text::nullIfBlank($node->field('MEMO'));

        // MEMO decide quando existe: NAME "SALDO" com MEMO de pagamento é transação.
        if ($profile->skipBalanceRows && $this->isBalanceRow($memo ?? $name)) {
            $this->warn(PzOfxWarningEnum::BALANCE_ROW_SKIPPED, ['index' => $number, 'value' => $memo ?? $name]);

            return null;
        }

        $rawAmount = self::id($node->field('TRNAMT'));
        if ($rawAmount === null) {
            $this->reject(PzOfxErrorEnum::MISSING_FIELD, ['index' => $number, 'field' => 'TRNAMT']);

            return null;
        }
        $amount = Decimal::parse($rawAmount);
        if ($amount === null) {
            $this->reject(PzOfxErrorEnum::INVALID_AMOUNT, ['index' => $number, 'field' => 'TRNAMT', 'value' => $rawAmount]);

            return null;
        }

        $posted = $this->postedAt($node, $number);
        if ($posted === null) {
            return null;
        }
        [$postedAt, $postedRaw] = $posted;

        $fitId = self::id($node->field('FITID'));

        return [
            // Dia do arquivo: o fingerprint não depende do fuso.
            'localDate' => (OfxDate::parse($postedRaw, $this->timezone, PzOfxTimezoneModeEnum::LOCAL) ?? $postedAt)->format('Y-m-d'),
            'index' => $index,
            'type' => $type,
            'rawType' => $rawType,
            'postedAt' => $postedAt,
            'userDate' => $this->optionalDate($node->field('DTUSER'), 'DTUSER'),
            'availableAt' => $this->optionalDate($node->field('DTAVAIL'), 'DTAVAIL'),
            'amount' => self::applySign($amount, $type, $profile->amountSign),
            'fitId' => $fitId,
            'name' => $name,
            'memo' => $memo,
            'description' => self::describe($name, $memo, $profile->description),
            'checkNumber' => self::id($node->field('CHECKNUM')),
            'referenceNumber' => self::id($node->field('REFNUM')),
            'serverTransactionId' => self::id($node->field('SRVRTID')),
            'correctFitId' => self::id($node->field('CORRECTFITID')),
            'correctAction' => self::id($node->field('CORRECTACTION')),
            'payeeId' => self::id($node->field('PAYEEID')),
            'sic' => self::id($node->field('SIC')),
            'currency' => self::id($node->value('CURRENCY', 'CURSYM') ?? $node->value('ORIGCURRENCY', 'CURSYM') ?? $node->field('CURRENCY')),
            'raw' => $node->firstFields(),
        ];
    }

    /**
     * DTPOSTED; na falta dele, DTUSER ou o prefixo `AAAAMMDD` do FITID, com aviso.
     *
     * @return array{0: DateTimeImmutable, 1: string}|null data e o texto de onde ela veio
     */
    private function postedAt(Node $node, int $number): ?array
    {
        $raw = self::id($node->field('DTPOSTED'));
        if ($raw !== null) {
            $date = $this->date($raw);
            if ($date === null) {
                $this->reject(PzOfxErrorEnum::INVALID_DATE, ['index' => $number, 'field' => 'DTPOSTED', 'value' => $raw]);

                return null;
            }

            return [$date, $raw];
        }

        $fallbacks = ['DTUSER' => self::id($node->field('DTUSER'))];
        $fitId = (string) self::id($node->field('FITID'));
        if (preg_match('/^(\d{8})/', $fitId, $m)) {
            $fallbacks['FITID'] = $m[1];
        }
        foreach ($fallbacks as $field => $value) {
            $date = $this->date($value);
            if ($date !== null) {
                $this->warn(PzOfxWarningEnum::DATE_FALLBACK, ['index' => $number, 'field' => $field, 'value' => $value]);

                return [$date, (string) $value];
            }
        }

        $this->reject(PzOfxErrorEnum::MISSING_FIELD, ['index' => $number, 'field' => 'DTPOSTED']);

        return null;
    }

    private function balance(?Node $node, string $field): ?PzOfxBalance
    {
        $raw = self::id($node?->field('BALAMT'));
        if ($raw === null) {
            return null;
        }

        $amount = Decimal::parse($raw);
        if ($amount === null) {
            $this->warn(PzOfxWarningEnum::INVALID_OPTIONAL_FIELD, ['field' => $field.'.BALAMT', 'value' => $raw]);

            return null;
        }

        return new PzOfxBalance($amount, $this->optionalDate($node?->field('DTASOF'), $field.'.DTASOF'));
    }

    private function status(Node $parent, string $field): ?PzOfxStatus
    {
        $node = $parent->child('STATUS');
        $code = self::id($node?->field('CODE'));
        if ($node === null || $code === null || ! ctype_digit($code)) {
            return null;
        }

        $status = new PzOfxStatus((int) $code, self::id($node->field('SEVERITY')), Text::nullIfBlank($node->field('MESSAGE')));
        if (! $status->isOk()) {
            $this->warn(PzOfxWarningEnum::STATUS_NOT_OK, [
                'field' => $field,
                'code' => $status->code,
                'severity' => $status->severity,
                'value' => $status->message,
            ]);
        }

        return $status;
    }

    private function date(?string $raw): ?DateTimeImmutable
    {
        return OfxDate::parse($raw, $this->timezone, $this->config->timezoneMode);
    }

    private function optionalDate(?string $raw, string $field): ?DateTimeImmutable
    {
        $raw = self::id($raw);
        if ($raw === null) {
            return null;
        }

        $date = $this->date($raw);
        if ($date === null) {
            $this->warn(PzOfxWarningEnum::INVALID_OPTIONAL_FIELD, ['field' => $field, 'value' => $raw]);
        }

        return $date;
    }

    private function isBalanceRow(?string $text): bool
    {
        return $text !== null && preg_match($this->config->balanceRowPattern, Text::comparable($text)) === 1;
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function reject(PzOfxErrorEnum $error, array $context): void
    {
        $exception = PzOfxParseException::because($error, $context);
        if ($this->config->strict) {
            throw $exception;
        }

        $this->warn(PzOfxWarningEnum::TRANSACTION_SKIPPED, ['index' => $context['index'] ?? null, 'reason' => $exception->getMessage()]);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function warn(PzOfxWarningEnum $code, array $context = []): void
    {
        $this->warnings[] = new PzOfxWarning($code, $context);
    }

    private static function applySign(string $amount, PzOfxTransactionTypeEnum $type, ?PzOfxAmountSignEnum $policy): string
    {
        return match ($policy) {
            PzOfxAmountSignEnum::INVERT => Decimal::negate($amount),
            PzOfxAmountSignEnum::BY_TYPE => match (true) {
                in_array($type->value, self::POSITIVE_TYPES, true) => Decimal::abs($amount),
                in_array($type->value, self::NEGATIVE_TYPES, true) => Decimal::negate(Decimal::abs($amount)),
                default => $amount,
            },
            default => $amount,
        };
    }

    private static function describe(?string $name, ?string $memo, ?PzOfxDescriptionEnum $strategy): string
    {
        return (string) match ($strategy) {
            PzOfxDescriptionEnum::MEMO => $memo,
            PzOfxDescriptionEnum::NAME => $name,
            PzOfxDescriptionEnum::NAME_OR_MEMO => $name ?? $memo,
            PzOfxDescriptionEnum::NAME_AND_MEMO => self::join($name, $memo),
            default => $memo ?? $name,
        };
    }

    private static function join(?string $name, ?string $memo): ?string
    {
        if ($name === null || $memo === null) {
            return $name ?? $memo;
        }
        $a = Text::comparable($name);
        $b = Text::comparable($memo);
        if (str_contains($b, $a)) {
            return $memo;
        }
        if (str_contains($a, $b)) {
            return $name;
        }

        return $name.' - '.$memo;
    }

    /** Busca mais abaixo se o banco não fechou o aggregate anterior (`</BANKTRANLIST>`). */
    private static function section(Node $node, string $name): ?Node
    {
        return $node->child($name) ?? ($node->find($name)[0] ?? null);
    }

    /** Só apara; vazio vira `null`. */
    private static function id(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
