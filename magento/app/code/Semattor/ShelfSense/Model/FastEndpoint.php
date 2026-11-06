<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\FlagManager;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The fast tracking receiver (pub/shelfsense-track.php) is used only when it is installed AND
 * answers from the shop's own URL. This is checked every night and after installing it.
 * Otherwise the normal Magento endpoint is used, so no data is lost.
 */
class FastEndpoint
{
    public const FILE = 'shelfsense-track.php';
    private const FLAG = 'semattor_shelfsense_fast_endpoint';

    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly Curl $curl,
        private readonly StoreManagerInterface $storeManager,
        private readonly DirectoryList $directoryList,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isAvailable(): bool
    {
        return (bool)$this->flagManager->getFlagData(self::FLAG) && is_file($this->targetPath());
    }

    public function url(): string
    {
        return $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB) . self::FILE;
    }

    /**
     * Copy the receiver from the module into pub/.
     */
    public function install(): bool
    {
        $source = dirname(__DIR__) . '/pub/' . self::FILE;
        return is_file($source) && @copy($source, $this->targetPath());
    }

    /**
     * Ping the receiver through the public URL of the default store.
     */
    public function check(): bool
    {
        $ok = false;
        if (is_file($this->targetPath())) {
            try {
                $url = $this->storeManager->getDefaultStoreView()->getBaseUrl(UrlInterface::URL_TYPE_WEB)
                    . self::FILE . '?ping=1';
                $this->curl->setTimeout(5);
                $this->curl->get($url);
                $headers = array_change_key_case($this->curl->getHeaders(), CASE_LOWER);
                $ok = $this->curl->getStatus() === 204 && isset($headers['x-shelfsense']);
            } catch (\Throwable $e) {
                $this->logger->info('ShelfSense fast endpoint check failed: ' . $e->getMessage());
            }
        }
        if ($ok) {
            $this->flagManager->saveFlag(self::FLAG, time());
        } else {
            $this->flagManager->deleteFlag(self::FLAG);
        }
        return $ok;
    }

    private function targetPath(): string
    {
        return $this->directoryList->getPath(DirectoryList::PUB) . '/' . self::FILE;
    }
}
