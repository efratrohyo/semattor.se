<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Model\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;

/**
 * Sort strategies selectable per category.
 */
class SortMode extends AbstractSource
{
    public const STANDARD = '';
    public const NEWEST = 'newest';
    public const SMART = 'smart';
    public const BESTSELLER = 'bestseller';

    public function getAllOptions(): array
    {
        if ($this->_options === null) {
            $this->_options = [
                ['value' => self::STANDARD, 'label' => __('Standard (befintlig position – rörs inte)')],
                ['value' => self::NEWEST, 'label' => __('Nyheter först')],
                ['value' => self::SMART, 'label' => __('Mest relevant (smart)')],
                ['value' => self::BESTSELLER, 'label' => __('Bästsäljare')],
            ];
        }
        return $this->_options;
    }

    /**
     * @return string[] Modes that ShelfSense actively manages.
     */
    public static function activeModes(): array
    {
        return [self::NEWEST, self::SMART, self::BESTSELLER];
    }
}
