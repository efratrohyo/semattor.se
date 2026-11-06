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
        private readonly ?float $score,
        private readonly int $views = 0,
        private readonly int $clicks = 0,
        private readonly bool $ownBrand = false,
        private readonly bool $testing = false
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
    public function getViews(): int { return $this->views; }
    public function getClicks(): int { return $this->clicks; }
    public function getOwnBrand(): bool { return $this->ownBrand; }
    public function getTesting(): bool { return $this->testing; }
}
