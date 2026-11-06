<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * "Test products": SKUs shown on a fixed place (default place 5) until a date, to test them.
 */
class AddTestAttributes implements DataPatchInterface
{
    public const ATTR_TEST_SKUS = 'shelfsense_test_skus';
    public const ATTR_TEST_UNTIL = 'shelfsense_test_until';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $eavSetup->addAttribute(Category::ENTITY, self::ATTR_TEST_SKUS, [
            'type' => 'text',
            'label' => 'ShelfSense: test products',
            'input' => 'text',
            'required' => false,
            'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
            'visible' => true,
            'user_defined' => false,
            'group' => 'ShelfSense',
            'sort_order' => 30,
        ]);
        $eavSetup->addAttribute(Category::ENTITY, self::ATTR_TEST_UNTIL, [
            'type' => 'datetime',
            'label' => 'ShelfSense: test until',
            'input' => 'date',
            'backend' => \Magento\Eav\Model\Entity\Attribute\Backend\Datetime::class,
            'required' => false,
            'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
            'visible' => true,
            'user_defined' => false,
            'group' => 'ShelfSense',
            'sort_order' => 40,
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
