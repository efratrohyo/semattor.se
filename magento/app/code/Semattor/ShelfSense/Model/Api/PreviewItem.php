<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Model\Api;

use Semattor\ShelfSense\Api\Data\PreviewItemInterface;

class PreviewItem implements PreviewItemInterface
{
    public function __construct(
        private readonly int $rank,
        private readonly int $productId,
        private readonly string $sku,
        private readonly string $name,
        private readonly int $standardPosition,
        private readonly int $sold,
        private readonly bool $soldOut,
        private readonly bool $pinned,
        private readonly bool $isNew,
        private readonly ?float $score
    ) {
    }

    public function getRank(): int { return $this->rank; }
    public function getProductId(): int { return $this->productId; }
    public function getSku(): string { return $this->sku; }
    public function getName(): string { return $this->name; }
    public function getStandardPosition(): int { return $this->standardPosition; }
    public function getSold(): int { return $this->sold; }
    public function getSoldOut(): bool { return $this->soldOut; }
    public function getPinned(): bool { return $this->pinned; }
    public function getIsNew(): bool { return $this->isNew; }
    public function getScore(): ?float { return $this->score; }
}
