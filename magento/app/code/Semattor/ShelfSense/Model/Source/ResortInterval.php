<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Model\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;

/**
 * How often a category is re-sorted at night. "default" = the global setting.
 */
class ResortInterval extends AbstractSource
{
    public const DEFAULT = 'default';

    public function getAllOptions(): array
    {
        if ($this->_options === null) {
            $this->_options = [
                ['value' => self::DEFAULT, 'label' => __('Default')],
                ['value' => '1', 'label' => __('Every night')],
                ['value' => '2', 'label' => __('Every 2 days')],
                ['value' => '3', 'label' => __('Every 3 days')],
                ['value' => '7', 'label' => __('Weekly')],
            ];
        }
        return $this->_options;
    }
}
