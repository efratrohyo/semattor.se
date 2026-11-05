<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Api\Data;

/**
 * Global ShelfSense Sorting settings (Stores > Configuration > Catalog > ShelfSense Sorting).
 */
interface SettingsInterface
{
    /**
     * @return bool
     */
    public function getEnabled(): bool;

    /**
     * @return bool
     */
    public function getSoldOutLast(): bool;

    /**
     * @return int Top X products that get an exact order (0 = all)
     */
    public function getTopCount(): int;

    /**
     * @return int Days of sales counted
     */
    public function getSalesDays(): int;

    /**
     * @return int Recent sales window in days (counts double)
     */
    public function getTrendDays(): int;

    /**
     * @return int New product boost halves every X days
     */
    public function getNewHalfLifeDays(): int;

    /**
     * @return int Most relevant: weight of sales
     */
    public function getWeightSales(): int;

    /**
     * @return int Most relevant: weight of new products
     */
    public function getWeightNew(): int;

    /**
     * @return int Most relevant: weight of your Standard position numbers
     */
    public function getWeightManual(): int;

    /**
     * @return string Cron expression for the automatic re-sort
     */
    public function getSchedule(): string;
}
