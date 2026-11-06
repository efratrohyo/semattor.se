<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Api\Data;

/**
 * One category change for PUT /V1/shelfsense/categories (bulk).
 */
interface CategoryUpdateInterface
{
    /**
     * @return int
     */
    public function getCategoryId(): int;

    /**
     * @param int $categoryId
     * @return $this
     */
    public function setCategoryId(int $categoryId): self;

    /**
     * @return string standard|newest|smart|bestseller|auto
     */
    public function getMode(): string;

    /**
     * @param string $mode
     * @return $this
     */
    public function setMode(string $mode): self;

    /**
     * @return string|null
     */
    public function getPinnedSkus(): ?string;

    /**
     * @param string|null $pinnedSkus
     * @return $this
     */
    public function setPinnedSkus(?string $pinnedSkus): self;

    /**
     * @return string|null
     */
    public function getTestSkus(): ?string;

    /**
     * @param string|null $testSkus
     * @return $this
     */
    public function setTestSkus(?string $testSkus): self;

    /**
     * @return string|null
     */
    public function getTestUntil(): ?string;

    /**
     * @param string|null $testUntil
     * @return $this
     */
    public function setTestUntil(?string $testUntil): self;
}
