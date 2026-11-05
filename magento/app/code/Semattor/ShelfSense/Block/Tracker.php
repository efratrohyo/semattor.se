<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Block;

use Magento\Catalog\Model\Layer\Resolver;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Semattor\ShelfSense\Model\Config;

class Tracker extends Template
{
    public function __construct(
        Context $context,
        private readonly Resolver $layerResolver,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getCategoryId(): int
    {
        $category = $this->layerResolver->get()->getCurrentCategory();
        return $category ? (int)$category->getId() : 0;
    }

    public function getCollectUrl(): string
    {
        return $this->getUrl('shelfsense/track/collect');
    }

    protected function _toHtml(): string
    {
        if (!$this->config->isEnabled() || !$this->config->isTrackingEnabled() || !$this->getCategoryId()) {
            return '';
        }
        return parent::_toHtml();
    }
}
