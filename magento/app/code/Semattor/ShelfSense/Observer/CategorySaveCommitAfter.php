<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Semattor\ShelfSense\Model\Applier;

/**
 * When a category is saved in admin: sort it now if a mode is chosen, or restore
 * the original order if it was switched back to "Standard". Categories that were
 * never managed by ShelfSense are left completely alone.
 */
class CategorySaveCommitAfter implements ObserverInterface
{
    public function __construct(
        private readonly Applier $applier,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $category = $observer->getEvent()->getData('category') ?? $observer->getEvent()->getData('object');
        if (!$category || !$category->getId()) {
            return;
        }
        try {
            // Set by the admin category save when the "Products in Category" grid was posted.
            $posted = $category->getData('posted_products');
            $this->applier->applyCategory(
                (int)$category->getId(),
                is_array($posted) ? array_map('intval', $posted) : null
            );
        } catch (\Throwable $e) {
            // Never break a category save because of sorting.
            $this->logger->error('ShelfSense: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
