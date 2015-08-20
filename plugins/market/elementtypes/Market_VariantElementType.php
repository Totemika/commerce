<?php
namespace Craft;

require_once(__DIR__ . '/Market_BaseElementType.php');

class Market_VariantElementType extends Market_BaseElementType
{

	public function getName()
	{
		return Craft::t('Variants');
	}

	public function hasContent()
	{
		return true;
	}

	public function hasTitles()
	{
		return false;
	}

	public function hasStatuses()
	{
		return false;
	}

	public function isSelectable()
	{
		return true;
	}

	public function getSources($context = NULL)
	{
		$sources = [

			'*' => [
				'label' => Craft::t('All product\'s variants'),
			]
		];

		return $sources;

	}

	public function getAvailableActions($source = null)
	{
		$deleteAction = craft()->elements->getAction('Delete');
		$deleteAction->setParams(array(
			'confirmationMessage' => Craft::t('Are you sure you want to delete the selected variants?'),
			'successMessage'      => Craft::t('Variants deleted.'),
		));

		$actions[] = $deleteAction;

		$editAction = craft()->elements->getAction('Edit');
		$actions[] = $editAction;


		return $actions;
	}

	public function defineTableAttributes($source = NULL)
	{
		return [
			'sku'            => Craft::t('SKU'),
			'price'          => Craft::t('Price'),
			'width'          => Craft::t('Width'),
			'height'         => Craft::t('Height'),
			'length'         => Craft::t('Length'),
			'weight'         => Craft::t('Weight'),
			'stock'          => Craft::t('Stock'),
			'minQty'         => Craft::t('Min Qty'),
			'maxQty'         => Craft::t('Max Qty')
		];
	}

	public function defineSearchableAttributes()
	{
		return ['sku', 'price', 'width', 'height', 'length', 'weight', 'stock', 'unlimitedStock', 'minQty','maxQty'];

	}


	public function getTableAttributeHtml(BaseElementModel $element, $attribute)
	{
		$numbers = ['weight','height','length','width'];
		if(in_array($attribute,$numbers)){
			$formatter = craft()->getNumberFormatter();
			if($element->$attribute == 0){
				return "<span style=\"color:#E5E5E5\">".$formatter->formatDecimal($element->$attribute)."</span>";
			}else{
				return $formatter->formatDecimal($element->$attribute);
			}

		}

		if($attribute == 'stock' && $element->unlimitedStock){
			return "<span style=\"color:#E5E5E5\">NA</span>";
		}

		if($attribute == 'price'){
			$formatter = craft()->getNumberFormatter();
			return $formatter->formatCurrency($element->$attribute,craft()->market_settings->getSettings()->defaultCurrency);
		}

		return parent::getTableAttributeHtml($element, $attribute);
	}


	public function defineSortableAttributes()
	{
		return [
			'sku'            => Craft::t('SKU'),
			'price'          => Craft::t('Price'),
			'width'          => Craft::t('Width'),
			'height'         => Craft::t('Height'),
			'length'         => Craft::t('Length'),
			'weight'         => Craft::t('Weight'),
			'stock'          => Craft::t('Stock'),
			'unlimitedStock' => Craft::t('Unlimited Stock'),
			'minQty'         => Craft::t('Min Qty'),
			'maxQty'         => Craft::t('Max Qty')
		];
	}


	public function defineCriteriaAttributes()
	{
		return [
			'sku'       => AttributeType::Mixed,
			'product'   => AttributeType::Mixed,
			'productId' => AttributeType::Mixed,
			'isMaster' => AttributeType::Mixed,
		];
	}

	public function modifyElementsQuery(DbCommand $query, ElementCriteriaModel $criteria)
	{
		$query
			->addSelect("variants.id,variants.productId,variants.isMaster,variants.sku,variants.price,variants.width,variants.height,variants.length,variants.weight,variants.stock,variants.unlimitedStock,variants.minQty,variants.maxQty")
			->join('market_variants variants', 'variants.id = elements.id');

		if ($criteria->sku) {
			$query->andWhere(DbHelper::parseParam('variants.sku', $criteria->sku, $query->params));
		}

		if ($criteria->product) {
			if ($criteria->product instanceof Market_ProductModel) {
				$criteria->productId = $criteria->product->id;
				$criteria->product   = NULL;
			} else {
				$query->andWhere(DbHelper::parseParam('variants.productId', $criteria->product, $query->params));
			}
		}

		if ($criteria->productId) {
			$query->andWhere(DbHelper::parseParam('variants.productId', $criteria->productId, $query->params));
		}

		if ($criteria->isMaster) {
			$query->andWhere(DbHelper::parseParam('variants.isMaster', $criteria->isMaster, $query->params));
		}

	}

	public function getEditorHtml(BaseElementModel $element)
	{
		$variant = $element;
		$html = craft()->templates->render('market/_includes/variant/fields',compact('variant'));

		$html .= parent::getEditorHtml($element);

		return $html;
	}

	public function populateElementModel($row)
	{
		return Market_VariantModel::populateModel($row);
	}

	public function saveElement(BaseElementModel $element, $params)
	{
		foreach ($params as $name => $value) {
			$element->$name = $value;
		}
		return craft()->market_variant->save($element);
	}

}