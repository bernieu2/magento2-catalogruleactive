<?php
namespace Gem\CatalogRuleAttribute\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Model\Product\Action;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable;
use Psr\Log\LoggerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;

class UpdateActiveCatalogRuleAttribute
{
    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory
     */
    protected $productCollection;

    /**
     * @var \Magento\Catalog\Model\Product\Action
     */
    protected $productAction;

    /**
     * @var \Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable
     */
    protected $configurableProduct;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    protected $dateTime;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @param \Magento\Framework\App\ResourceConnection $resourceConnection
     * @param \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollection
     * @param \Magento\Catalog\Model\Product\Action $productAction
     * @param \Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable $configurableProduct
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $dateTime
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     */
    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollection,
        \Magento\Catalog\Model\Product\Action $productAction,
        \Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable $configurableProduct,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Stdlib\DateTime\DateTime $dateTime,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->productCollection = $productCollection;
        $this->productAction = $productAction;
        $this->configurableProduct = $configurableProduct;
        $this->logger = $logger;
        $this->dateTime = $dateTime;
        $this->storeManager = $storeManager;
    }

   /**
    *
    * @return void
    */
    public function execute()
    {
        try {
              $websites = $this->storeManager->getWebsites();
              $websiteIds = [];

            foreach ($websites as $website) {
                $websiteIds[] = $website->getId();
            }

              $connection = $this->resourceConnection->getConnection();
              $tableName = $this->resourceConnection->getTableName('catalogrule_product');
              $date = strtotime($this->dateTime->gmtDate());

            foreach ($websiteIds as $websiteId) {
                $productIds = [];
                $currentActiveProds = [];

                $catalogRuleProducts = $connection->select()
                  ->from($tableName)
                  ->where('website_id = ?', $websiteId)
                  ->where('from_time = 0 or from_time < ?', $date)
                  ->where('to_time = 0 or to_time > ?', $date);
                $ruleProducts = $connection->fetchAll($catalogRuleProducts);

                foreach ($ruleProducts as $ruleProduct) {
                    $productIds[] = $ruleProduct['product_id'];
                }
                $productIds = array_unique($productIds);

                /** Check if there is a configurable product and append parent configurable */
                $configurableIds = $this->configurableProduct->getParentIdsByChild($productIds);

                if ($configurableIds) {
                    if (is_array($configurableIds)) {
                        $configurableIds = array_unique($configurableIds);
                        foreach ($configurableIds as $configurableId) {
                            $productIds[] = $configurableId;
                        }
                    } else {
                        $productIds[] = $configurableIds;
                    }
                }

                /** Get all previously active products to potentially remove */
                $activeCollection = $this->productCollection->create()
                                  ->addAttributeToFilter('catalog_rule_active', 1);
                foreach ($activeCollection as $activeProd) {
                    $currentActiveProds[] = $activeProd->getId();
                }
                $activeProdsToRemove = array_diff($currentActiveProds, $productIds);

                /** Filter out current items with no-change status */
                $newProductIds = array_diff($productIds, $currentActiveProds);

                /** Update only changed products */
                $storeId = $this->storeManager->getWebsite($websiteId)->getDefaultStore()->getId();
                $newAttrData = [
                'catalog_rule_active' => 1
                ];
                $oldAttrData = [
                'catalog_rule_active' => 0
                ];

                $this->productAction->updateAttributes($newProductIds, $newAttrData, $storeId);
                $this->productAction->updateAttributes($activeProdsToRemove, $oldAttrData, $storeId);

              /** Logging */
                $count = count($productIds);
                $this->logger->info("GEM CR ALL ACTIVE Website ($websiteId) Product Ids ($count)");
                $count = count($newProductIds);
                $this->logger->info("GEM CR UPDATED Website ($websiteId) Product Ids ($count)");
                $count = count($activeProdsToRemove);
                $this->logger->info("GEM CR REMOVED Website($websiteId) Product Ids ($count)");
            }
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $this->logger->error("GEM CR - Error updating catalog rule attributes: $errorMessage");
        }
    }
}
