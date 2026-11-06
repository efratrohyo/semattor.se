<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Model\Api;

use Semattor\ShelfSense\Api\Data\SettingsInterface;

class Settings implements SettingsInterface
{
    public function __construct(
        private readonly bool $enabled,
        private readonly bool $soldOutLast,
        private readonly int $topCount,
        private readonly int $salesDays,
        private readonly int $trendDays,
        private readonly int $newHalfLifeDays,
        private readonly int $weightSales,
        private readonly int $weightNew,
        private readonly int $weightManual,
        private readonly string $schedule,
        private readonly bool $trackingEnabled,
        private readonly int $autoMinDays,
        private readonly int $autoMinViews,
        private readonly int $autoExploration,
        private readonly int $intervalDays,
        private readonly int $sampleRate
    ) {
    }

    public function getEnabled(): bool { return $this->enabled; }
    public function getSoldOutLast(): bool { return $this->soldOutLast; }
    public function getTopCount(): int { return $this->topCount; }
    public function getSalesDays(): int { return $this->salesDays; }
    public function getTrendDays(): int { return $this->trendDays; }
    public function getNewHalfLifeDays(): int { return $this->newHalfLifeDays; }
    public function getWeightSales(): int { return $this->weightSales; }
    public function getWeightNew(): int { return $this->weightNew; }
    public function getWeightManual(): int { return $this->weightManual; }
    public function getSchedule(): string { return $this->schedule; }
    public function getTrackingEnabled(): bool { return $this->trackingEnabled; }
    public function getAutoMinDays(): int { return $this->autoMinDays; }
    public function getAutoMinViews(): int { return $this->autoMinViews; }
    public function getAutoExploration(): int { return $this->autoExploration; }
    public function getIntervalDays(): int { return $this->intervalDays; }
    public function getSampleRate(): int { return $this->sampleRate; }
}
