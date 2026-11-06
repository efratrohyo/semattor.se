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
        private readonly \Semattor\ShelfSense\Model\FastEndpoint $fastEndpoint,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getCategoryId(): int
    {
        $category = $this->layerResolver->get()->getCurrentCategory();
        return $category ? (int)$category->getId() : 0;
    }

    public function getSampleRate(): int
    {
        return $this->config->getSampleRate();
    }

    public function getCollectUrl(): string
    {
        return $this->fastEndpoint->isAvailable()
            ? $this->fastEndpoint->url()
            : $this->getUrl('shelfsense/track/collect');
    }

    public function getStoreId(): int
    {
        return (int)$this->_storeManager->getStore()->getId();
    }

    protected function _toHtml(): string
    {
        if (!$this->config->isEnabled() || !$this->config->isTrackingEnabled() || !$this->getCategoryId()) {
            return '';
        }
        return parent::_toHtml();
    }
}
