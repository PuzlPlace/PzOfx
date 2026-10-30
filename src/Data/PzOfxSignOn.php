<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Data;

use DateTimeImmutable;
use DateTimeInterface;

final class PzOfxSignOn
{
    public function __construct(
        public readonly ?PzOfxStatus $status,
        public readonly ?DateTimeImmutable $serverDate,
        public readonly ?string $language,
        /** `<ORG>`: texto livre, pouco confiável para identificar o banco. */
        public readonly ?string $organization,
        public readonly ?string $fid,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status?->toArray(),
            'server_date' => $this->serverDate?->format(DateTimeInterface::ATOM),
            'language' => $this->language,
            'organization' => $this->organization,
            'fid' => $this->fid,
        ];
    }
}
