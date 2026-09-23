<?php

namespace App\Services\Inventory;

/**
 * Where a stock posting comes from: the source document, who did it and why.
 * Every ledger row written by InventoryPostingService carries one of these.
 */
final class PostingContext
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $referenceType,
        public readonly ?int $referenceId = null,
        public readonly ?string $notes = null,
        public readonly ?int $userId = null,
        public readonly ?string $operatorName = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function with(?string $notes = null, array $meta = []): self
    {
        return new self(
            $this->referenceType,
            $this->referenceId,
            $notes ?? $this->notes,
            $this->userId,
            $this->operatorName,
            array_merge($this->meta, $meta),
        );
    }
}
