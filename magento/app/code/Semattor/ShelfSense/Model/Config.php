<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

class Config
{
    private const PREFIX = 'semattor_shelfsense/';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'general/enabled');
    }

    public function isOutOfStockLast(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'general/out_of_stock_last');
    }

    public function getBestsellerDays(): int
    {
        return max(1, $this->int('bestseller/days', 90));
    }

    public function getTrendDays(): int
    {
        return max(1, $this->int('smart/trend_days', 30));
    }

    public function getNewHalfLifeDays(): int
    {
        return max(1, $this->int('smart/new_half_life_days', 30));
    }

    /**
     * @return array{sales: float, new: float, manual: float} Normalised to sum 1.
     */
    public function getSmartWeights(): array
    {
        $w = [
            'sales' => max(0, $this->int('smart/weight_sales', 50)),
            'new' => max(0, $this->int('smart/weight_new', 25)),
            'manual' => max(0, $this->int('smart/weight_manual', 25)),
        ];
        $sum = array_sum($w) ?: 1;
        return array_map(static fn (int $v): float => $v / $sum, $w);
    }

    private function int(string $path, int $default): int
    {
        $value = $this->scopeConfig->getValue(self::PREFIX . $path);
        return is_numeric($value) ? (int)$value : $default;
    }
}
