<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Controller\Adminhtml\Sort;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Semattor\ShelfSense\Model\Applier;

/**
 * "Sort now" button: re-sort the category right away with its SAVED settings.
 */
class Now extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Catalog::categories';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Applier $applier
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $categoryId = (int)$this->getRequest()->getParam('category_id');
        if ($categoryId <= 0) {
            return $result->setData(['error' => (string)__('Save the category first.')]);
        }
        try {
            $row = $this->applier->applyCategory($categoryId);
            return $result->setData([
                'message' => (string)__(
                    '%1: %2 positions changed.',
                    ucfirst((string)($row['action'] ?? 'done')),
                    (int)($row['changed'] ?? 0)
                ),
            ]);
        } catch (\Throwable $e) {
            return $result->setData(['error' => (string)__('Could not sort: %1', $e->getMessage())]);
        }
    }
}
