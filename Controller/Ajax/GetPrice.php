<?php
namespace MagoArab\EasYorder\Controller\Ajax;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;

class GetPrice implements HttpPostActionInterface
{
    private $jsonFactory;
    private $request;
    private $productRepository;
    private $priceHelper;

    public function __construct(
        JsonFactory $jsonFactory,
        RequestInterface $request,
        ProductRepositoryInterface $productRepository,
        PriceHelper $priceHelper
    ) {
        $this->jsonFactory = $jsonFactory;
        $this->request = $request;
        $this->productRepository = $productRepository;
        $this->priceHelper = $priceHelper;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        
        try {
            $productId = (int)$this->request->getParam('product_id');
            $superAttribute = $this->request->getParam('super_attribute', []);
            
            $product = $this->productRepository->getById($productId);
            
            // Check product availability first
            if (!$product->getStatus() || $product->getStatus() != \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED) {
                return $result->setData([
                    'error' => true,
                    'message' => 'المنتج غير متاح حالياً.'
                ]);
            }
            
            $targetProduct = $product;
            if ($product->getTypeId() === 'configurable' && !empty($superAttribute)) {
                $childProduct = $product->getTypeInstance()->getProductByAttributes($superAttribute, $product);
                if ($childProduct) {
                    $targetProduct = $childProduct;
                    $price = $childProduct->getFinalPrice();
                } else {
                    return $result->setData([
                        'error' => true,
                        'message' => 'الخيارات المحددة غير متاحة.'
                    ]);
                }
            } else {
                $price = $product->getFinalPrice();
            }
            
            // Check stock availability
            $stockCheck = $this->checkStockAvailability($targetProduct);
            if (!$stockCheck['available']) {
                return $result->setData([
                    'error' => true,
                    'message' => $stockCheck['message'],
                    'stock_status' => 'out_of_stock'
                ]);
            }
            
            return $result->setData([
                'success' => true,
                'price' => $price,
                'formatted_price' => $this->priceHelper->currency($price, true, false)
            ]);
            
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => __('Error getting product price')
            ]);
        }
    }

    /**
     * Check if product is available in stock
     */
    private function checkStockAvailability($product): array
    {
        try {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $stockRegistry = $objectManager->get(\Magento\CatalogInventory\Api\StockRegistryInterface::class);
            
            $stockItem = $stockRegistry->getStockItem($product->getId());
            
            // If stock management is disabled, product is available
            if (!$stockItem->getManageStock()) {
                return [
                    'available' => true,
                    'message' => 'Stock management disabled'
                ];
            }
            
            // Check if product is in stock
            if (!$stockItem->getIsInStock()) {
                return [
                    'available' => false,
                    'message' => 'المنتج غير متوفر بالمخزون حالياً.'
                ];
            }
            
            return [
                'available' => true,
                'message' => 'Product available',
                'qty' => (int)$stockItem->getQty()
            ];
            
        } catch (\Exception $e) {
            return [
                'available' => false,
                'message' => 'خطأ في التحقق من توفر المنتج.'
            ];
        }
    }
}