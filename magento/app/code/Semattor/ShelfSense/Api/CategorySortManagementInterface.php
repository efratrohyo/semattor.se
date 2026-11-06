<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Api;

/**
 * ShelfSense Sorting REST API. See etc/mcp/shelfsense-sorting.md.
 */
interface CategorySortManagementInterface
{
    /**
     * List categories with their ShelfSense option.
     *
     * @param bool $all false = only categories that are not Standard; true = every category
     * @return \Semattor\ShelfSense\Api\Data\CategoryStatusInterface[]
     */
    public function getList(bool $all = false): array;

    /**
     * Settings, last sort and the first products customers see now.
     *
     * @param int $categoryId
     * @param int $limit
     * @return \Semattor\ShelfSense\Api\Data\CategoryStatusInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function get(int $categoryId, int $limit = 12): \Semattor\ShelfSense\Api\Data\CategoryStatusInterface;

    /**
     * Preview an option without saving anything.
     *
     * @param int $categoryId
     * @param string $mode standard|newest|smart|bestseller
     * @param string $pinnedSkus comma separated
     * @param int $limit
     * @param string|null $testSkus comma separated; null = use the saved test products
     * @param string|null $testUntil Y-m-d, last day of the test
     * @return \Semattor\ShelfSense\Api\Data\CategoryStatusInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\InputException
     */
    public function preview(
        int $categoryId,
        string $mode,
        string $pinnedSkus = '',
        int $limit = 12,
        ?string $testSkus = null,
        ?string $testUntil = null
    ): \Semattor\ShelfSense\Api\Data\CategoryStatusInterface;

    /**
     * Save the option (and optionally pinned SKUs) and sort the category now.
     * Mode "standard" restores the old order exactly.
     *
     * @param int $categoryId
     * @param string $mode standard|newest|smart|bestseller
     * @param string|null $pinnedSkus comma separated; null = keep current, "" = remove all
     * @param string|null $testSkus comma separated; null = keep current, "" = stop the test
     * @param string|null $testUntil Y-m-d, last day of the test (required with testSkus)
     * @param bool $allowOtherBrands must be true to pin or test products that are not our own brand.
     *        Only send true after the merchant approved it in the conversation.
     * @return \Semattor\ShelfSense\Api\Data\CategoryStatusInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\InputException
     */
    public function save(
        int $categoryId,
        string $mode,
        ?string $pinnedSkus = null,
        ?string $testSkus = null,
        ?string $testUntil = null,
        bool $allowOtherBrands = false
    ): \Semattor\ShelfSense\Api\Data\CategoryStatusInterface;

    /**
     * Change several categories at once (one after the other). Same rules as save().
     *
     * @param \Semattor\ShelfSense\Api\Data\CategoryUpdateInterface[] $categories
     * @param bool $allowOtherBrands
     * @return \Semattor\ShelfSense\Api\Data\CategoryStatusInterface[]
     * @throws \Magento\Framework\Exception\InputException
     */
    public function saveMany(array $categories, bool $allowOtherBrands = false): array;

    /**
     * Sort the category again now with its saved option (e.g. after changing settings).
     *
     * @param int $categoryId
     * @return \Semattor\ShelfSense\Api\Data\CategoryStatusInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function sortNow(int $categoryId): \Semattor\ShelfSense\Api\Data\CategoryStatusInterface;
}
