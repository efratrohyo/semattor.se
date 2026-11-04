<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Controller\Adminhtml\Preview;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Semattor\ShelfSense\Model\Applier;

/**
 * Returns the first products customers will see under "Populärt" for the values
 * currently on screen in the category form. Read only – nothing is saved.
 */
class Index extends Action implements HttpGetActionInterface
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
            return $result->setData(['error' => (string)__('Save the category first to see a preview.')]);
        }
        try {
            return $result->setData($this->applier->previewWith(
                $categoryId,
                (string)$this->getRequest()->getParam('mode', ''),
                (string)$this->getRequest()->getParam('pinned', '')
            ));
        } catch (\Throwable $e) {
            return $result->setData(['error' => (string)__('Could not load the preview: %1', $e->getMessage())]);
        }
    }
}
