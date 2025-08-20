<?php
/**
 * MagoArab_EasYorder Ajax Calculate Controller
 *
 * @category    MagoArab
 * @package     MagoArab_EasYorder
 * @author      MagoArab Development Team
 * @copyright   Copyright (c) 2025 MagoArab
 * @license     https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace MagoArab\EasYorder\Controller\Ajax;

use MagoArab\EasYorder\Api\QuickOrderServiceInterface;
use MagoArab\EasYorder\Helper\Data as HelperData;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Psr\Log\LoggerInterface;

/**
 * Class Calculate
 * 
 * Ajax controller for calculating order total
 */
class Calculate implements HttpPostActionInterface
{
    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var JsonFactory
     */
    private $jsonFactory;

    /**
     * @var QuickOrderServiceInterface
     */
    private $quickOrderService;

    /**
     * @var HelperData
     */
    private $helperData;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var PriceHelper
     */
    private $priceHelper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        QuickOrderServiceInterface $quickOrderService,
        HelperData $helperData,
        ProductRepositoryInterface $productRepository,
        PriceHelper $priceHelper,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->jsonFactory = $jsonFactory;
        $this->quickOrderService = $quickOrderService;
        $this->helperData = $helperData;
        $this->productRepository = $productRepository;
        $this->priceHelper = $priceHelper;
        $this->logger = $logger;
    }

    /**
     * Execute action
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            // Check if module is enabled
            if (!$this->helperData->isEnabled()) {
                return $result->setData([
                    'success' => false,
                    'message' => __('Quick order is not enabled.')
                ]);
            }

            $productId = (int)$this->request->getParam('product_id');
            $qty = (int)$this->request->getParam('qty', 1);
            $shippingMethod = trim($this->request->getParam('shipping_method'));
            $countryId = trim($this->request->getParam('country_id'));
            $region = trim($this->request->getParam('region'));
            $postcode = trim($this->request->getParam('postcode'));

            if (!$productId || !$shippingMethod || !$countryId) {
                return $result->setData([
                    'success' => false,
                    'message' => __('Required parameters are missing.')
                ]);
            }

            // CRITICAL: Validate stock before calculation to save resources
            $stockValidation = $this->validateStockForCalculation($productId, $qty);
            if (!$stockValidation['valid']) {
                return $result->setData([
                    'success' => false,
                    'message' => $stockValidation['message'],
                    'stock_error' => true
                ]);
            }

            // Handle configurable product attributes for price rules
            $superAttribute = $this->request->getParam('super_attribute');
            if ($superAttribute && is_array($superAttribute)) {
                $this->quickOrderService->setSelectedProductAttributes($superAttribute);
            }

            // ENHANCED: استخدام QuickOrderService مع تمرير جميع البيانات المطلوبة
            $calculationResult = $this->quickOrderService->calculateOrderTotalWithDynamicRules(
                $productId,
                $qty,
                $shippingMethod,
                $countryId,
                $region ?: null,
                $postcode ?: null,
                $this->request->getParam('coupon_code') // إضافة دعم الكوبون
            );

            return $result->setData([
                'success' => true,
                'calculation' => [
                    'product_price' => $calculationResult['product_price'],
                    'qty' => $qty,
                    'subtotal' => $calculationResult['subtotal'],
                    'shipping_cost' => $calculationResult['shipping_cost'],
                    'total' => $calculationResult['total'],
                    'discount_amount' => $calculationResult['discount_amount'] ?? 0,
                    'applied_rule_ids' => $calculationResult['applied_rule_ids'] ?? '',
                    'has_discount' => $calculationResult['has_discount'] ?? false,
                    'formatted' => [
                        'product_price' => $this->priceHelper->currency($calculationResult['product_price'], true, false),
                        'subtotal' => $this->priceHelper->currency($calculationResult['subtotal'], true, false),
                        'shipping_cost' => $this->priceHelper->currency($calculationResult['shipping_cost'], true, false),
                        'total' => $this->priceHelper->currency($calculationResult['total'], true, false),
                        'discount_amount' => $this->priceHelper->currency($calculationResult['discount_amount'] ?? 0, true, false)
                    ]
                ]
            ]);

        } catch (LocalizedException $e) {
            $this->logger->error('Error calculating total: ' . $e->getMessage());
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error calculating total: ' . $e->getMessage());
            return $result->setData([
                'success' => false,
                'message' => __('Unable to calculate total.')
            ]);
        }
    }

    /**
     * Validate stock availability for calculation
     */
    private function validateStockForCalculation(int $productId, int $qty): array
    {
        try {
            // Load product
            $product = $this->productRepository->getById($productId);
            
            // Check if product is enabled
            if (!$product->getStatus() || $product->getStatus() != \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED) {
                return [
                    'valid' => false,
                    'message' => 'المنتج غير متاح حالياً.'
                ];
            }

            // Handle configurable products
            if ($product->getTypeId() === 'configurable') {
                $superAttribute = $this->request->getParam('super_attribute');
                if (!$superAttribute || !is_array($superAttribute)) {
                    return [
                        'valid' => false,
                        'message' => 'يجب تحديد خيارات المنتج.'
                    ];
                }

                $childProduct = $product->getTypeInstance()->getProductByAttributes($superAttribute, $product);
                if (!$childProduct) {
                    return [
                        'valid' => false,
                        'message' => 'الخيارات المحددة غير متاحة.'
                    ];
                }
                
                // Check child product stock (skip visibility check)
                return $this->checkProductStock($childProduct, $qty, true);
            } else {
                // Check simple/virtual product stock
                return $this->checkProductStock($product, $qty);
            }

        } catch (\Exception $e) {
            $this->logger->error('Stock validation error in calculation: ' . $e->getMessage());
            return [
                'valid' => false,
                'message' => 'خطأ في التحقق من توفر المنتج.'
            ];
        }
    }

    /**
     * Check product stock
     */
    private function checkProductStock($product, int $qty, bool $isChildProduct = false): array
    {
        try {
            // Basic product validation (skip visibility for child products)
            if (!$isChildProduct) {
                // Full validation for main products
                if (!$product->getStatus() || $product->getStatus() != \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED) {
                    return [
                        'valid' => false,
                        'message' => 'المنتج غير متاح حالياً.'
                    ];
                }
            } else {
                // For child products, only check if enabled (skip visibility check)
                if (!$product->getStatus() || $product->getStatus() != \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED) {
                    return [
                        'valid' => false,
                        'message' => 'المنتج المحدد غير متاح حالياً.'
                    ];
                }
            }

            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $stockRegistry = $objectManager->get(\Magento\CatalogInventory\Api\StockRegistryInterface::class);
            
            $stockItem = $stockRegistry->getStockItem($product->getId());
            
            // If stock management is disabled, allow
            if (!$stockItem->getManageStock()) {
                return [
                    'valid' => true,
                    'message' => 'Stock management disabled'
                ];
            }
            
            // Check if product is in stock
            if (!$stockItem->getIsInStock()) {
                return [
                    'valid' => false,
                    'message' => 'المنتج غير متوفر بالمخزون حالياً.'
                ];
            }
            
            // Check available quantity
            $availableQty = (float)$stockItem->getQty();
            if ($availableQty < $qty) {
                if ($stockItem->getBackorders() == \Magento\CatalogInventory\Model\Stock::BACKORDERS_NO) {
                    return [
                        'valid' => false,
                        'message' => sprintf(
                            'الكمية المطلوبة (%d) غير متوفرة. الكمية المتاحة: %d',
                            $qty,
                            (int)$availableQty
                        )
                    ];
                }
            }
            
            return [
                'valid' => true,
                'message' => 'Stock available'
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Product stock check error: ' . $e->getMessage());
            return [
                'valid' => false,
                'message' => 'خطأ في التحقق من مخزون المنتج.'
            ];
        }
    }
}