<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Cron;

use Psr\Log\LoggerInterface;
use Semattor\ShelfSense\Model\Applier;
use Semattor\ShelfSense\Model\Config;

/**
 * Auto (learning) categories are re-sorted every hour so they learn faster.
 */
class ApplyAuto
{
    public function __construct(
        private readonly Applier $applier,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }
        foreach ($this->applier->applyAuto() as $categoryId => $row) {
            $this->logger->info(sprintf(
                'ShelfSense Auto: category %d (%s) -> %s, %d positions changed',
                $categoryId,
                $row['name'],
                $row['action'],
                $row['changed']
            ));
        }
    }
}
