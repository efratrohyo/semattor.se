<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Console\Command;

use Semattor\ShelfSense\Model\FastEndpoint;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento shelfsense:install-endpoint – copy the fast tracking receiver to pub/ and test it.
 */
class InstallEndpointCommand extends Command
{
    public function __construct(private readonly FastEndpoint $fastEndpoint)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('shelfsense:install-endpoint')
            ->setDescription('ShelfSense: install and test the fast tracking receiver (pub/shelfsense-track.php)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->fastEndpoint->install()) {
            $output->writeln('<error>Could not copy the file to pub/. Check file permissions.</error>');
            return Command::FAILURE;
        }
        $output->writeln('Copied to pub/' . FastEndpoint::FILE);
        if ($this->fastEndpoint->check()) {
            $output->writeln('<info>Works. ShelfSense now uses the fast receiver.</info>');
            return Command::SUCCESS;
        }
        $output->writeln('<comment>The file is there but does not answer from the shop URL.</comment>');
        $output->writeln('On nginx, allow it next to index.php, e.g. in the "location ~ ^/(index|get|static|errors/report|'
            . 'errors/404|errors/503|health_check)\.php$" line add "|shelfsense-track". Then run this command again.');
        $output->writeln('Until then ShelfSense keeps using the normal Magento endpoint (nothing is lost).');
        return Command::SUCCESS;
    }
}
