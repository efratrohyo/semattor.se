<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Api\Data;

/**
 * One product in the order customers see under "Populärt".
 */
interface PreviewItemInterface
{
    /**
     * @return int Place in the list, 1 = first
     */
    public function getRank(): int;

    /**
     * @return int
     */
    public function getProductId(): int;

    /**
     * @return string
     */
    public function getSku(): string;

    /**
     * @return string
     */
    public function getName(): string;

    /**
     * @return int Standard position number
     */
    public function getStandardPosition(): int;

    /**
     * @return int Units sold in the sales window (default 90 days)
     */
    public function getSold(): int;

    /**
     * @return bool
     */
    public function getSoldOut(): bool;

    /**
     * @return bool
     */
    public function getPinned(): bool;

    /**
     * @return bool Added in the last 60 days
     */
    public function getIsNew(): bool;

    /**
     * @return float|null Score 0-1, only for mode "smart"
     */
    public function getScore(): ?float;
}
