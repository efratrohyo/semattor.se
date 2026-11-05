<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Model\Api;

use Semattor\ShelfSense\Api\Data\CategoryStatusInterface;

class CategoryStatus implements CategoryStatusInterface
{
    /**
     * @param string[] $notInCategory
     * @param \Semattor\ShelfSense\Api\Data\PreviewItemInterface[] $preview
     */
    public function __construct(
        private readonly int $categoryId,
        private readonly string $name,
        private readonly string $mode,
        private readonly string $pinnedSkus,
        private readonly ?string $lastSorted,
        private readonly int $totalProducts = 0,
        private readonly array $notInCategory = [],
        private readonly array $preview = [],
        private string $message = '',
        private readonly bool $autoReady = false,
        private readonly string $autoProgress = ''
    ) {
    }

    public function getCategoryId(): int { return $this->categoryId; }
    public function getName(): string { return $this->name; }
    public function getMode(): string { return $this->mode; }
    public function getPinnedSkus(): string { return $this->pinnedSkus; }
    public function getLastSorted(): ?string { return $this->lastSorted; }
    public function getTotalProducts(): int { return $this->totalProducts; }
    public function getNotInCategory(): array { return $this->notInCategory; }
    public function getPreview(): array { return $this->preview; }
    public function getMessage(): string { return $this->message; }
    public function getAutoReady(): bool { return $this->autoReady; }
    public function getAutoProgress(): string { return $this->autoProgress; }

    public function withMessage(string $message): self
    {
        $copy = clone $this;
        $copy->message = $message;
        return $copy;
    }
}
