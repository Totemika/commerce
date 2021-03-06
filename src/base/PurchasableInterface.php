<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\base;

use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;

/**
 * Interface Purchasable
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
interface PurchasableInterface
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the ID of the Purchasable element that will be be added to the line item.
     * This element should meet the Purchasable Interface.
     *
     * @return int
     */
    public function getPurchasableId(): int;

    /**
     * Returns the base price the item will be added to the line item with.
     *
     * @return float decimal(14,4)
     */
    public function getPrice(): float;

    /**
     * Returns the base price the item will be added to the line item with.
     * It provides opportunity to populate the salePrice if sales have not already been applied.
     *
     * @return float decimal(14,4)
     */
    public function getSalePrice(): float;

    /**
     * Returns a unique code. Unique as per the commerce_purchasables table.
     *
     * @return string
     */
    public function getSku(): string;

    /**
     * Returns your element's title or any additional descriptive information.
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Returns the purchasable's tax category ID.
     *
     * @return int
     */
    public function getTaxCategoryId(): int;

    /**
     * Returns the purchasable's shipping category ID.
     *
     * @return int
     */
    public function getShippingCategoryId(): int;

    /**
     * Returns whether the purchasable is currently available for purchase.
     *
     * @return bool
     */
    public function getIsAvailable(): bool;

    /**
     * Populates the line item when this purchasable is found on it. Called when
     * Purchasable is added to the cart and when the cart recalculates.
     * This is your chance to modify the weight, height, width, length, price
     * and saleAmount. This is called before any onPopulateLineItem event listener.
     *
     * @param LineItem $lineItem
     */
    public function populateLineItem(LineItem $lineItem);

    /**
     * Returns an array of data that is serializable to json for storing a line
     * item at time of adding to the cart or order.
     *
     * @return array
     */
    public function getSnapShot(): array;

    /**
     * Returns any validation rules this purchasable required the line item to have.
     *
     * @param LineItem $lineItem
     * @return array
     */
    public function getLineItemRules(LineItem $lineItem): array;

    /**
     * Runs any logic needed for this purchasable after it was on an order that was just completed.
     *
     * This is called for each line item the purchasable was contained within.
     *
     * @param Order $order
     * @param LineItem $lineItem
     * @return void
     */
    public function afterOrderComplete(Order $order, LineItem $lineItem);

    /**
     * Returns whether this purchasable has free shipping.
     *
     * @return bool
     */
    public function hasFreeShipping(): bool;

    /**
     * Returns whether this purchasable can be subject to discounts or sales.
     *
     * @return bool
     */
    public function getIsPromotable(): bool;

    /**
     * Returns the source param used for knowing if a promotion category is related to this purchasable.
     *
     * @return mixed
     */
    public function getPromotionRelationSource();
}
