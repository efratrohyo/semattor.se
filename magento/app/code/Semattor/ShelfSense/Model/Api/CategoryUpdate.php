<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Model\Api;

use Semattor\ShelfSense\Api\Data\CategoryUpdateInterface;

class CategoryUpdate implements CategoryUpdateInterface
{
    private int $categoryId = 0;
    private string $mode = '';
    private ?string $pinnedSkus = null;
    private ?string $testSkus = null;
    private ?string $testUntil = null;

    public function getCategoryId(): int { return $this->categoryId; }
    public function setCategoryId(int $categoryId): self { $this->categoryId = $categoryId; return $this; }
    public function getMode(): string { return $this->mode; }
    public function setMode(string $mode): self { $this->mode = $mode; return $this; }
    public function getPinnedSkus(): ?string { return $this->pinnedSkus; }
    public function setPinnedSkus(?string $pinnedSkus): self { $this->pinnedSkus = $pinnedSkus; return $this; }
    public function getTestSkus(): ?string { return $this->testSkus; }
    public function setTestSkus(?string $testSkus): self { $this->testSkus = $testSkus; return $this; }
    public function getTestUntil(): ?string { return $this->testUntil; }
    public function setTestUntil(?string $testUntil): self { $this->testUntil = $testUntil; return $this; }
}
