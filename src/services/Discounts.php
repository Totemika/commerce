<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\events\MatchLineItemEvent;
use craft\commerce\models\Discount;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin;
use craft\commerce\records\CustomerDiscountUse as CustomerDiscountUseRecord;
use craft\commerce\records\Discount as DiscountRecord;
use craft\commerce\records\DiscountCategory as DiscountCategoryRecord;
use craft\commerce\records\DiscountPurchasable as DiscountPurchasableRecord;
use craft\commerce\records\DiscountUserGroup as DiscountUserGroupRecord;
use craft\db\Query;
use craft\elements\Category;
use DateTime;
use yii\base\Component;
use yii\base\Exception;
use yii\db\Expression;

/**
 * Discount service.
 *
 * @property array|Discount[] $allDiscounts
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class Discounts extends Component
{
    // Properties
    // =========================================================================

    /**
     * @var Discount[]
     */
    private $_allDiscounts;

    // Constants
    // =========================================================================

    /**
     * @event MatchLineItemEvent The event that is triggered when a line item is matched with a discount
     * You may set [[MatchLineItemEvent::isValid]] to `false` to prevent the application of the matched discount.
     *
     * Plugins can get notified before an item is removed from the cart.
     *
     * ```php
     * use craft\commerce\events\MatchLineItemEvent;
     * use craft\commerce\services\Discounts;
     * use yii\base\Event;
     *
     * Event::on(Discounts::class, Discounts::EVENT_BEFORE_MATCH_LINE_ITEM, function(MatchLineItemEvent $e) {
     *      // Maybe check some business rules and prevent a match from happening in some cases.
     * });
     * ```
     */
    const EVENT_BEFORE_MATCH_LINE_ITEM = 'beforeMatchLineItem';

    // Public Methods
    // =========================================================================

    /**
     * Get a discount by its ID.
     *
     * @param int $id
     * @return Discount|null
     */
    public function getDiscountById($id)
    {
        foreach ($this->getAllDiscounts() as $discount) {
            if ($discount->id == $id) {
                return $discount;
            }
        }

        return null;
    }

    /**
     * Get all discounts.
     *
     * @return Discount[]
     */
    public function getAllDiscounts(): array
    {
        if (null === $this->_allDiscounts) {
            $discounts = $this->_createDiscountQuery()
                ->addSelect([
                    'dp.purchasableId',
                    'dpt.categoryId',
                    'dug.userGroupId',
                ])
                ->leftJoin('{{%commerce_discount_purchasables}} dp', '[[dp.discountId]]=[[discounts.id]]')
                ->leftJoin('{{%commerce_discount_categories}} dpt', '[[dpt.discountId]]=[[discounts.id]]')
                ->leftJoin('{{%commerce_discount_usergroups}} dug', '[[dug.discountId]]=[[discounts.id]]')
                ->all();

            $allDiscountsById = [];
            $purchasables = [];
            $categories = [];
            $userGroups = [];

            foreach ($discounts as $discount) {
                $id = $discount['id'];
                if ($discount['purchasableId']) {
                    $purchasables[$id][] = $discount['purchasableId'];
                }

                if ($discount['categoryId']) {
                    $categories[$id][] = $discount['categoryId'];
                }

                if ($discount['userGroupId']) {
                    $userGroups[$id][] = $discount['userGroupId'];
                }

                unset($discount['purchasableId'], $discount['userGroupId'], $discount['categoryId']);

                if (!isset($allDiscountsById[$id])) {
                    $allDiscountsById[$id] = new Discount($discount);
                }
            }

            foreach ($allDiscountsById as $id => $discount) {
                $discount->setPurchasableIds($purchasables[$id] ?? []);
                $discount->setCategoryIds($categories[$id] ?? []);
                $discount->setUserGroupIds($userGroups[$id] ?? []);
            }

            $this->_allDiscounts = $allDiscountsById;
        }

        return $this->_allDiscounts;
    }

    /**
     * Populates a discount's relations.
     *
     * @param Discount $discount
     */
    public function populateDiscountRelations(Discount $discount)
    {
        $rows = (new Query())->select(
            'dp.purchasableId,
            dpt.categoryId,
            dug.userGroupId')
            ->from('{{%commerce_discounts}} discounts')
            ->leftJoin('{{%commerce_discount_purchasables}} dp', '[[dp.discountId]]=[[discounts.id]]')
            ->leftJoin('{{%commerce_discount_categories}} dpt', '[[dpt.discountId]]=[[discounts.id]]')
            ->leftJoin('{{%commerce_discount_usergroups}} dug', '[[dug.discountId]]=[[discounts.id]]')
            ->where(['discounts.id' => $discount->id])
            ->all();

        $purchsableIds = [];
        $categoryIds = [];
        $userGroupIds = [];

        foreach ($rows as $row) {
            if ($row['purchasableId']) {
                $purchsableIds[] = $row['purchasableId'];
            }

            if ($row['categoryId']) {
                $categoryIds[] = $row['categoryId'];
            }

            if ($row['userGroupId']) {
                $userGroupIds[] = $row['userGroupId'];
            }
        }

        $discount->setPurchasableIds($purchsableIds);
        $discount->setCategoryIds($categoryIds);
        $discount->setUserGroupIds($userGroupIds);
    }

    /**
     * Fetches a discount by its code and tries to apply it to the current cart.
     *
     * @param string $code
     * @param int|null $customerId
     * @param string|null $explanation
     * @return bool
     */
    public function matchCode(string $code, int $customerId = null, string &$explanation = null): bool
    {
        $discount = $this->getDiscountByCode($code);

        if (!$discount) {
            $explanation = Craft::t('commerce', 'Coupon not valid');
            return false;
        }

        if ($discount->totalUseLimit > 0 && $discount->totalUses >= $discount->totalUseLimit) {
            $explanation = Craft::t('commerce', 'Discount use has reached its limit');
            return false;
        }

        $now = new DateTime();
        $from = $discount->dateFrom;
        $to = $discount->dateTo;
        if (($from && $from > $now) || ($to && $to < $now)) {
            $explanation = Craft::t('commerce', 'Discount is out of date');

            return false;
        }

        $plugin = Plugin::getInstance();

        if (!$discount->allGroups) {
            $customer = $customerId ? $plugin->getCustomers()->getCustomerById($customerId) : null;
            $user = $customer ? $customer->getUser() : null;
            $groupIds = $user ? Plugin::getInstance()->getCustomers()->getUserGroupIdsForUser($user) : [];
            if (empty(array_intersect($groupIds, $discount->getUserGroupIds()))) {
                $explanation = Craft::t('commerce', 'Discount is not allowed for the customer');

                return false;
            }
        }

        if ($customerId) {
            // The 'Per User Limit' can only be tracked against logged in users since guest customers are re-generated often
            if ($discount->perUserLimit > 0 && !Craft::$app->getUser()->isLoggedIn()) {
                $explanation = Craft::t('commerce', 'Discount is limited to use by logged in users only.');

                return false;
            }

            $allUsedUp = (new Query())
                ->select('id')
                ->from('{{%commerce_customer_discountuses}}')
                ->where(['>=', 'uses', $discount->perUserLimit])
                ->one();

            if ($allUsedUp) {
                $explanation = Craft::t('commerce', 'You can not use this discount anymore');

                return false;
            }
        }

        if ($discount->perEmailLimit > 0) {
            $cart = $plugin->getCarts()->getCart();
            $email = $cart->email;

            if ($email) {
                $previousOrders = $plugin->getOrders()->getOrdersByEmail($email);

                $usedCount = 0;
                foreach ($previousOrders as $order) {
                    if (strcasecmp($order->couponCode, $code) == 0) {
                        $usedCount++;
                    }
                }

                if ($usedCount >= $discount->perEmailLimit) {
                    $explanation = Craft::t('commerce', 'This coupon limited to {limit} uses.', [
                        'limit' => $discount->perEmailLimit,
                    ]);

                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Returns an enabled discount by its code.
     *
     * @param string $code
     * @return Discount|null
     */
    public function getDiscountByCode($code)
    {
        if (!$code) {
            return null;
        }

        $result = $this->_createDiscountQuery()
            ->where(['code' => $code, 'enabled' => true])
            ->one();

        return $result ? new Discount($result) : null;
    }

    /**
     * Match a line item against a discount.
     *
     * @param LineItem $lineItem
     * @param Discount $discount
     * @return bool
     */
    public function matchLineItem(LineItem $lineItem, Discount $discount): bool
    {
        if ($lineItem->onSale && $discount->excludeOnSale) {
            return false;
        }

        // can't match something not promotable
        if (!$lineItem->purchasable->getIsPromotable()) {
            return false;
        }

        if ($discount->getPurchasableIds() && !$discount->allPurchasables) {
            $purchasableId = $lineItem->purchasableId;
            if (!\in_array($purchasableId, $discount->getPurchasableIds(), true)) {
                return false;
            }
        }

        if ($discount->getCategoryIds() && !$discount->allCategories && $lineItem->getPurchasable()) {
            $purchasable = $lineItem->getPurchasable();

            if (!$purchasable) {
                return false;
            }

            $relatedTo = ['sourceElement' => $purchasable->getPromotionRelationSource()];
            $relatedCategories = Category::find()->relatedTo($relatedTo)->ids();
            $purchasableIsRelateToOneOrMoreCategories = (bool)array_intersect($relatedCategories, $discount->getCategoryIds());
            if (!$purchasableIsRelateToOneOrMoreCategories) {
                return false;
            }
        }

        // Raise the 'beforeMatchLineItem' event
        $event = new MatchLineItemEvent([
            'lineItem' => $lineItem,
            'discount' => $discount
        ]);

        $this->trigger(self::EVENT_BEFORE_MATCH_LINE_ITEM, $event);

        return $event->isValid;
    }

    /**
     * Save a discount.
     *
     * @param Discount $model the discount being saved
     * @param bool $runValidation should we validate this discount before saving.
     * @return bool
     * @throws \Exception
     */
    public function saveDiscount(Discount $model, bool $runValidation = true): bool
    {
        if ($model->id) {
            $record = DiscountRecord::findOne($model->id);

            if (!$record) {
                throw new Exception(Craft::t('commerce', 'No discount exists with the ID “{id}”', ['id' => $model->id]));
            }
        } else {
            $record = new DiscountRecord();
        }

        if ($runValidation && !$model->validate()) {
            Craft::info('Discount not saved due to validation error.', __METHOD__);

            return false;
        }

        $record->name = $model->name;
        $record->description = $model->description;
        $record->dateFrom = $model->dateFrom;
        $record->dateTo = $model->dateTo;
        $record->enabled = $model->enabled;
        $record->stopProcessing = $model->stopProcessing;
        $record->purchaseTotal = $model->purchaseTotal;
        $record->purchaseQty = $model->purchaseQty;
        $record->maxPurchaseQty = $model->maxPurchaseQty;
        $record->baseDiscount = $model->baseDiscount;
        $record->perItemDiscount = $model->perItemDiscount;
        $record->percentDiscount = $model->percentDiscount;
        $record->percentageOffSubject = $model->percentageOffSubject;
        $record->freeShipping = $model->freeShipping;
        $record->excludeOnSale = $model->excludeOnSale;
        $record->perUserLimit = $model->perUserLimit;
        $record->perEmailLimit = $model->perEmailLimit;
        $record->totalUseLimit = $model->totalUseLimit;

        $record->sortOrder = $record->sortOrder ?: 999;
        $record->code = $model->code ?: null;

        $record->allGroups = $model->allGroups = empty($model->getUserGroupIds());
        $record->allCategories = $model->allCategories = empty($model->getCategoryIds());
        $record->allPurchasables = $model->allPurchasables = empty($model->getPurchasableIds());

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $record->save(false);
            $model->id = $record->id;

            DiscountUserGroupRecord::deleteAll(['discountId' => $model->id]);
            DiscountPurchasableRecord::deleteAll(['discountId' => $model->id]);
            DiscountCategoryRecord::deleteAll(['discountId' => $model->id]);

            foreach ($model->getUserGroupIds() as $groupId) {
                $relation = new DiscountUserGroupRecord;
                $relation->userGroupId = $groupId;
                $relation->discountId = $model->id;
                $relation->save(false);
            }

            foreach ($model->getCategoryIds() as $categoryId) {
                $relation = new DiscountCategoryRecord();
                $relation->categoryId = $categoryId;
                $relation->discountId = $model->id;
                $relation->save(false);
            }

            foreach ($model->getPurchasableIds() as $purchasableId) {
                $relation = new DiscountPurchasableRecord();
                $relation->purchasableId = $purchasableId;
                $relation->discountId = $model->id;
                $relation->save(false);
            }

            $transaction->commit();

            return true;
        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Delete a discount by its ID.
     *
     * @param int $id
     * @return bool
     */
    public function deleteDiscountById($id): bool
    {
        $record = DiscountRecord::findOne($id);

        if ($record) {
            return $record->delete();
        }

        return false;
    }

    /**
     * Clears a coupon's usage history.
     *
     * @param int $id the coupon's ID
     */
    public function clearCouponUsageHistoryById(int $id)
    {
        $db = Craft::$app->getDb();

        $db->createCommand()
            ->delete('{{%commerce_customer_discountuses}}', ['discountId' => $id])
            ->execute();

        $db->createCommand()
            ->update('{{%commerce_discounts}}', ['totalUses' => 0], ['id' => $id])
            ->execute();
    }

    /**
     * Reorder discounts by an array of ids.
     *
     * @param array $ids
     * @return bool
     */
    public function reorderDiscounts(array $ids): bool
    {
        foreach ($ids as $sortOrder => $id) {
            Craft::$app->getDb()->createCommand()
                ->update('{{%commerce_discounts}}', ['sortOrder' => $sortOrder + 1], ['id' => $id])
                ->execute();
        }

        return true;
    }

    /**
     * Updates discount uses counters.
     *
     * @param Order $order
     */
    public function orderCompleteHandler($order)
    {
        if (!$order->couponCode) {
            return;
        }

        /** @var DiscountRecord $record */
        $record = DiscountRecord::find()->where(['code' => $order->couponCode])->one();
        if (!$record || !$record->id) {
            return;
        }

        if ($record->totalUseLimit) {
            // Increment total uses.
            Craft::$app->getDb()->createCommand()
                ->update('{{%commerce_discounts}}', [
                    'totalUses' => new Expression('[[totalUses]] + 1')
                ], [
                    'code' => $order->couponCode
                ])
                ->execute();
        }

        if ($record->perUserLimit && $order->customerId) {
            $customerDiscountUseRecord = CustomerDiscountUseRecord::find()->where(['customerId' => $order->customerId, 'discountId' => $record->id])->one();

            if (!$customerDiscountUseRecord) {
                $customerDiscountUseRecord = new CustomerDiscountUseRecord();
                $customerDiscountUseRecord->customerId = $order->customerId;
                $customerDiscountUseRecord->discountId = $record->id;
                $customerDiscountUseRecord->uses = 1;
                $customerDiscountUseRecord->save();
            } else {
                Craft::$app->getDb()->createCommand()
                    ->update('{{%commerce_customer_discountuse}}', [
                        'uses' => new Expression('[[uses]] + 1')
                    ], [
                        'customerId' => $order->customerId,
                        'discountId' => $record->id
                    ])
                    ->execute();
            }
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a Query object prepped for retrieving discounts
     *
     * @return Query
     */
    private function _createDiscountQuery(): Query
    {
        return (new Query())
            ->select([
                'discounts.id',
                'discounts.name',
                'discounts.description',
                'discounts.code',
                'discounts.perUserLimit',
                'discounts.perEmailLimit',
                'discounts.totalUseLimit',
                'discounts.totalUses',
                'discounts.dateFrom',
                'discounts.dateTo',
                'discounts.purchaseTotal',
                'discounts.purchaseQty',
                'discounts.maxPurchaseQty',
                'discounts.baseDiscount',
                'discounts.perItemDiscount',
                'discounts.percentDiscount',
                'discounts.percentageOffSubject',
                'discounts.excludeOnSale',
                'discounts.freeShipping',
                'discounts.allGroups',
                'discounts.allPurchasables',
                'discounts.allCategories',
                'discounts.enabled',
                'discounts.stopProcessing',
                'discounts.sortOrder',
                'discounts.dateCreated',
                'discounts.dateUpdated',
            ])
            ->from(['discounts' => '{{%commerce_discounts}}'])
            ->orderBy(['sortOrder' => SORT_ASC]);
    }
}
