<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Semattor\ShelfSense\Model\Source\ResortInterval;

/**
 * Per category: how often to re-sort ("default" = global setting).
 */
class AddIntervalAttribute implements DataPatchInterface
{
    public const ATTR_INTERVAL = 'shelfsense_interval';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup])
            ->addAttribute(Category::ENTITY, self::ATTR_INTERVAL, [
                'type' => 'varchar',
                'label' => 'ShelfSense: re-sort',
                'input' => 'select',
                'source' => ResortInterval::class,
                'required' => false,
                'default' => ResortInterval::DEFAULT,
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible' => true,
                'user_defined' => false,
                'group' => 'ShelfSense',
                'sort_order' => 15,
            ]);
        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddCategoryAttributes::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
