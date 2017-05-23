<?php
namespace craft\commerce\services;

use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\helpers\Db;
use craft\commerce\models\Sale;
use craft\commerce\Plugin;
use craft\commerce\records\Sale as SaleRecord;
use craft\commerce\records\SaleProduct as SaleProductRecord;
use craft\commerce\records\SaleProductType as SaleProductTypeRecord;
use craft\commerce\records\SaleUserGroup as SaleUserGroupRecord;
use yii\base\Component;


/**
 * Sale service.
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2015, Pixel & Tonic, Inc.
 * @license   https://craftcommerce.com/license Craft Commerce License Agreement
 * @see       https://craftcommerce.com
 * @package   craft.plugins.commerce.services
 * @since     1.0
 */
class Sales extends Component
{

    private $_allSales;

    private $_allActiveSales;

    /**
     * @param int $id
     *
     * @return Sale|null
     */
    public function getSaleById($id)
    {
        foreach ($this->getAllSales() as $sale) {
            if ($sale->id == $id) {
                return $sale;
            }
        }

        return null;
    }

    /**
     * @return Sale[]
     */
    public function getAllSales()
    {
        if (!isset($this->_allSales)) {
            $sales = Craft::$app->getDb()->createCommand()
                ->select('sales.id,
                sales.name,
                sales.description,
                sales.dateFrom,
                sales.dateTo,
                sales.discountType,
                sales.discountAmount,
                sales.allGroups,
                sales.allProducts,
                sales.allProductTypes,
                sales.enabled,
                sp.productId,
                spt.productTypeId,
                sug.userGroupId')
                ->from('commerce_sales sales')
                ->leftJoin('commerce_sale_products sp', 'sp.saleId=sales.id')
                ->leftJoin('commerce_sale_producttypes spt', 'spt.saleId=sales.id')
                ->leftJoin('commerce_sale_usergroups sug', 'sug.saleId=sales.id')
                ->queryAll();

            $allSalesById = [];
            $products = [];
            $productTypes = [];
            $groups = [];
            foreach ($sales as $sale) {
                $id = $sale['id'];
                if (!isset($allSalesById[$id])) {
                    $allSalesById[$id] = Sale::populateModel($sale);
                }

                if ($sale['productId']) {
                    $products[$id][] = $sale['productId'];
                }

                if ($sale['productTypeId']) {
                    $productTypes[$id][] = $sale['productTypeId'];
                }

                if ($sale['userGroupId']) {
                    $groups[$id][] = $sale['userGroupId'];
                }
            }

            foreach ($allSalesById as $id => $sale) {
                $sale->productIds = isset($products[$id]) ? $products[$id] : [];
                $sale->productTypeIds = isset($productTypes[$id]) ? $productTypes[$id] : [];
                $sale->groupIds = isset($groups[$id]) ? $groups[$id] : [];
            }
            $this->_allSales = array_values($allSalesById);
        }

        return $this->_allSales;
    }

    /**
     * Getting all sales applicable for the current user and given product
     *
     * @param Product $product
     *
     * @return Sale[]
     * @deprecated in 1.2. Use getSalesForProduct() instead.
     */
    public function getForProduct(Product $product)
    {
        Craft::$app->getDeprecator()->log('Commerce_SalesService::getForProduct()', 'Commerce_SalesService::getForProduct() has been deprecated. Use Commerce_SalesService::getSalesForProduct() instead.');

        return $this->getSalesForProduct($product);
    }

    /**
     * @param Product $product
     *
     * @return Sale[]
     */
    public function getSalesForProduct(Product $product)
    {
        $matchedSales = [];
        foreach ($this->_getAllActiveSales() as $sale) {
            if ($this->matchProductAndSale($product, $sale)) {
                $matchedSales[] = $sale;
            }
        }

        return $matchedSales;
    }

    private function _getAllActiveSales()
    {
        if (!isset($this->_allActiveSales)) {
            $sales = $this->getAllSales();
            $activeSales = [];
            foreach ($sales as $sale) {
                if ($sale->enabled) {
                    $from = $sale->dateFrom;
                    $to = $sale->dateTo;
                    $now = new DateTime();
                    if ($from == null || $from < $now) {
                        if ($to == null || $to > $now) {
                            $activeSales[] = $sale;
                        }
                    }
                }
            }

            $this->_allActiveSales = $activeSales;
        }

        return $this->_allActiveSales;
    }

    /**
     * @param Product $product
     * @param Sale    $sale
     *
     * @return bool
     */
    public function matchProductAndSale(Product $product, Sale $sale)
    {
        // can't match something not promotable
        if (!$product->promotable) {
            return false;
        }

        // Product ID match
        if (!$sale->allProducts && !in_array($product->id, $sale->getProductIds())) {
            return false;
        }

        // Product Type match
        if (!$sale->allProductTypes && !in_array($product->typeId, $sale->getProductTypeIds())) {
            return false;
        }

        if (!$sale->allGroups) {
            // User Group match
            $userGroups = Plugin::getInstance()->getDiscounts()->getCurrentUserGroupIds();
            if (!$userGroups || !array_intersect($userGroups, $sale->getGroupIds())) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Variant $variant
     *
     * @return Sale[]
     */
    public function getSalesForVariant(Variant $variant)
    {
        $matchedSales = [];
        foreach ($this->_getAllActiveSales() as $sale) {
            if ($this->matchProductAndSale($variant->product, $sale)) {
                $matchedSales[] = $sale;
            }
        }

        return $matchedSales;
    }

    /**
     * @param Sale  $model
     * @param array $groups       ids
     * @param array $productTypes ids
     * @param array $products     ids
     *
     * @return bool
     * @throws \Exception
     */
    public function saveSale(
        Sale $model,
        array $groups,
        array $productTypes,
        array $products
    ) {
        if ($model->id) {
            $record = SaleRecord::findOne($model->id);

            if (!$record) {
                throw new Exception(Craft::t('commerce', 'commerce', 'No sale exists with the ID “{id}”',
                    ['id' => $model->id]));
            }
        } else {
            $record = new SaleRecord();
        }

        $fields = [
            'id',
            'name',
            'description',
            'dateFrom',
            'dateTo',
            'discountType',
            'discountAmount',
            'enabled'
        ];
        foreach ($fields as $field) {
            $record->$field = $model->$field;
        }

        $record->allGroups = $model->allGroups = empty($groups);
        $record->allProductTypes = $model->allProductTypes = empty($productTypes);
        $record->allProducts = $model->allProducts = empty($products);

        $record->validate();
        $model->addErrors($record->getErrors());

        Db::beginStackedTransaction();
        try {
            if (!$model->hasErrors()) {
                $record->save(false);
                $model->id = $record->id;

                SaleUserGroupRecord::deleteAll(['saleId' => $model->id]);
                SaleProductRecord::deleteAll(['saleId' => $model->id]);
                SaleProductTypeRecord::deleteAll(['saleId' => $model->id]);

                foreach ($groups as $groupId) {
                    $relation = new SaleUserGroupRecord();
                    $relation->attributes = [
                        'userGroupId' => $groupId,
                        'saleId' => $model->id
                    ];
                    $relation->insert();
                }

                foreach ($productTypes as $productTypeId) {
                    $relation = new SaleProductTypeRecord;
                    $relation->attributes = [
                        'productTypeId' => $productTypeId,
                        'saleId' => $model->id
                    ];
                    $relation->insert();
                }

                foreach ($products as $productId) {
                    $relation = new SaleProductRecord;
                    $relation->attributes = [
                        'productId' => $productId,
                        'saleId' => $model->id
                    ];
                    $relation->insert();
                }

                Db::commitStackedTransaction();

                return true;
            }
        } catch (\Exception $e) {
            Db::rollbackStackedTransaction();
            throw $e;
        }

        Db::rollbackStackedTransaction();

        return false;
    }

    /**
     * @param int $id
     *
     * @return bool
     */
    public function deleteSaleById($id)
    {
        $sale = SaleRecord::findOne($id);

        if ($sale)
        {
            return $sale->delete();
        }
    }
}
