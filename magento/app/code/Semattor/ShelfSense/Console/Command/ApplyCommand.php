<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Semattor\ShelfSense\Model\Applier;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento shelfsense:apply                       – run all managed categories now
 * bin/magento shelfsense:apply --category=12,34      – only these
 * bin/magento shelfsense:apply --category=12 --dry-run – show the ranking, change nothing
 */
class ApplyCommand extends Command
{
    public function __construct(
        private readonly Applier $applier,
        private readonly State $state
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('shelfsense:apply')
            ->setDescription('ShelfSense: re-sort categories that have a sorting mode chosen')
            ->addOption('category', 'c', InputOption::VALUE_REQUIRED, 'Comma separated category IDs')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview the order for --category without saving');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Throwable) {
            // already set
        }

        $ids = $input->getOption('category')
            ? array_map('intval', explode(',', (string)$input->getOption('category')))
            : null;

        if ($input->getOption('dry-run')) {
            if ($ids === null) {
                $output->writeln('<error>--dry-run needs --category=ID</error>');
                return Command::FAILURE;
            }
            foreach ($ids as $id) {
                $output->writeln("<info>Category $id</info>");
                $items = $this->applier->preview($id);
                if ($items === []) {
                    $output->writeln('  Standard – ShelfSense does not touch this category.');
                    continue;
                }
                $table = new Table($output);
                $table->setHeaders(['#', 'SKU', 'orig pos', 'sold', 'trend', 'new date', 'score', 'flags']);
                foreach ($items as $i => $item) {
                    $table->addRow([
                        $i + 1,
                        $item->sku,
                        $item->baselinePosition,
                        $item->qtySold,
                        $item->qtyTrend,
                        $item->newTimestamp ? gmdate('Y-m-d', $item->newTimestamp) : '-',
                        round($item->score, 3),
                        trim(($item->pinned ? 'pinned ' : '') . ($item->sellable ? '' : 'sold-out')),
                    ]);
                }
                $table->render();
            }
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Category', 'Mode', 'Result', 'Changed']);
        foreach ($this->applier->applyAll($ids) as $id => $row) {
            $table->addRow([$id, $row['name'], $row['mode'] ?: 'standard', $row['action'], $row['changed']]);
        }
        $table->render();
        return Command::SUCCESS;
    }
}
