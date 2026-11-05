<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Controller\Track;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Semattor\ShelfSense\Model\Config;
use Semattor\ShelfSense\Model\Learning;

/**
 * Receives list views and clicks from category pages (navigator.sendBeacon).
 * Body (text/plain JSON): {"c": categoryId, "v": [productIds seen], "k": [productIds clicked]}
 * Stores only counts per day. No customer, session or IP data.
 */
class Collect implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const MAX_IDS = 200;
    private const BOTS = '/bot|crawl|spider|slurp|facebookexternalhit|preview|headless|lighthouse|pingdom|monitor/i';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly Learning $learning,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): Raw
    {
        $result = $this->rawFactory->create()->setHttpResponseCode(204)->setContents('');
        try {
            if (!$this->config->isEnabled() || !$this->config->isTrackingEnabled()) {
                return $result;
            }
            $agent = (string)$this->request->getServer('HTTP_USER_AGENT');
            if ($agent === '' || preg_match(self::BOTS, $agent)) {
                return $result;
            }
            $raw = (string)$this->request->getContent();
            if ($raw === '' || strlen($raw) > 20000) {
                return $result;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                return $result;
            }
            $categoryId = (int)($data['c'] ?? 0);
            $views = $this->ids($data['v'] ?? []);
            $clicks = $this->ids($data['k'] ?? []);
            if ($categoryId > 0 && ($views || $clicks)) {
                $this->learning->record((int)$this->storeManager->getStore()->getId(), $categoryId, $views, $clicks);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ShelfSense tracking: ' . $e->getMessage());
        }
        return $result;
    }

    /**
     * @return int[]
     */
    private function ids(mixed $list): array
    {
        if (!is_array($list)) {
            return [];
        }
        $ids = array_filter(array_map('intval', array_slice($list, 0, self::MAX_IDS)), static fn (int $id): bool => $id > 0);
        return array_values(array_unique($ids));
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true; // anonymous counters only, no state change for the visitor
    }
}
