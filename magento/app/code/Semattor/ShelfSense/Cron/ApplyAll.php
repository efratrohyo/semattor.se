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
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }
        foreach ($this->applier->applyAll() as $categoryId => $row) {
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
