<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Api;

/**
 * Read and change the global ShelfSense Sorting settings.
 */
interface SettingsManagementInterface
{
    /**
     * @return \Semattor\ShelfSense\Api\Data\SettingsInterface
     */
    public function get(): \Semattor\ShelfSense\Api\Data\SettingsInterface;

    /**
     * Change only the fields you send. Takes effect at the next sort
     * (category save, next night, or POST /V1/shelfsense/categories/:categoryId/sort).
     *
     * @param bool|null $enabled
     * @param bool|null $soldOutLast
     * @param int|null $topCount
     * @param int|null $salesDays
     * @param int|null $trendDays
     * @param int|null $newHalfLifeDays
     * @param int|null $weightSales
     * @param int|null $weightNew
     * @param int|null $weightManual
     * @param string|null $schedule
     * @return \Semattor\ShelfSense\Api\Data\SettingsInterface
     * @throws \Magento\Framework\Exception\InputException
     */
    public function update(
        ?bool $enabled = null,
        ?bool $soldOutLast = null,
        ?int $topCount = null,
        ?int $salesDays = null,
        ?int $trendDays = null,
        ?int $newHalfLifeDays = null,
        ?int $weightSales = null,
        ?int $weightNew = null,
        ?int $weightManual = null,
        ?string $schedule = null
    ): \Semattor\ShelfSense\Api\Data\SettingsInterface;
}
