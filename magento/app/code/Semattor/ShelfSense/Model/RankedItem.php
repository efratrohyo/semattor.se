<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Model;

/**
 * One product in a category with every signal ShelfSense knows about it.
 */
class RankedItem
{
    public float $score = 0.0;
    public bool $pinned = false;
    public int $pinIndex = PHP_INT_MAX;
    public string $name = '';
    public int $views = 0;
    public int $clicks = 0;
    public bool $ownBrand = false;
    public bool $testing = false;

    public function __construct(
        public readonly int $productId,
        public readonly string $sku,
        public readonly int $baselinePosition,
        public readonly int $newTimestamp,
        public readonly float $qtySold,
        public readonly float $qtyTrend,
        public readonly bool $sellable
    ) {
    }
}
