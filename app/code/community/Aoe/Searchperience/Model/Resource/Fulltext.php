<?php

class Aoe_Searchperience_Model_Resource_Fulltext extends Mage_CatalogSearch_Model_Resource_Fulltext
{
    /**
     * Holds datetime attribute values
     * before and after modification
     *
     * @var array
     */
    public static $dateTimeAttributeValues = array();

    /**
     * Number of products to process at once
     *
     * @var int
     */
    protected $_limit = 100;

    /**
     * Fields, that are being nulled, once
     * their value is 0
     *
     * @var array
     */
    protected $_nullableFields = [
        'special_price',
    ];

    /**
     * Init resource model
     */
    protected function _construct()
    {
        $this->_limit = Mage::getStoreConfig('searchperience/searchperience/indexerBatchSize');
        $this->_limit = max(1, $this->_limit);
        $this->_limit = min(5000, $this->_limit);

        parent::_construct();
    }

    /**
     * Regenerate search index for specific store
     *
     * @param int $storeId Store View Id
     * @param int|array $productIds Product Entity Id
     * @return Mage_CatalogSearch_Model_Resource_Fulltext
     */
    protected function _rebuildStoreIndex($storeId, $productIds = null)
    {
        if ($productIds === array()) {
            // $this->_getSearchableProducts() won't find anything anyways
            return $this;
        }

        if (!Mage::getStoreConfigFlag('searchperience/searchperience/enablePushingDocumentsToSearchperience', $storeId)) {
            if (Mage::helper('aoe_searchperience')->isLoggingEnabled()) {
                Mage::log(sprintf('Skipping indexing for store "%s" because of enablePushingDocumentsToSearchperience', $storeId), Zend_Log::DEBUG, Aoe_Searchperience_Helper_Data::LOGFILE);
            }
            return $this;
        }

        // prepare searchable attributes
        $staticFields = array();
        foreach ($this->_getSearchableAttributes('static') as $attribute) {
            $staticFields[] = $attribute->getAttributeCode();
        }
        $dynamicFields = array(
            'int'       => array_keys($this->_getSearchableAttributes('int')),
            'varchar'   => array_keys($this->_getSearchableAttributes('varchar')),
            'text'      => array_keys($this->_getSearchableAttributes('text')),
            'decimal'   => array_keys($this->_getSearchableAttributes('decimal')),
            'datetime'  => array_keys($this->_getSearchableAttributes('datetime')),
        );

        $lastProductId = 0;
        $productsFound = array();
        while (true) {
            $products = $this->_getSearchableProducts($storeId, $staticFields, $productIds, $lastProductId, $this->_limit);

            if (!$products) {
                break;
            }

            if (Mage::helper('aoe_searchperience')->isLoggingEnabled()) {
                $message = sprintf('Found "%s" searchable product(s) in store "%s".', count($products), $storeId);
                if (!is_null($productIds)) {
                    $message .= ' (requested productIds: ' . implode(', ',$productIds) . ')';
                }

                $i = 0; $tmp = array();
                foreach ($products as $productData) {
                    $tmp[] = $productData['entity_id'];
                    if ($i++ > 5) {  $tmp[] = '...'; break; }
                }
                $message .= ' (found productIds: ' . implode(', ', $tmp) . ')';

                Mage::log($message, Zend_Log::DEBUG, Aoe_Searchperience_Helper_Data::LOGFILE);
            }

            $productAttributes = array();
            $productRelations  = array();
            $websiteId = Mage::app()->getStore($storeId)->getWebsiteId();
            foreach ($products as $productData) { /* @var $productData array */
                $lastProductId = $productData['entity_id'];
                $productsFound[] = $productData['entity_id'];
                $productAttributes[$productData['entity_id']] = $productData['entity_id'];
                $productChildren = $this->_getProductChildrenIds(
                    $productData['entity_id'],
                    $productData['type_id'],
                    $websiteId
                );
                $productRelations[$productData['entity_id']] = $productChildren;
                if ($productChildren) {
                    foreach ($productChildren as $productChildId) {
                        $productAttributes[$productChildId] = $productChildId;
                    }
                }
            }

            $this->processBatch($storeId, $productAttributes, $dynamicFields, $products, $productRelations);

            // cleanup
            self::$dateTimeAttributeValues = array();
        }

        if (!is_null($productIds)) {
            $missingProducts = array_diff($productIds, $productsFound);
            if (count($missingProducts)) {
                $this->cleanIndex($storeId, $missingProducts);
            }
        }

        $this->finishProcessing();
        $this->resetSearchResults();

        if (Mage::helper('aoe_searchperience')->isLoggingEnabled()) {
            Mage::log('statistics: ' . var_export(Aoe_Searchperience_Model_Client_Searchperience::$statistics, true),
                Zend_Log::DEBUG, Aoe_Searchperience_Helper_Data::LOGFILE
            );
        }

        return $this;
    }

    /**
     * Prepare Fulltext index value for product
     *
     * @param array $indexData
     * @param array $productData
     * @param int $storeId
     * @return string
     */
    protected function _prepareProductIndex($indexData, $productData, $storeId)
    {
        // store original values for later usage
        foreach ($indexData as $entityId => $attributeData) {
            foreach ($attributeData as $attributeId => $attributeValue) {
                $attribute = $this->_getSearchableAttribute($attributeId);
                if ($attribute->getBackendType() == 'datetime') {
                    self::$dateTimeAttributeValues[$entityId][$attributeId] = $attributeValue;
                }
            }
        }

        return parent::_prepareProductIndex($indexData, $productData, $storeId);
    }

    /**
     * Retrieve attribute source value for search
     *
     * @param int $attributeId
     * @param mixed $value
     * @param int $storeId
     * @return mixed
     */
    protected function _getAttributeValue($attributeId, $value, $storeId)
    {
        $attribute = $this->_getSearchableAttribute($attributeId);
        if (is_string($value)) {
            $value = preg_replace('#<\s*br\s*/?\s*>#', ' ', $value);
        }

        // handle values that are 0 but should be empty
        if (in_array($attribute->getAttributeCode(), $this->_nullableFields) && $value == 0) {
            return null;
        }

        return parent::_getAttributeValue($attributeId, $value, $storeId);
    }

    /**
     * Checks if product is visible
     *
     * @param   int     $productId
     * @param   array   $productAttributes
     * @return bool
     */
    protected function _isProductVisible($productId, $productAttributes)
    {
        $visibility              = $this->_getSearchableAttribute('visibility');
        $allowedVisibilityValues = $this->_engine->getAllowedVisibility();
        $productAttr             = $productAttributes[$productId];

        if (!isset($productAttr[$visibility->getId()])
            || !in_array($productAttr[$visibility->getId()], $allowedVisibilityValues)
        ) {
            return false;
        }

        return true;
    }

    /**
     * Checks if product is enabled
     *
     * @param $productId
     * @param $productAttributes
     * @return bool
     */
    protected function _isProductEnabled($productId, $productAttributes)
    {
        $status      = $this->_getSearchableAttribute('status');
        $statusVals  = Mage::getSingleton('catalog/product_status')->getVisibleStatusIds();
        $productAttr = $productAttributes[$productId];

        if (!isset($productAttr[$status->getId()]) || !in_array($productAttr[$status->getId()], $statusVals)) {
            return false;
        }

        return true;
    }

    /**
     * Checks, if given product is the child product of
     * determined relation structure and returns parent id
     *
     * @param $productRelations
     * @param $productId
     * @return int|bool
     */
    protected function _getParentProduct($productRelations, $productId)
    {
        // check if product is a parent product
        if (isset($productRelations[$productId])) {
            return false;
        }

        // no parent product, check if it is a child
        foreach ($productRelations as $parent => $listOfChildren) {
            if (is_array($listOfChildren) && in_array($productId, $listOfChildren)) {
                return $parent;
            }
        }

        return false;
    }

    /**
     * Delete search index data for store
     *
     * @param int $storeId Store View Id
     * @param array|int $productId Product Entity Id
     * @return Mage_CatalogSearch_Model_Resource_Fulltext
     */
    public function cleanIndex($storeId = null, $productId = null)
    {
        if (Mage::helper('aoe_searchperience')->isLoggingEnabled()) {
            $stores = is_array($storeId) ? $storeId : array($storeId);
            $products = is_array($productId) ? $productId : array($productId);
            Mage::log(sprintf('[CLEAN] Product: "%s", Store "%s"', implode(', ', $products), implode(', ', $stores)),
                Zend_Log::DEBUG, Aoe_Searchperience_Helper_Data::LOGFILE
            );
        }
        return parent::cleanIndex($storeId, $productId);
    }

    /**
     * Template method that will be called after everything is done (required in inheriting class using threadi)
     */
    protected function finishProcessing()
    {
        // NOOP
    }

    /**
     * Wrapper method for actual processBatch (required in inheriting class using threadi)
     *
     * @param $storeId
     * @param array $productAttributes
     * @param array $dynamicFields
     * @param array $products
     * @param array $productRelations
     * @return array
     */
    public function processBatch($storeId, array $productAttributes, array $dynamicFields, array $products,
        array $productRelations
    ) {
        return $this->_processBatch($storeId, $productAttributes, $dynamicFields, $products, $productRelations);
    }

    /**
     * @param $storeId
     * @param array $productAttributes
     * @param array $dynamicFields
     * @param array $products
     * @param array $productRelations
     * @return array
     */
    public function _processBatch($storeId, array $productAttributes, array $dynamicFields, array $products,
        array $productRelations
    ) {
        $productIndexes = array();
        $productAttributes = $this->_getProductAttributes($storeId, $productAttributes, $dynamicFields);
        foreach ($products as $productData) {
            if (!isset($productAttributes[$productData['entity_id']])) {
                continue;
            }

            $productAttr = $productAttributes[$productData['entity_id']];
            $hasParent = true;

            // determine has parent status and product id to process
            if (false == ($productId = $this->_getParentProduct($productRelations, $productData['entity_id']))) {
                $productId = $productData['entity_id'];
                $hasParent = false;
            }

            // only clean index, if (parent) product is visible and enabled
            if (
                !$this->_isProductVisible($productId, $productAttributes) ||
                !$this->_isProductEnabled($productId, $productAttributes)
            ) {
                $this->cleanIndex($storeId, $productId);
                continue;
            }

            // only process products, which are parent products
            if (false !== $hasParent) {
                continue;
            }

            $productIndex = array(
                $productData['entity_id'] => $productAttr
            );

            if ($productChildren = $productRelations[$productData['entity_id']]) {
                foreach ($productChildren as $productChildId) {
                    if (isset($productAttributes[$productChildId])) {
                        $productIndex[$productChildId] = $productAttributes[$productChildId];
                    }
                }
            }
            $index = $this->_prepareProductIndex($productIndex, $productData, $storeId);

            $productIndexes[$productData['entity_id']] = $index;
        }

        $this->_saveProductIndexes($storeId, $productIndexes);
    }

    /**
     * Retrieve searchable products per store
     *
     * @param int $storeId
     * @param array $staticFields
     * @param array|int $productIds
     * @param int $lastProductId
     * @param int $limit
     * @return array
     */
    protected function _getSearchableProducts($storeId, array $staticFields, $productIds = null, $lastProductId = 0,
                                              $limit = 100)
    {
        $websiteId      = Mage::app()->getStore($storeId)->getWebsiteId();
        $writeAdapter   = $this->_getWriteAdapter();

        $select = $writeAdapter->select()
            ->useStraightJoin(true)
            ->from(
                array('e' => $this->getTable('catalog/product')),
                array_merge(array('entity_id', 'type_id'), $staticFields)
            )
            ->join(
                array('website' => $this->getTable('catalog/product_website')),
                $writeAdapter->quoteInto(
                    'website.product_id=e.entity_id AND website.website_id=?',
                    $websiteId
                ),
                array()
            );

        // only add stock status join if we should hide elements without stock
        if (!Mage::getStoreConfig(Mage_CatalogInventory_Helper_Data::XML_PATH_SHOW_OUT_OF_STOCK)) {
            $select
                ->join(
                    array('stock_status' => $this->getTable('cataloginventory/stock_status')),
                    $writeAdapter->quoteInto(
                        'stock_status.product_id = e.entity_id AND stock_status.website_id = ?',
                        $websiteId
                    ),
                    array('in_stock' => 'stock_status')
                );
        }

        if (!is_null($productIds)) {
            $select->where('e.entity_id IN(?)', $productIds);
        }

        $select->where('e.entity_id>?', $lastProductId)
            ->limit($limit)
            ->order('e.entity_id');

        /**
         * Add additional external limitation
         */
        Mage::dispatchEvent('prepare_catalog_product_index_select', array(
            'select'        => $select,
            'entity_field'  => new Zend_Db_Expr('e.entity_id'),
            'website_field' => new Zend_Db_Expr('website.website_id'),
            'store_field'   => $storeId
        ));

        $result = $writeAdapter->fetchAll($select);

        return $result;
    }
}
