<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Api\Data;

/**
 * ShelfSense Sorting settings and status of one category.
 */
interface CategoryStatusInterface
{
    /**
     * @return int
     */
    public function getCategoryId(): int;

    /**
     * @return string
     */
    public function getName(): string;

    /**
     * @return string standard|newest|smart|bestseller
     */
    public function getMode(): string;

    /**
     * @return string Comma separated SKUs, empty if none
     */
    public function getPinnedSkus(): string;

    /**
     * @return string|null UTC "Y-m-d H:i:s" of the last sort, null if never / Standard
     */
    public function getLastSorted(): ?string;

    /**
     * @return int Visible products in the category (0 when not loaded)
     */
    public function getTotalProducts(): int;

    /**
     * @return string[] Pinned SKUs that are not in the category (ignored)
     */
    public function getNotInCategory(): array;

    /**
     * @return \Semattor\ShelfSense\Api\Data\PreviewItemInterface[] First products customers see
     */
    public function getPreview(): array;

    /**
     * @return string Test product SKUs, comma separated
     */
    public function getTestSkus(): string;

    /**
     * @return string|null Last day of the test (Y-m-d)
     */
    public function getTestUntil(): ?string;

    /**
     * @return string[] Pinned or test SKUs that are NOT our own brand (shown above own brand products)
     */
    public function getOtherBrandSkus(): array;

    /**
     * @return bool Auto (learning) has enough data for this category
     */
    public function getAutoReady(): bool;

    /**
     * @return string Data collected for Auto, e.g. "9 of 14 days, 1 240 of 2 000 product views"
     */
    public function getAutoProgress(): string;

    /**
     * @return string What happened, in plain English
     */
    public function getMessage(): string;
}
