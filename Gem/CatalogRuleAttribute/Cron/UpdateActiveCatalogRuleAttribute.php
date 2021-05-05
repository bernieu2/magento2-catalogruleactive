<?php
namespace Gem\CatalogRuleAttribute\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable;
use Psr\Log\LoggerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

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
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    protected $productRepository;

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
     * @param \Magento\Framework\App\ResourceConnection $resourceConnection
     * @param \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollection
     * @param \Magento\Catalog\Api\ProductRepositoryInterface $productRepository
     * @param \Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable $configurableProduct
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $dateTime
     */
    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollection,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable $configurableProduct,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Stdlib\DateTime\DateTime $dateTime
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->productCollection = $productCollection;
        $this->productRepository = $productRepository;
        $this->configurableProduct = $configurableProduct;
        $this->logger = $logger;
        $this->dateTime = $dateTime;
    }

   /**
    *
    * @return void
    */
    public function execute()
    {
        try {
              $connection = $this->resourceConnection->getConnection();
              $tableName = $this->resourceConnection->getTableName('catalogrule_product');
              $date = strtotime($this->dateTime->gmtDate());
              $productIds = [];
              $configurableIds = [];
              $currentActiveProds = [];

              $catalogRuleProducts = $connection->select()
                  ->from($tableName)
                  ->where('from_time = 0 or from_time < ?', $date)
                  ->where('to_time = 0 or to_time > ?', $date);
              $ruleProducts = $connection->fetchAll($catalogRuleProducts);

            foreach ($ruleProducts as $ruleProduct) {
                $productIds[] = $ruleProduct['product_id'];
            }

              /** Check if there is a configurable product and append parent configurable */
              $configurableIds = $this->configurableProduct->getParentIdsByChild($productIds);

            if (!empty($configurableIds)) {
                $configurableIds = array_unique($configurableIds);
                $productIds = array_merge($configurableIds, $productIds);
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
            foreach ($newProductIds as $productId) {
                $product = $this->productRepository->getById($productId);
                $product->setData('catalog_rule_active', 1);
                $product->save();
                $product->unset();
            }

            foreach ($activeProdsToRemove as $productId) {
                $product = $this->productRepository->getById($productId);
                $product->setData('catalog_rule_active', 0);
                $product->save();
                $product->unset();
            }

            /** Logging */
            $count = count($productIds);
            $productIds = implode(',', $productIds);
            $this->logger->info("GEM CR ALL ACTIVE - Product Ids ($count) $productIds");
            $count = count($newProductIds);
            $newProductIds = implode(',', $newProductIds);
            $this->logger->info("GEM CR UPDATED - Product Ids ($count) $newProductIds");
            $count = count($activeProdsToRemove);
            $activeProdsToRemove = implode(',', $activeProdsToRemove);
            $this->logger->info("GEM CR REMOVED - Product Ids ($count) $activeProdsToRemove");
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $this->logger->error("GEM CR - Error updating catalog rule attributes: $errorMessage");
        }
    }
}
