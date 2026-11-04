<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use Semattor\ShelfSense\Model\Source\SortMode;

/**
 * Adds the two per-category settings. Both default to empty = "Standard",
 * so no existing category changes behaviour after install.
 */
class AddCategoryAttributes implements DataPatchInterface, PatchRevertableInterface
{
    public const ATTR_MODE = 'shelfsense_mode';
    public const ATTR_PINNED = 'shelfsense_pinned_skus';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $eavSetup->addAttribute(Category::ENTITY, self::ATTR_MODE, [
            'type' => 'varchar',
            'label' => 'ShelfSense: sort products by',
            'input' => 'select',
            'source' => SortMode::class,
            'required' => false,
            'default' => '',
            // Category product positions are global in Magento, so the setting is too.
            'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
            'visible' => true,
            'user_defined' => false,
            'group' => 'ShelfSense',
            'sort_order' => 10,
        ]);

        $eavSetup->addAttribute(Category::ENTITY, self::ATTR_PINNED, [
            'type' => 'text',
            'label' => 'ShelfSense: pinned SKUs on top',
            'input' => 'textarea',
            'required' => false,
            'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
            'visible' => true,
            'user_defined' => false,
            'group' => 'ShelfSense',
            'sort_order' => 20,
        ]);

        return $this;
    }

    public function revert(): void
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $eavSetup->removeAttribute(Category::ENTITY, self::ATTR_MODE);
        $eavSetup->removeAttribute(Category::ENTITY, self::ATTR_PINNED);
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
