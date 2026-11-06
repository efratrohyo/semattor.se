<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Cron;

use Psr\Log\LoggerInterface;
use Semattor\ShelfSense\Model\Applier;
use Semattor\ShelfSense\Model\Config;

class ApplyAll
{
    public function __construct(
        private readonly Applier $applier,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly \Semattor\ShelfSense\Model\Learning $learning,
        private readonly \Semattor\ShelfSense\Model\FastEndpoint $fastEndpoint
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }
        // Use the fast tracking receiver only if it answers (falls back to Magento otherwise).
        $this->fastEndpoint->check();
        // Keep 120 days of view/click statistics.
        $this->learning->purgeOlderThan(120);
        foreach ($this->applier->applyAll(null, true) as $categoryId => $row) {
            $this->logger->info(sprintf(
                'ShelfSense: category %d (%s) mode=%s -> %s, %d positions changed',
                $categoryId,
                $row['name'],
                $row['mode'] ?: 'standard',
                $row['action'],
                $row['changed']
            ));
        }
    }
}
