<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Model\Api;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\InputException;
use Semattor\ShelfSense\Api\Data\SettingsInterface;
use Semattor\ShelfSense\Api\SettingsManagementInterface;

class SettingsManagement implements SettingsManagementInterface
{
    private const P = 'semattor_shelfsense/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $writer,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly TypeListInterface $cacheTypeList
    ) {
    }

    public function get(): SettingsInterface
    {
        $v = fn (string $path) => $this->scopeConfig->getValue(self::P . $path);
        return new Settings(
            (bool)$v('general/enabled'),
            (bool)$v('general/out_of_stock_last'),
            (int)$v('general/top_count'),
            (int)$v('bestseller/days'),
            (int)$v('smart/trend_days'),
            (int)$v('smart/new_half_life_days'),
            (int)$v('smart/weight_sales'),
            (int)$v('smart/weight_new'),
            (int)$v('smart/weight_manual'),
            (string)$v('general/cron_expr')
        );
    }

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
    ): SettingsInterface {
        $changes = [];
        $bool = static fn (?bool $b) => $b === null ? null : ($b ? '1' : '0');
        $positive = static function (?int $i, string $name, int $min) {
            if ($i === null) {
                return null;
            }
            if ($i < $min) {
                throw new InputException(__('%1 must be %2 or more.', $name, $min));
            }
            return (string)$i;
        };

        $changes['general/enabled'] = $bool($enabled);
        $changes['general/out_of_stock_last'] = $bool($soldOutLast);
        $changes['general/top_count'] = $positive($topCount, 'topCount', 0);
        $changes['bestseller/days'] = $positive($salesDays, 'salesDays', 1);
        $changes['smart/trend_days'] = $positive($trendDays, 'trendDays', 1);
        $changes['smart/new_half_life_days'] = $positive($newHalfLifeDays, 'newHalfLifeDays', 1);
        $changes['smart/weight_sales'] = $positive($weightSales, 'weightSales', 0);
        $changes['smart/weight_new'] = $positive($weightNew, 'weightNew', 0);
        $changes['smart/weight_manual'] = $positive($weightManual, 'weightManual', 0);
        if ($schedule !== null) {
            if (count(preg_split('/\s+/', trim($schedule))) !== 5) {
                throw new InputException(__('schedule must be a cron expression with 5 parts, e.g. "15 3 * * *".'));
            }
            $changes['general/cron_expr'] = trim($schedule);
        }

        $changes = array_filter($changes, static fn ($value) => $value !== null);
        foreach ($changes as $path => $value) {
            $this->writer->save(self::P . $path, $value);
        }
        if ($changes) {
            $this->cacheTypeList->cleanType('config');
            $this->reinitableConfig->reinit();
        }
        return $this->get();
    }
}
