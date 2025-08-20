<?php
/**
 * MagoArab_EasYorder Enhanced Quick Order Service
 * Supports third-party extensions and catalog rules
 */
declare(strict_types=1);
namespace MagoArab\EasYorder\Model;
use MagoArab\EasYorder\Api\QuickOrderServiceInterface;
use MagoArab\EasYorder\Api\Data\QuickOrderDataInterface;
use MagoArab\EasYorder\Helper\Data as HelperData;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Quote\Api\ShippingMethodManagementInterface;
use Magento\Payment\Api\PaymentMethodListInterface;
use Magento\Payment\Model\Config as PaymentConfig;
use Magento\Shipping\Model\Config as ShippingConfig;
use Magento\Directory\Model\RegionFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\DataObject;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\CatalogRule\Model\RuleFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\SalesRule\Model\RuleFactory as CartRuleFactory;
use Magento\SalesRule\Model\Validator as CartRuleValidator;
use Magento\CatalogRule\Model\ResourceModel\Rule as CatalogRuleResource;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
class QuickOrderService implements QuickOrderServiceInterface
{
    private $productRepository;
    private $quoteFactory;
    private $quoteManagement;
    private $storeManager;
    private $customerFactory;
    private $customerRepository;
    private $orderSender;
    private $helperData;
    private $dataHelper;
    private $cartRepository;
    private $cartManagement;
    private $scopeConfig;
    private $logger;
    private $shippingMethodManagement;
    private $paymentMethodList;
    private $paymentConfig;
    private $shippingConfig;
    private $regionFactory;
    private $orderRepository;
    private $priceHelper;
    private $customerSession;
    private $checkoutSession;
    private $ruleFactory;
    private $dateTime;
    private $request;
    private $cartRuleFactory;
    private $cartRuleValidator;
    private $catalogRuleResource;
    private $timezone;
    /**
     * Property to store current order attributes
     */
    private $currentOrderAttributes = null;
    
    /**
     * Shipping calculation cache to improve performance
     */
    private $shippingCache = [];
	public function __construct(
			ProductRepositoryInterface $productRepository,
			QuoteFactory $quoteFactory,
			QuoteManagement $quoteManagement,
			StoreManagerInterface $storeManager,
			CustomerFactory $customerFactory,
			CustomerRepositoryInterface $customerRepository,
			OrderSender $orderSender,
			HelperData $helperData,
			CartRepositoryInterface $cartRepository,
			CartManagementInterface $cartManagement,
			ScopeConfigInterface $scopeConfig,
			LoggerInterface $logger,
			ShippingMethodManagementInterface $shippingMethodManagement,
			PaymentMethodListInterface $paymentMethodList,
			PaymentConfig $paymentConfig,
			ShippingConfig $shippingConfig,
			RegionFactory $regionFactory,
			OrderRepositoryInterface $orderRepository,
			PriceHelper $priceHelper,
			CustomerSession $customerSession,
			CheckoutSession $checkoutSession,
			RuleFactory $ruleFactory,
			DateTime $dateTime,
			RequestInterface $request,
			CartRuleFactory $cartRuleFactory,
        CartRuleValidator $cartRuleValidator,
        CatalogRuleResource $catalogRuleResource,
        TimezoneInterface $timezone
    ) {
        $this->productRepository = $productRepository;
        $this->quoteFactory = $quoteFactory;
        $this->quoteManagement = $quoteManagement;
        $this->storeManager = $storeManager;
        $this->customerFactory = $customerFactory;
        $this->customerRepository = $customerRepository;
        $this->orderSender = $orderSender;
        $this->helperData = $helperData;
        $this->dataHelper = $helperData;
        $this->cartRepository = $cartRepository;
        $this->cartManagement = $cartManagement;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->shippingMethodManagement = $shippingMethodManagement;
        $this->paymentMethodList = $paymentMethodList;
        $this->paymentConfig = $paymentConfig;
        $this->shippingConfig = $shippingConfig;
        $this->regionFactory = $regionFactory;
        $this->orderRepository = $orderRepository;
        $this->priceHelper = $priceHelper;
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->ruleFactory = $ruleFactory;
        $this->dateTime = $dateTime;
		$this->request = $request;
        $this->cartRuleFactory = $cartRuleFactory;
        $this->cartRuleValidator = $cartRuleValidator;
        $this->catalogRuleResource = $catalogRuleResource;
        $this->timezone = $timezone;
    }
    public function getAvailableShippingMethods(int $productId, string $countryId, ?string $region = null, ?string $postcode = null, int $qty = 1): array
    {
        // Create cache key for shipping methods (include quantity)
        $cacheKey = sprintf('shipping_%d_%s_%s_%s_%d', $productId, $countryId, $region ?: 'none', $postcode ?: 'none', $qty);
        
        // Return cached result if available
        if (isset($this->shippingCache[$cacheKey])) {
            $this->logger->info('Returning cached shipping methods', ['cache_key' => $cacheKey]);
            return $this->shippingCache[$cacheKey];
        }
        
        $requestId = uniqid('service_', true);
        try {
            $this->logger->info('=== Enhanced QuickOrderService: Starting shipping calculation ===', [
                'request_id' => $requestId,
                'product_id' => $productId,
                'country_id' => $countryId,
                'region' => $region,
                'postcode' => $postcode,
                'qty' => $qty
            ]);
            // Step 1: Create realistic quote like normal checkout with location context
            $quote = $this->createRealisticQuoteWithProduct($productId, $countryId, $region, $postcode, $qty);
            // Step 2: Use OFFICIAL Magento Shipping Method Management API
            $shippingMethods = $this->collectShippingMethodsUsingOfficialAPI($quote, $requestId);
            // Step 3: Apply admin filtering (keeps third-party compatibility)
            $filteredMethods = $this->helperData->filterShippingMethods($shippingMethods);
            $this->logger->info('=== Enhanced QuickOrderService: Shipping calculation completed ===', [
                'request_id' => $requestId,
                'original_methods_count' => count($shippingMethods),
                'filtered_methods_count' => count($filteredMethods),
                'final_methods' => array_column($filteredMethods, 'code')
            ]);
            
            // Cache the result (but not for different quantities to ensure rules are re-applied)
            if ($qty === 1) {
                $this->shippingCache[$cacheKey] = $filteredMethods;
            }
            
            return $filteredMethods;
        } catch (\Exception $e) {
            $this->logger->error('=== Enhanced QuickOrderService: Error in shipping calculation ===', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }
    /**
     * Create realistic quote that mimics normal checkout behavior with FULL rules application
     */
    private function createRealisticQuoteWithProduct(int $productId, string $countryId, ?string $region = null, ?string $postcode = null, int $qty = 1)
{
    try {
        $product = $this->productRepository->getById($productId);
        $store = $this->storeManager->getStore();
        // Create quote exactly like checkout
        $quote = $this->quoteFactory->create();
        $quote->setStore($store);
        $quote->setStoreId($store->getId());
        $quote->setCurrency();
        // CRITICAL: Set customer context for ALL catalog rules
        $quote->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);
        $quote->setCustomerIsGuest(true);
        $quote->setCustomerEmail('guest@example.com');
        // Set realistic addresses EARLY for cart rules that depend on location
        $this->setRealisticShippingAddress($quote, $countryId, $region, $postcode);
        $this->setRealisticBillingAddress($quote, $countryId, $region, $postcode);
        // Handle product variants properly with attributes - SIMPLIFIED APPROACH
        if ($product->getTypeId() === 'configurable') {
            $selectedAttributes = $this->getSelectedProductAttributes();
            if ($selectedAttributes && !empty($selectedAttributes)) {
                // User has selected specific attributes
                $simpleProduct = $product->getTypeInstance()->getProductByAttributes($selectedAttributes, $product);
                if ($simpleProduct) {
                    // CRITICAL: Add with correct quantity from the start
                    $request = new DataObject([
                        'qty' => $qty,
                        'product' => $simpleProduct->getId()
                    ]);
                    $quote->addProduct($simpleProduct, $request);
                    $this->logger->info('Added simple product directly (user selected)', [
                        'parent_id' => $productId,
                        'simple_id' => $simpleProduct->getId(),
                        'qty' => $qty,
                        'selected_attributes' => $selectedAttributes
                    ]);
                } else {
                    throw new LocalizedException(__('Selected product configuration is not available'));
                }
            } else {
                // No attributes selected, get first available simple product
                $simpleProduct = $this->getFirstAvailableSimpleProduct($product);
                if ($simpleProduct) {
                    // Add with correct quantity
                    $request = new DataObject([
                        'qty' => $qty,
                        'product' => $simpleProduct->getId()
                    ]);
                    $quote->addProduct($simpleProduct, $request);
                    $this->logger->info('Added simple product directly (auto selected)', [
                        'parent_id' => $productId,
                        'simple_id' => $simpleProduct->getId(),
                        'qty' => $qty
                    ]);
                } else {
                    throw new LocalizedException(__('No available product variants found'));
                }
            }
        } else {
            // For simple products
            $request = new DataObject([
                'qty' => $qty,
                'product' => $product->getId()
            ]);
            $quote->addProduct($product, $request);
            $this->logger->info('Added simple product', [
                'product_id' => $productId,
                'qty' => $qty
            ]);
        }
        // ENHANCED: Multiple totals collection for COMPLETE rules application
        // Step 1: Initial totals collection
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->cartRepository->save($quote);
        // Step 2: Force catalog rules application
        foreach ($quote->getAllItems() as $item) {
            $item->getProduct()->setCustomerGroupId($quote->getCustomerGroupId());
            $item->calcRowTotal();
        }
        // Step 3: Reload and recalculate for cart rules
        $quote = $this->cartRepository->get($quote->getId());
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->cartRepository->save($quote);
        // Step 4: Final calculation to ensure ALL rules are applied
        $quote = $this->cartRepository->get($quote->getId());
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->cartRepository->save($quote);
        $this->logger->info('Enhanced quote created with FULL rules application', [
            'quote_id' => $quote->getId(),
            'total_items_count' => count($quote->getAllItems()),
            'visible_items_count' => count($quote->getAllVisibleItems()),
            'subtotal' => $quote->getSubtotal(),
            'subtotal_with_discount' => $quote->getSubtotalWithDiscount(),
            'grand_total' => $quote->getGrandTotal(),
            'applied_rule_ids' => $quote->getAppliedRuleIds(),
            'items_details' => array_map(function($item) {
                return [
                    'item_id' => $item->getId(),
                    'parent_item_id' => $item->getParentItemId(),
                    'sku' => $item->getSku(),
                    'name' => $item->getName(),
                    'qty' => $item->getQty(),
                    'price' => $item->getPrice(),
                    'row_total' => $item->getRowTotal(),
                    'product_type' => $item->getProductType(),
                    'is_virtual' => $item->getIsVirtual()
                ];
            }, $quote->getAllVisibleItems()) // Use getAllVisibleItems() instead of getAllItems()
        ]);
        return $quote;
    } catch (\Exception $e) {
        $this->logger->error('Failed to create enhanced quote with rules', [
            'product_id' => $productId,
            'qty' => $qty,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        throw new LocalizedException(__('Unable to create quote: %1', $e->getMessage()));
    }
}
/**
 * Get selected product attributes from current request/session
 * This will be called during order creation
 */
private function getSelectedProductAttributes(): ?array
{
    try {
        // Check if we're in order creation context and have stored attributes
        if (isset($this->currentOrderAttributes)) {
            return $this->currentOrderAttributes;
        }
        return null;
    } catch (\Exception $e) {
        $this->logger->warning('Could not get selected product attributes: ' . $e->getMessage());
        return null;
    }
}
/**
 * Set selected product attributes for order creation
 */
public function setSelectedProductAttributes(array $attributes): void
{
    $this->currentOrderAttributes = $attributes;
}
    private function setRealisticShippingAddress($quote, string $countryId, ?string $region = null, ?string $postcode = null)
    {
        $shippingAddress = $quote->getShippingAddress();
        // Set complete address data
        $shippingAddress->setCountryId($countryId);
        $shippingAddress->setCity($region ? $region : 'Cairo');
        $shippingAddress->setStreet(['123 Main Street', 'Apt 1']);
        $shippingAddress->setFirstname('Guest');
        $shippingAddress->setLastname('Customer');
        $shippingAddress->setTelephone('01234567890');
        $shippingAddress->setEmail('guest@example.com');
        $shippingAddress->setCompany('');
        // Set region properly
        if ($region) {
            $regionId = $this->getRegionIdByName($region, $countryId);
            if ($regionId) {
                $shippingAddress->setRegionId($regionId);
                $shippingAddress->setRegion($region);
            } else {
                $shippingAddress->setRegion($region);
            }
        }
        // Set postcode
        if ($postcode) {
            $shippingAddress->setPostcode($postcode);
        } else {
            $shippingAddress->setPostcode('11511'); // Default Egyptian postcode
        }
        // Save address changes
        $shippingAddress->save();
        return $shippingAddress;
    }
    /**
     * Set realistic billing address for cart rules
     */
    private function setRealisticBillingAddress($quote, string $countryId, ?string $region = null, ?string $postcode = null)
    {
        $billingAddress = $quote->getBillingAddress();
        $billingAddress->setCountryId($countryId);
        $billingAddress->setCity($region ? $region : 'Cairo');
        $billingAddress->setStreet(['123 Main Street', 'Apt 1']);
        $billingAddress->setFirstname('Guest');
        $billingAddress->setLastname('Customer');
        $billingAddress->setTelephone('01234567890');
        $billingAddress->setEmail('guest@example.com');
        if ($region) {
            $regionId = $this->getRegionIdByName($region, $countryId);
            if ($regionId) {
                $billingAddress->setRegionId($regionId);
                $billingAddress->setRegion($region);
            } else {
                $billingAddress->setRegion($region);
            }
        }
        if ($postcode) {
            $billingAddress->setPostcode($postcode);
        } else {
            $billingAddress->setPostcode('11511');
        }
        $billingAddress->save();
        return $billingAddress;
    }
/**
 * FIXED: Enhanced shipping collection that works with ALL third-party extensions
 */
private function collectShippingMethodsUsingOfficialAPI($quote, string $requestId): array
{
    try {
        $this->logger->info('Enhanced Shipping Collection Started', [
            'request_id' => $requestId,
            'quote_id' => $quote->getId()
        ]);
        // STEP 1: Ensure proper customer context
        $quote->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);
        $quote->setCustomerIsGuest(true);
        // STEP 2: Get shipping address and validate
        $shippingAddress = $quote->getShippingAddress();
        if (!$shippingAddress->getCountryId()) {
            throw new \Exception('Shipping address missing country');
        }
        // STEP 3: CRITICAL FIX - Force proper address setup for shipping calculation
        $shippingAddress->setCollectShippingRates(true);
        $shippingAddress->removeAllShippingRates();
        // Set weight if not set (required for many shipping methods)
        $totalWeight = 0;
        foreach ($quote->getAllItems() as $item) {
            $product = $item->getProduct();
            if ($product && $product->getWeight()) {
                $totalWeight += ($product->getWeight() * $item->getQty());
            }
        }
        if ($totalWeight > 0) {
            $shippingAddress->setWeight($totalWeight);
        } else {
            $shippingAddress->setWeight(1); // Default weight for calculation
        }
        // STEP 4: Force totals calculation BEFORE shipping collection
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->cartRepository->save($quote);
        // STEP 5: Manual shipping rates collection (more reliable than API)
        $shippingAddress->setCollectShippingRates(true);
        $shippingAddress->collectShippingRates();
        // STEP 6: Force another totals collection
        $quote->collectTotals();
        $this->cartRepository->save($quote);
        // STEP 7: Get rates from address (most reliable method)
        $shippingRates = $shippingAddress->getAllShippingRates();
        $this->logger->info('Shipping rates collected', [
            'request_id' => $requestId,
            'rates_count' => count($shippingRates),
            'quote_subtotal' => $quote->getSubtotal(),
            'quote_weight' => $shippingAddress->getWeight()
        ]);
        $methods = [];
 foreach ($shippingRates as $rate) {
    // FIXED: Accept rates even with warnings, but skip null methods
    if ($rate->getMethod() !== null) {
        $methodCode = $rate->getCarrier() . '_' . $rate->getMethod();
        if ($rate->getErrorMessage()) {
            $this->logger->info('Shipping rate has warning but will be included', [
                'request_id' => $requestId,
                'carrier' => $rate->getCarrier(),
                'method' => $rate->getMethod(),
                'warning' => $rate->getErrorMessage(),
                'price' => $rate->getPrice()
            ]);
        }
        $methods[] = [
            'code' => $methodCode,
            'carrier_code' => $rate->getCarrier(),
            'method_code' => $rate->getMethod(),
            'carrier_title' => $rate->getCarrierTitle(),
            'title' => $rate->getMethodTitle(),
            'price' => (float)$rate->getPrice(),
            'price_formatted' => $this->formatPrice((float)$rate->getPrice())
        ];
        $this->logger->info('Valid shipping method found', [
            'request_id' => $requestId,
            'method_code' => $methodCode,
            'price' => $rate->getPrice(),
            'carrier_title' => $rate->getCarrierTitle()
        ]);
    } else {
        $this->logger->warning('Shipping rate has null method - skipped', [
            'request_id' => $requestId,
            'carrier' => $rate->getCarrier(),
            'method' => $rate->getMethod(),
            'error' => $rate->getErrorMessage()
        ]);
    }
}
        // STEP 8: If no methods found, try alternative approach
        if (empty($methods)) {
            $this->logger->warning('No shipping rates found, trying alternative collection', [
                'request_id' => $requestId
            ]);
            $methods = $this->collectShippingUsingCarrierModels($quote, $requestId);
        }
        // STEP 9: Fallback to configured carriers if still empty
        if (empty($methods)) {
            $this->logger->warning('No methods from carriers, using fallback', [
                'request_id' => $requestId
            ]);
            $methods = $this->getFallbackShippingMethods();
        }
        $this->logger->info('Final shipping methods result', [
            'request_id' => $requestId,
            'methods_count' => count($methods),
            'methods' => array_column($methods, 'code')
        ]);
        return $methods;
    } catch (\Exception $e) {
        $this->logger->error('Enhanced shipping collection failed', [
            'request_id' => $requestId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        // Ultimate fallback
        return $this->getFallbackShippingMethods();
    }
}
/**
 * FIXED: Enhanced carrier collection that works with all Magento carriers
 */
private function collectShippingUsingCarrierModels($quote, string $requestId): array
{
    $methods = [];
    try {
        $this->logger->info('Starting alternative carrier collection', [
            'request_id' => $requestId
        ]);
        // Get all carriers from shipping config
        $allCarriers = $this->shippingConfig->getAllCarriers();
        $shippingAddress = $quote->getShippingAddress();
        foreach ($allCarriers as $carrierCode => $carrierModel) {
            try {
                // Check if carrier is active
                $isActive = $this->scopeConfig->getValue(
                    'carriers/' . $carrierCode . '/active',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                );
                if (!$isActive) {
                    continue;
                }
                // Skip freeshipping if it has issues and continue to other carriers
                if ($carrierCode === 'freeshipping') {
                    $this->logger->info('Skipping freeshipping carrier for alternative collection', [
                        'request_id' => $requestId
                    ]);
                    continue;
                }
                $this->logger->info('Processing carrier', [
                    'request_id' => $requestId,
                    'carrier' => $carrierCode,
                    'model_class' => get_class($carrierModel)
                ]);
                // Create comprehensive shipping rate request
                $request = $this->createShippingRateRequest($quote, $shippingAddress);
                // Try to collect rates from carrier
                $result = $carrierModel->collectRates($request);
                if ($result && $result->getRates()) {
                    $rates = $result->getRates();
                    $this->logger->info('Carrier returned rates', [
                        'request_id' => $requestId,
                        'carrier' => $carrierCode,
                        'rates_count' => count($rates)
                    ]);
                    foreach ($rates as $rate) {
                        if ($rate->getMethod() !== null) {
                            $methodCode = $rate->getCarrier() . '_' . $rate->getMethod();
                            $methods[] = [
                                'code' => $methodCode,
                                'carrier_code' => $rate->getCarrier(),
                                'method_code' => $rate->getMethod(),
                                'carrier_title' => $rate->getCarrierTitle(),
                                'title' => $rate->getMethodTitle(),
                                'price' => (float)$rate->getPrice(),
                                'price_formatted' => $this->formatPrice((float)$rate->getPrice())
                            ];
                            $this->logger->info('Alternative carrier method collected', [
                                'request_id' => $requestId,
                                'carrier' => $carrierCode,
                                'method' => $methodCode,
                                'price' => $rate->getPrice()
                            ]);
                        }
                    }
                } else {
                    $this->logger->info('Carrier returned no rates', [
                        'request_id' => $requestId,
                        'carrier' => $carrierCode,
                        'result_class' => $result ? get_class($result) : 'null'
                    ]);
                    // For standard carriers, create fallback methods
                    if (in_array($carrierCode, ['flatrate', 'tablerate'])) {
                        $fallbackMethod = $this->createFallbackMethod($carrierCode);
                        if ($fallbackMethod) {
                            $methods[] = $fallbackMethod;
                            $this->logger->info('Created fallback method for carrier', [
                                'request_id' => $requestId,
                                'carrier' => $carrierCode,
                                'method' => $fallbackMethod['code']
                            ]);
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->logger->warning('Carrier collection failed', [
                    'request_id' => $requestId,
                    'carrier' => $carrierCode,
                    'error' => $e->getMessage()
                ]);
                // Try to create basic method for known carriers
                if (in_array($carrierCode, ['flatrate', 'tablerate'])) {
                    $fallbackMethod = $this->createFallbackMethod($carrierCode);
                    if ($fallbackMethod) {
                        $methods[] = $fallbackMethod;
                    }
                }
                // Don't let one carrier failure stop others
                continue;
            }
        }
        $this->logger->info('Alternative carrier collection completed', [
            'request_id' => $requestId,
            'total_methods' => count($methods),
            'methods' => array_column($methods, 'code')
        ]);
    } catch (\Exception $e) {
        $this->logger->error('Alternative carrier collection failed completely', [
            'request_id' => $requestId,
            'error' => $e->getMessage()
        ]);
    }
    return $methods;
}
/**
 * Create comprehensive shipping rate request
 */
private function createShippingRateRequest($quote, $shippingAddress)
{
    // Create proper rate request object
    $request = new \Magento\Framework\DataObject();
    // Set destination data
    $request->setDestCountryId($shippingAddress->getCountryId());
    $request->setDestRegionId($shippingAddress->getRegionId());
    $request->setDestRegionCode($shippingAddress->getRegionCode());
    $request->setDestStreet($shippingAddress->getStreet());
    $request->setDestCity($shippingAddress->getCity());
    $request->setDestPostcode($shippingAddress->getPostcode());
    // Set package data
    $request->setPackageWeight($shippingAddress->getWeight() ?: 1);
    $request->setPackageValue($quote->getSubtotal());
    $request->setPackageValueWithDiscount($quote->getSubtotalWithDiscount());
    $request->setPackageQty($quote->getItemsQty());
    // Set store/website data
    $request->setStoreId($quote->getStoreId());
    $request->setWebsiteId($quote->getStore()->getWebsiteId());
    $request->setBaseCurrency($quote->getBaseCurrencyCode());
    $request->setPackageCurrency($quote->getQuoteCurrencyCode());
    $request->setLimitMethod(null);
    // Set origin data
    $request->setOrigCountry($this->scopeConfig->getValue(
        'shipping/origin/country_id',
        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
    ));
    $request->setOrigRegionId($this->scopeConfig->getValue(
        'shipping/origin/region_id',
        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
    ));
    $request->setOrigCity($this->scopeConfig->getValue(
        'shipping/origin/city',
        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
    ));
    $request->setOrigPostcode($this->scopeConfig->getValue(
        'shipping/origin/postcode',
        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
    ));
    // Add all items to request
    $items = [];
    foreach ($quote->getAllItems() as $item) {
        if (!$item->getParentItem()) {
            $items[] = new \Magento\Framework\DataObject([
                'qty' => $item->getQty(),
                'weight' => $item->getWeight() ?: 1,
                'product_id' => $item->getProductId(),
                'base_row_total' => $item->getBaseRowTotal(),
                'price' => $item->getPrice(),
                'row_total' => $item->getRowTotal(),
                'product' => $item->getProduct()
            ]);
        }
    }
    $request->setAllItems($items);
    return $request;
}
/**
 * Create fallback method for standard carriers
 */
private function createFallbackMethod(string $carrierCode): ?array
{
    $title = $this->scopeConfig->getValue(
        'carriers/' . $carrierCode . '/title',
        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
    );
    if (!$title) {
        return null;
    }
    $price = 0;
    switch ($carrierCode) {
        case 'flatrate':
            $price = (float)$this->scopeConfig->getValue(
                'carriers/flatrate/price',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            ) ?: 25;
            break;
        case 'freeshipping':
            $price = 0;
            break;
        case 'tablerate':
            $price = 25; // Default for tablerate
            break;
    }
    return [
        'code' => $carrierCode . '_' . $carrierCode,
        'carrier_code' => $carrierCode,
        'method_code' => $carrierCode,
        'carrier_title' => $title,
        'title' => $title,
        'price' => $price,
        'price_formatted' => $this->formatPrice($price)
    ];
}
/**
 * Force collection of ALL active carriers
 */
private function forceCollectAllActiveCarriers(): array
{
    $methods = [];
    // List of standard Magento carriers
    $standardCarriers = [
        'flatrate' => 'Flat Rate',
        'freeshipping' => 'Free Shipping', 
        'tablerate' => 'Table Rate',
        'ups' => 'UPS',
        'usps' => 'USPS',
        'fedex' => 'FedEx',
        'dhl' => 'DHL'
    ];
    foreach ($standardCarriers as $carrierCode => $defaultTitle) {
        $isActive = $this->scopeConfig->getValue(
            'carriers/' . $carrierCode . '/active',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        if ($isActive) {
            $title = $this->scopeConfig->getValue(
                'carriers/' . $carrierCode . '/title',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            ) ?: $defaultTitle;
            $price = 0;
            $methodCode = $carrierCode . '_' . $carrierCode;
            // Set appropriate prices
            switch ($carrierCode) {
                case 'flatrate':
                    $price = (float)$this->scopeConfig->getValue(
                        'carriers/flatrate/price',
                        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                    ) ?: 25;
                    break;
                case 'freeshipping':
                    $price = 0;
                    $methodCode = 'freeshipping_freeshipping';
                    break;
                case 'tablerate':
                    $price = 30; // Default tablerate price
                    break;
                default:
                    $price = 35; // Default for other carriers
            }
            $methods[] = [
                'code' => $methodCode,
                'carrier_code' => $carrierCode,
                'method_code' => $carrierCode,
                'carrier_title' => $title,
                'title' => $title,
                'price' => $price,
                'price_formatted' => $this->formatPrice($price)
            ];
            $this->logger->info('Force collected carrier method', [
                'carrier' => $carrierCode,
                'method' => $methodCode,
                'price' => $price
            ]);
        }
    }
    return $methods;
}
/**
 * Get fallback shipping methods from system configuration
 */
private function getFallbackShippingMethods(): array
{
    // First try to force collect all active carriers
    $forcedMethods = $this->forceCollectAllActiveCarriers();
    if (!empty($forcedMethods)) {
        $this->logger->info('Using forced carrier methods', [
            'methods_count' => count($forcedMethods),
            'methods' => array_column($forcedMethods, 'code')
        ]);
        return $forcedMethods;
    }
    // Ultimate fallback
    return [[
        'code' => 'fallback_standard',
        'carrier_code' => 'fallback',
        'method_code' => 'standard',
        'carrier_title' => 'Standard Shipping',
        'title' => 'Standard Delivery',
        'price' => 25.0,
        'price_formatted' => $this->formatPrice(25.0)
    ]];
}
    /**
     * Fallback shipping collection if official API fails
     */
    private function fallbackShippingCollection($quote, string $requestId): array
    {
        try {
            $this->logger->info('Using fallback shipping collection', ['request_id' => $requestId]);
            $shippingAddress = $quote->getShippingAddress();
            $shippingAddress->setCollectShippingRates(true);
            $shippingAddress->collectShippingRates();
            $quote->collectTotals();
            $this->cartRepository->save($quote);
            $shippingRates = $shippingAddress->getAllShippingRates();
            $methods = [];
            foreach ($shippingRates as $rate) {
                if (!$rate->getErrorMessage()) {
                    $methods[] = [
                        'code' => $rate->getCarrier() . '_' . $rate->getMethod(),
                        'carrier_code' => $rate->getCarrier(),
                        'method_code' => $rate->getMethod(),
                        'carrier_title' => $rate->getCarrierTitle(),
                        'title' => $rate->getMethodTitle(),
                        'price' => (float)$rate->getPrice(),
                        'price_formatted' => $this->formatPrice((float)$rate->getPrice())
                    ];
                }
            }
            return $methods;
        } catch (\Exception $e) {
            $this->logger->error('Fallback shipping collection failed', [
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    /**
     * Get first available simple product for configurable
     */
    private function getFirstAvailableSimpleProduct($configurableProduct)
    {
        try {
            $childProducts = $configurableProduct->getTypeInstance()->getUsedProducts($configurableProduct);
            foreach ($childProducts as $childProduct) {
                if ($childProduct->isSalable() && $childProduct->getStatus() == 1) {
                    return $this->productRepository->getById($childProduct->getId());
                }
            }
            if (!empty($childProducts)) {
                return $this->productRepository->getById($childProducts[0]->getId());
            }
            return null;
        } catch (\Exception $e) {
            $this->logger->error('Error getting simple product variant', [
                'configurable_id' => $configurableProduct->getId(),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    /**
     * Get default attributes for simple product
     */
    private function getDefaultAttributesForSimpleProduct($configurableProduct, $simpleProduct): array
    {
        $attributes = [];
        $configurableAttributes = $configurableProduct->getTypeInstance()->getConfigurableAttributes($configurableProduct);
        foreach ($configurableAttributes as $attribute) {
            $attributeCode = $attribute->getProductAttribute()->getAttributeCode();
            $attributeId = $attribute->getAttributeId();
            $value = $simpleProduct->getData($attributeCode);
            if ($value) {
                $attributes[$attributeId] = $value;
            }
        }
        $this->logger->info('Generated default attributes for simple product', [
            'configurable_id' => $configurableProduct->getId(),
            'simple_id' => $simpleProduct->getId(),
            'attributes' => $attributes
        ]);
        return $attributes;
    }
   public function getAvailablePaymentMethods(): array
{
    try {
        $store = $this->storeManager->getStore();
        // Use OFFICIAL Payment Method List API
        $paymentMethods = $this->paymentMethodList->getActiveList($store->getId());
        $methods = [];
        foreach ($paymentMethods as $method) {
            $methodCode = $method->getCode();
            $title = $method->getTitle() ?: $this->getPaymentMethodDefaultTitle($methodCode);
            $methods[] = [
                'code' => $methodCode,
                'title' => $title
            ];
        }
        // Apply admin filtering
        $methods = $this->helperData->filterPaymentMethods($methods);
        $this->logger->info('Enhanced payment methods retrieved', [
            'count' => count($methods),
            'methods' => array_column($methods, 'code'),
            'store_id' => $store->getId()
        ]);
        return $methods;
    } catch (\Exception $e) {
        $this->logger->error('Error getting enhanced payment methods: ' . $e->getMessage());
        return $this->getFallbackPaymentMethods();
    }
}
    private function getFallbackPaymentMethods(): array
    {
        try {
            $store = $this->storeManager->getStore();
            $activePayments = $this->paymentConfig->getActiveMethods();
            $methods = [];
            foreach ($activePayments as $code => $config) {
                $isActive = $this->scopeConfig->getValue(
                    'payment/' . $code . '/active',
                    ScopeInterface::SCOPE_STORE,
                    $store->getId()
                );
                if ($isActive) {
                    $title = $this->scopeConfig->getValue(
                        'payment/' . $code . '/title',
                        ScopeInterface::SCOPE_STORE,
                        $store->getId()
                    ) ?: $this->getPaymentMethodDefaultTitle($code);
                    $methods[] = [
                        'code' => $code,
                        'title' => $title
                    ];
                }
            }
            return $methods;
        } catch (\Exception $e) {
            $this->logger->error('Error getting fallback payment methods: ' . $e->getMessage());
            return [];
        }
    }
    private function getPaymentMethodDefaultTitle(string $methodCode): string
    {
        $titles = [
            'checkmo' => 'Check / Money order',
            'banktransfer' => 'Bank Transfer Payment',
            'cashondelivery' => 'Cash On Delivery',
            'free' => 'No Payment Information Required',
            'purchaseorder' => 'Purchase Order'
        ];
        return $titles[$methodCode] ?? ucfirst(str_replace('_', ' ', $methodCode));
    }
public function createQuickOrder(QuickOrderDataInterface $orderData): array
{
    // Get selected product attributes from order data instead of request
    $superAttribute = $orderData->getSuperAttribute();
    if ($superAttribute && is_array($superAttribute)) {
        $this->setSelectedProductAttributes($superAttribute);
    }
    try {
        $this->logger->info('=== Enhanced Order Creation Started ===', [
            'product_id' => $orderData->getProductId(),
            'shipping_method' => $orderData->getShippingMethod(),
            'payment_method' => $orderData->getPaymentMethod(),
            'country' => $orderData->getCountryId(),
            'qty' => $orderData->getQty(),
            'super_attribute' => $superAttribute
        ]);
        // STEP 1: Create quote with shipping calculation FIRST
        $quote = $this->createRealisticQuoteWithProduct(
            $orderData->getProductId(),
            $orderData->getCountryId(),
            $orderData->getRegion(),
            $orderData->getPostcode(),
            $orderData->getQty()
        );
        // STEP 2: Attach logged-in customer if available, otherwise treat as guest
        $this->attachLoggedInCustomerToQuote($quote);
        // STEP 2.5: Set customer info early (email/name)
        $this->setCustomerInformation($quote, $orderData);
        // STEP 3: Set addresses BEFORE shipping calculation
        $this->setBillingAddress($quote, $orderData);
        $this->setShippingAddressEarly($quote, $orderData);
        // STEP 4: Update quantity if different from 1
        if ($orderData->getQty() > 1) {
            $this->updateQuoteItemQuantity($quote, $orderData->getQty());
        }
        // STEP 4.5: Apply coupon as early as possible so totals/rates reflect it when configured
        $earlyCoupon = $orderData->getCouponCode();
        if ($earlyCoupon) {
            try {
                $quote->setCouponCode($earlyCoupon);
            } catch (\Exception $e) {
                $this->logger->warning('Early coupon application failed', ['coupon' => $earlyCoupon, 'error' => $e->getMessage()]);
            }
        }
        // STEP 5: Get FRESH shipping methods for this specific quote
        $availableShippingMethods = $this->getQuoteShippingMethods($quote);
        $this->logger->info('Fresh shipping methods for order', [
            'methods_count' => count($availableShippingMethods),
            'methods' => array_column($availableShippingMethods, 'code'),
            'requested_method' => $orderData->getShippingMethod()
        ]);
        // STEP 6: Validate and set shipping method
        $validShippingMethod = $this->validateAndSetShippingMethod($quote, $orderData, $availableShippingMethods);
        // STEP 6.5: Apply catalog/cart rules on same quote, then re-validate shipping
        $this->applyCatalogRules($quote);
        $this->applyCartRules($quote);
        // Recalculate shipping after rules (quantity/threshold sensitive)
        $this->recalculateShippingAfterPriceRules($quote, $orderData->getShippingMethod());
        // If any zero-cost shipping rate exists (e.g., freeshipping), enforce selecting it to match frontend summary
        $this->enforceFreeShippingMethodIfAvailable($quote);
        // Prefer zero-cost shipping rate when available (e.g., free shipping threshold)
        try {
            $shippingAddress = $quote->getShippingAddress();
            $availableRates = $shippingAddress->getAllShippingRates();
            $zeroRate = null;
            foreach ($availableRates as $rate) {
                if ((float)$rate->getPrice() <= 0) {
                    $zeroRate = $rate; break;
                }
            }
            if ($zeroRate) {
                $code = $zeroRate->getCarrier() . '_' . $zeroRate->getMethod();
                $shippingAddress->setShippingMethod($code);
                $shippingAddress->setShippingDescription($zeroRate->getCarrierTitle() . ' - ' . $zeroRate->getMethodTitle());
                $quote->setTotalsCollectedFlag(false);
                $quote->collectTotals();
            }
        } catch (\Exception $e) {
            $this->logger->warning('Selecting zero-cost shipping failed: ' . $e->getMessage());
        }
        // STEP 7: Set payment method
        $this->setPaymentMethod($quote, $orderData);
        // STEP 7.5: Recalculate shipping after price rules
        $this->recalculateShippingAfterPriceRules($quote, $orderData->getShippingMethod());
        // STEP 7.7: Recollect shipping rates after price/cart rules to ensure thresholds/free shipping are applied
        try {
            $shippingAddress = $quote->getShippingAddress();
            $shippingAddress->setCollectShippingRates(true);
            $shippingAddress->removeAllShippingRates();
            $shippingAddress->collectShippingRates();
        } catch (\Exception $e) {
            $this->logger->warning('Recollect shipping rates failed: ' . $e->getMessage());
        }

        // STEP 8: Apply coupon code (if any) BEFORE final totals (re-apply to persist)
        $coupon = $orderData->getCouponCode();
        if ($coupon) {
            try {
                $quote->setCouponCode($coupon);
                // Re-apply cart rules so coupon affects totals before final shipping decision
                $this->applyCartRules($quote);
            } catch (\Exception $e) {
                $this->logger->warning('Invalid coupon code ignored', ['coupon' => $coupon, 'error' => $e->getMessage()]);
            }
        }
        // CRITICAL: Enforce final shipping consistency to match frontend summary
        $this->enforceFinalShippingConsistency($quote, $orderData->getShippingMethod());
        // Additionally, apply free shipping based on store rules (subtotal incl/excl tax) if threshold met
        try {
            $this->logger->info('=== CHECKING FREE SHIPPING RULES ===', [
                'quote_subtotal' => $quote->getSubtotal(),
                'quote_subtotal_with_discount' => $quote->getSubtotalWithDiscount(),
                'quote_subtotal_incl_tax' => $quote->getSubtotalInclTax(),
                'free_shipping_threshold' => $this->helperData->getFreeShippingThreshold(),
                'total_qty' => $quote->getItemsQty()
            ]);
            
            if ($this->helperData->shouldApplyFreeShippingForQuote($quote)) {
                $shippingAddress = $quote->getShippingAddress();
                $shippingAddress->setShippingAmount(0);
                $shippingAddress->setBaseShippingAmount(0);
                $shippingAddress->setFreeShipping(true);
                
                $this->logger->info('=== FREE SHIPPING APPLIED ===', [
                    'reason' => 'threshold_met',
                    'subtotal_meets_threshold' => true
                ]);
            } else {
                $this->logger->info('=== FREE SHIPPING NOT APPLIED ===', [
                    'reason' => 'threshold_not_met',
                    'subtotal_meets_threshold' => false
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Free-shipping threshold evaluation failed: ' . $e->getMessage());
        }
        // If a zero-cost rate exists after coupon, prefer it
        $this->enforceFreeShippingMethodIfAvailable($quote);
        // Final totals collection
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->logger->info('Final quote totals before placing order', [
            'subtotal' => $quote->getSubtotal(),
            'subtotal_with_discount' => $quote->getSubtotalWithDiscount(),
            'discount_amount' => $quote->getSubtotal() - $quote->getSubtotalWithDiscount(),
            'shipping_amount' => $quote->getShippingAddress()->getShippingAmount(),
            'grand_total' => $quote->getGrandTotal(),
            'coupon' => $quote->getCouponCode()
        ]);
        $this->cartRepository->save($quote);

        // Always rely on Magento rules; do not force frontend values on quote
        $this->logger->info('Applying Magento rules on quote without forcing frontend values');
        $this->ensureQuoteRulesApplication($quote, $orderData);

        // Keep frontendValues only for logging/diagnostics (no forcing)
        $frontendValues = $orderData->getData('frontend_values');
        if (!is_array($frontendValues)) {
            $frontendValues = null;
        }
        
        // Freeze quote totals and shipping so no re-collection happens during order conversion
        $this->freezeQuoteTotalsForOrderPlacement($quote);
        
        // Capture final quote values before order placement
        $finalQuoteValues = [
            'subtotal' => (float)$quote->getSubtotal(),
            'discount_amount' => (float)($quote->getSubtotal() - $quote->getSubtotalWithDiscount()),
            'shipping_amount' => (float)$quote->getShippingAddress()->getShippingAmount(),
            'grand_total' => (float)$quote->getGrandTotal()
        ];
        
        $this->logger->info('Final quote values before order placement', $finalQuoteValues);
        
        // STEP 9: Validate quote is ready for order
        $this->validateQuoteForOrder($quote);
        
        // STEP 9.5: Critical stock and availability validation
        $stockValidation = $this->validateStockAvailability($quote, $orderData);
        if (!$stockValidation['valid']) {
            throw new LocalizedException(__($stockValidation['message']));
        }
        
        // STEP 10: Place order using official API
        $orderId = $this->cartManagement->placeOrder($quote->getId());
        $order = $this->orderRepository->get($orderId);
        
        // CRITICAL: Ensure order is saved immediately after creation
        $this->orderRepository->save($order);
        
        // Log order state immediately after creation
        $this->logger->info('=== STEP 10.1: Order created - checking initial state ===', [
            'order_id' => $order->getId(),
            'order_increment_id' => $order->getIncrementId(),
            'magento_status' => $order->getStatus(),
            'magento_state' => $order->getState(),
            'initial_order_totals' => [
                'subtotal' => $order->getSubtotal(),
                'shipping' => $order->getShippingAmount(),
                'discount' => $order->getDiscountAmount(),
                'grand_total' => $order->getGrandTotal()
            ],
            'quote_totals_at_conversion' => [
                'subtotal' => $quote->getSubtotal(),
                'shipping' => $quote->getShippingAddress()->getShippingAmount(),
                'discount' => $quote->getShippingAddress()->getDiscountAmount(),
                'grand_total' => $quote->getGrandTotal()
            ],
            'frontend_values' => $frontendValues
        ]);
        
        // ALWAYS preserve price rules first, then adjust values if needed
        $this->logger->info('=== STEP 10.2: Preserving price rules from quote to order ===');
        $this->ensureOrderReflectsQuoteRules($order, $quote);
        
        // Do not adjust order totals with frontend values; keep Magento-calculated totals and rules intact
        
        // Save customer note if provided
        if (method_exists($order, 'addStatusHistoryComment')) {
            $note = $orderData->getCustomerNote();
            if ($note) {
                $order->addStatusHistoryComment($note)->setIsCustomerNotified(false);
            }
        }
        
        // CRITICAL: Save order before visibility operations
        $this->orderRepository->save($order);
        
        // STEP 10.5: Ensure order appears properly in admin (using Magento defaults only)
        $this->ensureProperOrderStateTransition($order);
        
        // CRITICAL: Final save after all operations
        $this->orderRepository->save($order);
        
        // STEP 11: Send email if enabled
        $this->sendOrderNotification($order);
        // Get product details for success message
        $productDetails = $this->getOrderProductDetails($order);
        // Build final summary from order to ensure UI matches admin totals
        $finalSummary = $this->buildOrderSummary($order);
        $this->logger->info('Enhanced order created successfully', [
            'order_id' => $orderId,
            'increment_id' => $order->getIncrementId(),
            'grand_total' => $order->getGrandTotal(),
            'shipping_method' => $order->getShippingMethod(),
            'shipping_description' => $order->getShippingDescription(),
            'shipping_amount' => $order->getShippingAmount(),
            'discount_amount' => $order->getDiscountAmount(),
            'subtotal' => $order->getSubtotal(),
            'product_details' => $productDetails,
            'RULES_VERIFICATION' => [
                'applied_rule_ids' => $order->getAppliedRuleIds(),
                'coupon_code' => $order->getCouponCode(),
                'discount_description' => $order->getDiscountDescription(),
                'rules_preserved_in_admin' => !empty($order->getAppliedRuleIds()) || !empty($order->getCouponCode()),
                'admin_will_show_rules' => !empty($order->getDiscountDescription()) || $order->getDiscountAmount() != 0
            ]
        ]);
        
        // Return response with values exclusively from the saved Order object
        return [
            'success' => true,
            'order_id' => $orderId,
            'increment_id' => $order->getIncrementId(),
            'message' => $this->helperData->getSuccessMessage(),
            'product_details' => $productDetails,
            'order_total' => $this->formatPrice($order->getGrandTotal()),
            'summary' => $finalSummary,
            'redirect_url' => $this->getOrderSuccessUrl($order),
            // Additional raw values for debugging
            'order_data' => [
                'subtotal' => $order->getSubtotal(),
                'shipping_amount' => $order->getShippingAmount(),
                'discount_amount' => $order->getDiscountAmount(),
                'grand_total' => $order->getGrandTotal()
            ],
            // Include final quote values for comparison
            'quote_values' => $finalQuoteValues
        ];
    } catch (\Exception $e) {
        $this->logger->error('Enhanced order creation failed: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
            'order_data' => [
                'product_id' => $orderData->getProductId(),
                'shipping_method' => $orderData->getShippingMethod(),
                'payment_method' => $orderData->getPaymentMethod()
            ]
        ]);
        throw new LocalizedException(__('Unable to create order: %1', $e->getMessage()));
    }
}

/**
 * Attach logged-in customer to quote to ensure order is created under account
 */
private function attachLoggedInCustomerToQuote($quote): void
{
    try {
        if (!$this->customerSession || !$this->customerSession->isLoggedIn()) {
            // keep as guest
            return;
        }
        $customerId = (int)$this->customerSession->getCustomerId();
        if ($customerId <= 0) {
            return;
        }
        $customer = $this->customerRepository->getById($customerId);
        // Assign core customer info
        if (method_exists($quote, 'assignCustomer')) {
            $quote->assignCustomer($customer);
        }
        $quote->setCustomerId($customerId);
        $quote->setCustomerEmail($customer->getEmail());
        $quote->setCustomerGroupId((int)$customer->getGroupId());
        $quote->setCustomerIsGuest(false);
    } catch (\Exception $e) {
        // Fallback to guest silently
        $this->logger->warning('Unable to attach logged-in customer to quote: ' . $e->getMessage());
    }
}
/**
 * Set shipping address early without method calculation
 */
private function setShippingAddressEarly($quote, QuickOrderDataInterface $orderData): void
{
    $shippingAddress = $quote->getShippingAddress();
    $this->setAddressData($shippingAddress, $orderData, $quote->getCustomerEmail());
    // Set weight for shipping calculation
    $totalWeight = 0;
    foreach ($quote->getAllItems() as $item) {
        $product = $item->getProduct();
        if ($product && $product->getWeight()) {
            $totalWeight += ($product->getWeight() * $item->getQty());
        }
    }
    $shippingAddress->setWeight($totalWeight > 0 ? $totalWeight : 1);
    // Save address data
    $quote->collectTotals();
    $this->cartRepository->save($quote);
}
/**
 * Get shipping methods for specific quote
 */
private function getQuoteShippingMethods($quote): array
{
    $shippingAddress = $quote->getShippingAddress();
    // Force shipping rates collection
    $shippingAddress->setCollectShippingRates(true);
    $shippingAddress->removeAllShippingRates();
    $shippingAddress->collectShippingRates();
    $quote->collectTotals();
    $this->cartRepository->save($quote);
    $shippingRates = $shippingAddress->getAllShippingRates();
    $methods = [];
    foreach ($shippingRates as $rate) {
        if ($rate->getMethod() !== null) {
            $methodCode = $rate->getCarrier() . '_' . $rate->getMethod();
            $methods[] = [
                'code' => $methodCode,
                'carrier_code' => $rate->getCarrier(),
                'method_code' => $rate->getMethod(),
                'carrier_title' => $rate->getCarrierTitle(),
                'title' => $rate->getMethodTitle(),
                'price' => (float)$rate->getPrice(),
                'rate_object' => $rate // Keep reference to original rate
            ];
        }
    }
    return $methods;
}
/**
 * Validate and set shipping method on quote
 */
private function validateAndSetShippingMethod($quote, QuickOrderDataInterface $orderData, array $availableShippingMethods): string
{
    $requestedMethod = $orderData->getShippingMethod();
    $shippingAddress = $quote->getShippingAddress();
    $this->logger->info('Validating shipping method', [
        'requested_method' => $requestedMethod,
        'available_methods' => array_column($availableShippingMethods, 'code')
    ]);
    // Find exact match
// Find exact match
foreach ($availableShippingMethods as $method) {
    if ($method['code'] === $requestedMethod) {
        $shippingAddress->setShippingMethod($method['code']);
        $shippingAddress->setShippingDescription($method['carrier_title'] . ' - ' . $method['title']);
        // Check for free shipping conditions
        $subtotal = $quote->getSubtotal();
        $freeShippingThreshold = $this->dataHelper->getFreeShippingThreshold();
        $shouldApplyFreeShipping = $this->dataHelper->shouldApplyFreeShipping($subtotal);
        if ($shouldApplyFreeShipping || $method['price'] == 0 || $subtotal >= $freeShippingThreshold) {
            $shippingAddress->setShippingAmount(0);
            $shippingAddress->setBaseShippingAmount(0);
            $shippingAddress->setFreeShipping(true);
            $this->logger->info('Free shipping applied in exact match', [
                'method' => $method['code'],
                'subtotal' => $subtotal,
                'threshold' => $freeShippingThreshold,
                'original_price' => $method['price']
            ]);
        }
        $this->logger->info('Exact shipping method match found', [
            'method' => $method['code'],
            'price' => $method['price'],
            'free_shipping' => $shippingAddress->getFreeShipping()
        ]);
        return $method['code'];
    }
}
// Find carrier match
$requestedCarrier = explode('_', $requestedMethod)[0];
foreach ($availableShippingMethods as $method) {
    if ($method['carrier_code'] === $requestedCarrier) {
        $shippingAddress->setShippingMethod($method['code']);
        $shippingAddress->setShippingDescription($method['carrier_title'] . ' - ' . $method['title']);
        // Check for free shipping conditions
        $subtotal = $quote->getSubtotal();
        $freeShippingThreshold = $this->dataHelper->getFreeShippingThreshold();
        $shouldApplyFreeShipping = $this->dataHelper->shouldApplyFreeShipping($subtotal);
        if ($shouldApplyFreeShipping || $method['price'] == 0 || $subtotal >= $freeShippingThreshold) {
            $shippingAddress->setShippingAmount(0);
            $shippingAddress->setBaseShippingAmount(0);
            $shippingAddress->setFreeShipping(true);
            $this->logger->info('Free shipping applied in carrier match', [
                'method' => $method['code'],
                'subtotal' => $subtotal,
                'threshold' => $freeShippingThreshold,
                'original_price' => $method['price']
            ]);
        }
        $this->logger->info('Carrier match found', [
            'requested' => $requestedMethod,
            'used' => $method['code'],
            'free_shipping' => $shippingAddress->getFreeShipping()
        ]);
        return $method['code'];
    }
}
// Use first available method
if (!empty($availableShippingMethods)) {
    $firstMethod = $availableShippingMethods[0];
    $shippingAddress->setShippingMethod($firstMethod['code']);
    $shippingAddress->setShippingDescription($firstMethod['carrier_title'] . ' - ' . $firstMethod['title']);
    // Check for free shipping conditions
    $subtotal = $quote->getSubtotal();
    $freeShippingThreshold = $this->dataHelper->getFreeShippingThreshold();
    $shouldApplyFreeShipping = $this->dataHelper->shouldApplyFreeShipping($subtotal);
    if ($shouldApplyFreeShipping || $firstMethod['price'] == 0 || $subtotal >= $freeShippingThreshold) {
        $shippingAddress->setShippingAmount(0);
        $shippingAddress->setBaseShippingAmount(0);
        $shippingAddress->setFreeShipping(true);
        $this->logger->info('Free shipping applied to first available method', [
            'method' => $firstMethod['code'],
            'subtotal' => $subtotal,
            'threshold' => $freeShippingThreshold,
            'original_price' => $firstMethod['price']
        ]);
    }
    $this->logger->info('Using first available shipping method', [
        'requested' => $requestedMethod,
        'used' => $firstMethod['code'],
        'free_shipping' => $shippingAddress->getFreeShipping()
    ]);
    return $firstMethod['code'];
}
    throw new LocalizedException(__('No valid shipping method available for this order.'));
}
/**
 * Validate quote is ready for order placement
 */
private function validateQuoteForOrder($quote): void
{
    $shippingAddress = $quote->getShippingAddress();
    $payment = $quote->getPayment();
    // Check shipping method
    if (!$shippingAddress->getShippingMethod()) {
        throw new LocalizedException(__('Shipping method is missing. Please select a shipping method and try again.'));
    }
    // Check payment method
    if (!$payment->getMethod()) {
        throw new LocalizedException(__('Payment method is missing. Please select a payment method and try again.'));
    }
    // Check quote has items
    if (!$quote->getItemsCount()) {
        throw new LocalizedException(__('Quote has no items. Please add products to continue.'));
    }
    // Check shipping address
    if (!$shippingAddress->getCountryId() || !$shippingAddress->getCity()) {
        throw new LocalizedException(__('Shipping address is incomplete. Please provide complete address.'));
    }
    $this->logger->info('Quote validation passed', [
        'quote_id' => $quote->getId(),
        'shipping_method' => $shippingAddress->getShippingMethod(),
        'payment_method' => $payment->getMethod(),
        'items_count' => $quote->getItemsCount(),
        'grand_total' => $quote->getGrandTotal()
    ]);
}	
/**
 * Ensure order is properly indexed and visible
 */
/**
 * Ensure order appears properly in admin grid
 * NO interference with order status/state - relies completely on Magento defaults
 */
private function ensureProperOrderStateTransition($order): void
{
    try {
        $this->logger->info('=== ORDER VISIBILITY: Using Pure Magento Behavior ===', [
            'order_id' => $order->getId(),
            'magento_state' => $order->getState(),
            'magento_status' => $order->getStatus(),
            'payment_method' => $order->getPayment() ? $order->getPayment()->getMethod() : 'unknown'
        ]);

        // Only ensure order appears in admin grid - NO status/state changes
        $this->ensureOrderGridIndexing($order);

        $this->logger->info('Order indexing completed - pure Magento behavior maintained', [
            'order_id' => $order->getId(),
            'final_state' => $order->getState(),
            'final_status' => $order->getStatus()
        ]);

    } catch (\Exception $e) {
        $this->logger->error('Error in order indexing: ' . $e->getMessage(), [
            'order_id' => $order->getId(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

/**
 * Ensure order appears in admin grid without changing status/state
 * FIXED: Restored comprehensive visibility ensuring while keeping pure Magento behavior
 */
private function ensureOrderGridIndexing($order): void
{
    try {
        // Clear relevant cache
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        try {
            $cacheManager = $objectManager->get(\Magento\Framework\App\Cache\Manager::class);
            $cacheManager->clean(['db_ddl', 'collections', 'eav']);
        } catch (\Exception $e) {
            $this->logger->warning('Could not clean cache: ' . $e->getMessage());
        }

        // Force manual grid insertion if needed (ENHANCED METHOD)
        try {
            $connection = $objectManager->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
            $gridTable = $connection->getTableName('sales_order_grid');
            
            // Check if record exists in grid
            $exists = $connection->fetchOne(
                "SELECT entity_id FROM {$gridTable} WHERE entity_id = ?",
                [$order->getId()]
            );
            
            if (!$exists) {
                // Insert manually into grid table with current order data
                $gridData = [
                    'entity_id' => $order->getId(),
                    'status' => $order->getStatus(),
                    'store_id' => $order->getStoreId(),
                    'store_name' => $order->getStoreName(),
                    'customer_id' => $order->getCustomerId(),
                    'base_grand_total' => $order->getBaseGrandTotal(),
                    'grand_total' => $order->getGrandTotal(),
                    'increment_id' => $order->getIncrementId(),
                    'base_currency_code' => $order->getBaseCurrencyCode(),
                    'order_currency_code' => $order->getOrderCurrencyCode(),
                    'shipping_name' => $order->getShippingAddress() ? $order->getShippingAddress()->getName() : '',
                    'billing_name' => $order->getBillingAddress() ? $order->getBillingAddress()->getName() : '',
                    'created_at' => $order->getCreatedAt(),
                    'updated_at' => $order->getUpdatedAt(),
                    'billing_address' => $order->getBillingAddress() ? 
                        implode(', ', $order->getBillingAddress()->getStreet()) . ', ' . 
                        $order->getBillingAddress()->getCity() : '',
                    'shipping_address' => $order->getShippingAddress() ? 
                        implode(', ', $order->getShippingAddress()->getStreet()) . ', ' . 
                        $order->getShippingAddress()->getCity() : '',
                    'shipping_information' => $order->getShippingDescription(),
                    'customer_email' => $order->getCustomerEmail(),
                    'customer_group' => $order->getCustomerGroupId(),
                    'subtotal' => $order->getSubtotal(),
                    'shipping_and_handling' => $order->getShippingAmount(),
                    'customer_name' => $order->getCustomerName(),
                    'payment_method' => $order->getPayment() ? $order->getPayment()->getMethod() : '',
                    'total_refunded' => $order->getTotalRefunded() ?: 0
                ];
                
                $connection->insert($gridTable, $gridData);
                $this->logger->info('Order manually inserted into grid', [
                    'order_id' => $order->getId(),
                    'increment_id' => $order->getIncrementId(),
                    'status' => $order->getStatus()
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Manual grid insertion failed: ' . $e->getMessage());
        }

        // Try indexer as backup
        try {
            $indexerRegistry = $objectManager->get(\Magento\Framework\Indexer\IndexerRegistry::class);
            $salesOrderGridIndexer = $indexerRegistry->get('sales_order_grid');
            if ($salesOrderGridIndexer && $salesOrderGridIndexer->isValid()) {
                $salesOrderGridIndexer->reindexRow($order->getId());
            }
        } catch (\Exception $e) {
            $this->logger->warning('Indexer reindex failed: ' . $e->getMessage());
        }

        // Final order save to ensure persistence
        $this->orderRepository->save($order);
        
        $this->logger->info('Order visibility ensured with pure Magento status', [
            'order_id' => $order->getId(),
            'increment_id' => $order->getIncrementId(),
            'magento_status' => $order->getStatus(),
            'magento_state' => $order->getState()
        ]);

    } catch (\Exception $e) {
        $this->logger->error('Error ensuring order visibility: ' . $e->getMessage(), [
            'order_id' => $order->getId(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
    // Rest of the methods remain the same as previous version...
    private function updateQuoteItemQuantity($quote, int $qty): void
    {
        foreach ($quote->getAllItems() as $item) {
            $item->setQty($qty);
        }
        $quote->collectTotals();
    }
    private function setCustomerInformation($quote, QuickOrderDataInterface $orderData): void
    {
        $customerEmail = trim((string)$orderData->getCustomerEmail());
        if ($customerEmail === '' && $this->helperData->isAutoGenerateEmailEnabled()) {
            $customerEmail = $this->helperData->generateGuestEmail($orderData->getCustomerPhone());
        }
        if ($customerEmail !== '') {
            $quote->setCustomerEmail($customerEmail);
        }
        // Split full name into first/last
        $fullName = trim($orderData->getCustomerName());
        $nameParts = explode(' ', $fullName, 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? $firstName;
        $quote->setCustomerFirstname($firstName);
        $quote->setCustomerLastname($lastName);
    }
    private function setBillingAddress($quote, QuickOrderDataInterface $orderData): void
    {
        $billingAddress = $quote->getBillingAddress();
        $this->setAddressData($billingAddress, $orderData, $quote->getCustomerEmail());
    }
 /**
 * FIXED: Properly set shipping address and method
 */
private function setShippingAddressAndMethod($quote, QuickOrderDataInterface $orderData): void
{
    $shippingAddress = $quote->getShippingAddress();
    $this->setAddressData($shippingAddress, $orderData, $quote->getCustomerEmail());
    // CRITICAL: Force shipping rates collection
    $shippingAddress->setCollectShippingRates(true);
    $shippingAddress->removeAllShippingRates();
    // Set weight for shipping calculation
    $totalWeight = 0;
    foreach ($quote->getAllItems() as $item) {
        $product = $item->getProduct();
        if ($product && $product->getWeight()) {
            $totalWeight += ($product->getWeight() * $item->getQty());
        }
    }
    $shippingAddress->setWeight($totalWeight > 0 ? $totalWeight : 1);
    // Collect shipping rates
    $shippingAddress->collectShippingRates();
    // Force totals calculation
    $quote->setTotalsCollectedFlag(false);
    $quote->collectTotals();
    $this->cartRepository->save($quote);
    // FIXED: Properly validate and set shipping method
    $requestedMethod = $orderData->getShippingMethod();
    $availableRates = $shippingAddress->getAllShippingRates();
    $methodFound = false;
    $this->logger->info('Setting shipping method', [
        'requested_method' => $requestedMethod,
        'available_rates_count' => count($availableRates)
    ]);
    // Check if requested method exists in available rates
    foreach ($availableRates as $rate) {
        $rateCode = $rate->getCarrier() . '_' . $rate->getMethod();
        $this->logger->info('Available rate', [
            'rate_code' => $rateCode,
            'carrier' => $rate->getCarrier(),
            'method' => $rate->getMethod(),
            'price' => $rate->getPrice()
        ]);
        if ($rateCode === $requestedMethod || 
            $rate->getCarrier() === $requestedMethod ||
            strpos($requestedMethod, $rate->getCarrier() . '_') === 0) {
            $shippingAddress->setShippingMethod($rateCode);
            $shippingAddress->setShippingDescription($rate->getCarrierTitle() . ' - ' . $rate->getMethodTitle());
            $methodFound = true;
            $this->logger->info('Shipping method set successfully', [
                'method_code' => $rateCode,
                'description' => $rate->getCarrierTitle() . ' - ' . $rate->getMethodTitle(),
                'price' => $rate->getPrice()
            ]);
            break;
        }
    }
    // If method not found, try to find similar method
    if (!$methodFound) {
        $this->logger->warning('Requested shipping method not found, trying alternatives', [
            'requested_method' => $requestedMethod
        ]);
        // Extract carrier from requested method
        $carrierCode = explode('_', $requestedMethod)[0];
        foreach ($availableRates as $rate) {
            if ($rate->getCarrier() === $carrierCode) {
                $rateCode = $rate->getCarrier() . '_' . $rate->getMethod();
                $shippingAddress->setShippingMethod($rateCode);
                $shippingAddress->setShippingDescription($rate->getCarrierTitle() . ' - ' . $rate->getMethodTitle());
                $methodFound = true;
                $this->logger->info('Alternative shipping method set', [
                    'original_request' => $requestedMethod,
                    'set_method' => $rateCode
                ]);
                break;
            }
        }
    }
    // Final fallback - use first available rate
    if (!$methodFound && !empty($availableRates)) {
        $firstRate = reset($availableRates);
        $rateCode = $firstRate->getCarrier() . '_' . $firstRate->getMethod();
        $shippingAddress->setShippingMethod($rateCode);
        $shippingAddress->setShippingDescription($firstRate->getCarrierTitle() . ' - ' . $firstRate->getMethodTitle());
        $this->logger->info('Fallback shipping method set', [
            'fallback_method' => $rateCode,
            'original_request' => $requestedMethod
        ]);
        $methodFound = true;
    }
    if (!$methodFound) {
        throw new LocalizedException(__('No valid shipping method available. Please try again.'));
    }
    // Final totals collection with shipping method
    $quote->setTotalsCollectedFlag(false);
    $quote->collectTotals();
    $this->cartRepository->save($quote);
    $this->logger->info('Shipping method final verification', [
        'quote_shipping_method' => $shippingAddress->getShippingMethod(),
        'quote_shipping_amount' => $shippingAddress->getShippingAmount(),
        'quote_grand_total' => $quote->getGrandTotal()
    ]);
}
    private function setPaymentMethod($quote, QuickOrderDataInterface $orderData): void
    {
        $payment = $quote->getPayment();
        $payment->importData(['method' => $orderData->getPaymentMethod()]);
    }
    private function sendOrderNotification($order): void
    {
        if ($this->helperData->isEmailNotificationEnabled()) {
            try {
                $this->orderSender->send($order);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to send order email: ' . $e->getMessage());
            }
        }
    }
    private function getOrderSuccessUrl($order): string
    {
        return $this->storeManager->getStore()->getUrl('checkout/onepage/success', [
            '_query' => ['order_id' => $order->getId()]
        ]);
    }
private function setAddressData($address, QuickOrderDataInterface $orderData, string $customerEmail): void
{
    // Split customer name into first and last name
    $fullName = trim($orderData->getCustomerName());
    $nameParts = explode(' ', $fullName, 2);
    $firstName = $nameParts[0];
    $lastName = isset($nameParts[1]) ? $nameParts[1] : $firstName; // Use first name if no last name
    $address->setFirstname($firstName);
    $address->setLastname($lastName); // FIXED: Always set lastname
    // Handle street address properly
    $streetAddress = $orderData->getAddress();
    if (strpos($streetAddress, ',') !== false) {
        $streetLines = array_map('trim', explode(',', $streetAddress));
    } else {
        $streetLines = [$streetAddress];
    }
    $address->setStreet($streetLines);
    $address->setCity($orderData->getCity());
    $address->setCountryId($orderData->getCountryId());
    $address->setTelephone($this->helperData->formatPhoneNumber($orderData->getCustomerPhone()));
    $address->setEmail($customerEmail);
    // CRITICAL: ensure we do NOT bind to a persisted customer address id
    if (method_exists($address, 'setCustomerAddressId')) {
        $address->setCustomerAddressId(null);
    }
    if (method_exists($address, 'setSaveInAddressBook')) {
        $address->setSaveInAddressBook(0);
    }
    if (method_exists($address, 'setSameAsBilling')) {
        $address->setSameAsBilling(0);
    }
    if ($orderData->getRegion()) {
        $regionId = $this->getRegionIdByName($orderData->getRegion(), $orderData->getCountryId());
        if ($regionId) {
            $address->setRegionId($regionId);
        }
        $address->setRegion($orderData->getRegion());
    }
    if ($orderData->getPostcode()) {
        $address->setPostcode($orderData->getPostcode());
    }
    // IMPORTANT: Ensure all required fields are set
    if (!$address->getCompany()) {
        $address->setCompany(''); // Set empty company to avoid issues
    }
}
    public function calculateShippingCost(
        int $productId,
        string $shippingMethod,
        string $countryId,
        ?string $region = null,
        ?string $postcode = null,
        int $qty = 1
    ): float {
        try {
            // أولاً: محاولة الحصول على طرق الشحن المتاحة
            $methods = $this->getAvailableShippingMethods($productId, $countryId, $region, $postcode, $qty);
            foreach ($methods as $method) {
                if ($method['code'] === $shippingMethod) {
                    $this->logger->info('تم العثور على طريقة الشحن المطلوبة', [
                        'method_code' => $shippingMethod,
                        'price' => $method['price']
                    ]);
                    return (float)$method['price'];
                }
            }
            // ثانياً: التحقق من قواعد الشحن المجاني
            $product = $this->productRepository->getById($productId);
            $subtotal = (float)$product->getFinalPrice() * $qty;
            $freeShippingThreshold = $this->helperData->getFreeShippingThreshold();
            if ($freeShippingThreshold > 0 && $subtotal >= $freeShippingThreshold) {
                $this->logger->info('تطبيق الشحن المجاني', [
                    'subtotal' => $subtotal,
                    'threshold' => $freeShippingThreshold
                ]);
                return 0.0;
            }
            // ثالثاً: استخدام السعر الافتراضي إذا كان متوفراً
            $defaultPrice = $this->helperData->getDefaultShippingPrice();
            if ($defaultPrice > 0) {
                $this->logger->info('استخدام السعر الافتراضي للشحن', [
                    'default_price' => $defaultPrice
                ]);
                return $defaultPrice;
            }
            // إذا لم تتوفر أي طريقة شحن، إرجاع 0
            $this->logger->warning('لا توجد طرق شحن متاحة', [
                'product_id' => $productId,
                'shipping_method' => $shippingMethod,
                'country_id' => $countryId
            ]);
            return 0.0;
        } catch (\Exception $e) {
            $this->logger->error('خطأ في حساب تكلفة الشحن: ' . $e->getMessage());
            return 0.0;
        }
    }
    /**
     * Calculate shipping cost for quote with specific method
     */
    public function calculateShippingCostForQuote($quote, $shippingMethodCode)
    {
        try {
            // FIXED: تحسين حساب تكلفة الشحن وتجنب التضارب
            $shippingAddress = $quote->getShippingAddress();
            if (!$shippingAddress || $quote->isVirtual()) {
                return 0.0;
            }
            // إعادة تعيين معدلات الشحن
            $shippingAddress->setCollectShippingRates(true);
            $shippingAddress->removeAllShippingRates();
            // جمع معدلات الشحن الجديدة
            $shippingAddress->collectShippingRates();
            // البحث عن الطريقة المطلوبة
            $rates = $shippingAddress->getAllShippingRates();
            foreach ($rates as $rate) {
                if ($rate->getCode() === $shippingMethodCode) {
                    $cost = $rate->getPrice();
                    $this->logger->info('Found shipping method', [
                        'method' => $shippingMethodCode,
                        'cost' => $cost,
                        'carrier' => $rate->getCarrier()
                    ]);
                    return (float)$cost;
                }
            }
            // إذا لم توجد الطريقة، استخدم القيمة الافتراضية
            $this->logger->warning('Shipping method not found, using fallback', [
                'requested_method' => $shippingMethodCode,
                'available_methods' => array_map(function($rate) {
                    return $rate->getCode();
                }, $rates)
            ]);
            return (float)$this->scopeConfig->getValue(
                'magoarab_easyorder/shipping/fallback_shipping_price',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );
        } catch (\Exception $e) {
            $this->logger->error('Error calculating shipping cost: ' . $e->getMessage());
            return 0.0;
        }
    }
    private function getRegionIdByName(string $regionName, string $countryId): ?int
    {
        try {
            $region = $this->regionFactory->create();
            $region->loadByName($regionName, $countryId);
            return $region->getId() ? (int)$region->getId() : null;
        } catch (\Exception $e) {
            $this->logger->warning('Could not find region ID for: ' . $regionName . ' in country: ' . $countryId);
            return null;
        }
    }
    private function formatPrice(float $price): string
    {
        return $this->priceHelper->currency($price, true, false);
    }
    /**
     * الطريقة المحسنة للحساب الديناميكي - تحاكي checkout تماماً
     */
    public function calculateDynamicPricing(
        int $productId,
        int $qty,
        string $shippingMethod,
        string $countryId,
        ?string $region = null,
        ?string $postcode = null
    ): array {
        try {
            // إنشاء quote واقعي مثل checkout
            $quote = $this->createCheckoutLikeQuote($productId, $countryId, $region, $postcode, $qty);
            // تطبيق قواعد الكتالوج أولاً
            $this->applyCatalogRules($quote);
            // ثم تطبيق قواعد السلة
            $this->applyCartRules($quote);
            // إعداد طريقة الشحن
            $this->setupShippingMethod($quote, $shippingMethod);
            // الحساب النهائي
            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();
            $this->cartRepository->save($quote);
            // FIXED: استخراج النتائج مع حساب سعر الوحدة الصحيح
            $productPrice = 0;
            $originalPrice = 0;
            foreach ($quote->getAllItems() as $item) {
                $itemQty = (int)$item->getQty();
                if ($itemQty > 0) {
                    // سعر الوحدة = السعر الإجمالي ÷ الكمية
                    $productPrice = (float)$item->getRowTotal() / $itemQty;
                } else {
                    $productPrice = (float)$item->getPrice();
                }
                $originalPrice = (float)$item->getProduct()->getPrice();
                break;
            }
            return [
                'product_price' => $productPrice, // سعر الوحدة الواحدة
                'original_price' => $originalPrice,
                'subtotal' => (float)$quote->getSubtotal(),
                'subtotal_incl_tax' => (float)$quote->getSubtotalInclTax(),
                'shipping_cost' => (float)$quote->getShippingAddress()->getShippingAmount(),
                'discount_amount' => (float)($quote->getSubtotal() - $quote->getSubtotalWithDiscount()),
                'total' => (float)$quote->getGrandTotal(),
                'applied_rule_ids' => $quote->getAppliedRuleIds() ?: ''
            ];
        } catch (\Exception $e) {
            $this->logger->error('خطأ في الحساب الديناميكي: ' . $e->getMessage());
            return $this->getFallbackCalculation($productId, $qty, $shippingMethod, $countryId, $region, $postcode);
        }
    }
    /**
     * إنشاء quote يحاكي checkout تماماً
     */
    private function createCheckoutLikeQuote(int $productId, string $countryId, ?string $region = null, ?string $postcode = null, int $qty = 1)
    {
        $product = $this->productRepository->getById($productId);
        $store = $this->storeManager->getStore();
        // إنشاء quote مثل checkout تماماً
        $quote = $this->quoteFactory->create();
        $quote->setStore($store);
        $quote->setStoreId($store->getId());
        $quote->setIsActive(true);
        $quote->setIsMultiShipping(false);
        // إعداد العميل (guest)
        $quote->setCustomerIsGuest(true);
        $quote->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);
        // FIXED: التأكد من إضافة المنتج مرة واحدة فقط بالكمية الصحيحة
        $request = new \Magento\Framework\DataObject([
            'qty' => $qty,
            'product' => $product->getId()
        ]);
        // التحقق من عدم وجود المنتج مسبقاً
        $existingItem = $quote->getItemByProduct($product);
        if (!$existingItem) {
            $quote->addProduct($product, $request);
        } else {
            // تحديث الكمية إذا كان المنتج موجود
            $existingItem->setQty($qty);
        }
        // إعداد عناوين الفوترة والشحن
        $billingAddress = $quote->getBillingAddress();
        $billingAddress->setCountryId($countryId);
        if ($region) $billingAddress->setRegion($region);
        if ($postcode) $billingAddress->setPostcode($postcode);
        $shippingAddress = $quote->getShippingAddress();
        $shippingAddress->setCountryId($countryId);
        if ($region) $shippingAddress->setRegion($region);
        if ($postcode) $shippingAddress->setPostcode($postcode);
        $shippingAddress->setCollectShippingRates(true);
        // حفظ الـ quote
        $this->cartRepository->save($quote);
        return $quote;
    }
    /**
     * إعداد طريقة الشحن
     */
    private function setupShippingMethod($quote, string $shippingMethod): void
    {
        $shippingAddress = $quote->getShippingAddress();
        $shippingAddress->setShippingMethod($shippingMethod);
        $shippingAddress->setCollectShippingRates(true);
        $shippingAddress->collectShippingRates();
    }
    /**
     * تحسين تطبيق قواعد الأسعار مع ضمان تطبيق جميع القواعد
     */
public function calculateOrderTotalWithDynamicRules(
    int $productId,
    int $qty,
    string $shippingMethod,
    string $countryId,
    ?string $region = null,
    ?string $postcode = null,
    ?string $couponCode = null
): array {
    try {
        $this->logger->info('Starting session-based calculation', [
            'product_id' => $productId,
            'qty' => $qty,
            'shipping_method' => $shippingMethod,
            'coupon' => $couponCode
        ]);

        // Use active session quote to mirror Magento core behaviour
        $quote = $this->checkoutSession->getQuote();
        if (!$quote || !$quote->getId()) {
            $quote = $this->quoteFactory->create();
            $quote->setStore($this->storeManager->getStore());
            $this->cartRepository->save($quote);
            $this->checkoutSession->replaceQuote($quote);
        }

        // Reset items to match current PDP selection
        foreach ($quote->getAllItems() as $item) {
            $quote->removeItem($item->getId());
        }

        // Load product and add with qty (handles configurable via selected attributes)
        $product = $this->productRepository->getById($productId);
        $this->addProductToQuote($quote, $product, $qty);

        // Set addresses for proper rule evaluation
        $this->setCalculationAddresses($quote, $countryId, $region, $postcode);

        // Set requested shipping method
        $this->setShippingMethodOnQuote($quote, $shippingMethod);

        // Apply coupon if provided using core API when available
        if (!empty($couponCode)) {
            try {
                $om = \Magento\Framework\App\ObjectManager::getInstance();
                /** @var \Magento\Quote\Api\CouponManagementInterface $couponMgmt */
                $couponMgmt = $om->get(\Magento\Quote\Api\CouponManagementInterface::class);
                $couponMgmt->set($quote->getId(), $couponCode);
            } catch (\Throwable $t) {
                $quote->setCouponCode($couponCode);
            }
        }

        // Collect totals following core pipeline
        $this->applyCatalogRules($quote);
        $this->applyCartRules($quote);

        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->cartRepository->save($quote);

        return $this->extractCalculationResults($quote);
    } catch (\Exception $e) {
        $this->logger->error('Calculation failed: ' . $e->getMessage());
        return $this->getFallbackCalculation($productId, $qty, $shippingMethod, $countryId, $region, $postcode);
    }
}
    /**
     * Internal method for calculating order total with dynamic rules
     */
    private function calculateOrderTotalWithDynamicRulesInternal($quote): array
    {
        try {
            // FIXED: تحسين إدارة Quote وتجنب الـ conflicts
            // 1. حفظ الحالة الأصلية للـ quote
            $originalTotalsCollectedFlag = $quote->getTotalsCollectedFlag();
            $originalDataChanges = $quote->getDataChanges();
            // 2. إعادة تعيين flags بشكل صحيح
            $quote->setTotalsCollectedFlag(false);
            // 3. إعادة تعيين shipping rates للعناوين
            foreach ($quote->getAllAddresses() as $address) {
                $address->setCollectShippingRates(true);
                $address->removeAllShippingRates();
            }
            // 4. تطبيق قواعد الكتالوج أولاً
            $this->applyCatalogRules($quote);
            // 5. جمع المعدلات والمجاميع بالترتيب الصحيح
            $quote->collectTotals();
            // 6. تطبيق قواعد السلة بعد حساب المجاميع
            $this->applyCartRules($quote);
            // 7. إعادة حساب المجاميع النهائية
            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();
            // 8. حساب تكلفة الشحن بشكل صحيح مع معالجة محسنة للشحن المجاني
            $shippingAddress = $quote->getShippingAddress();
            $shippingCost = 0.0;
            if (!$quote->isVirtual() && $shippingAddress) {
                $subtotal = $quote->getSubtotal();
                // التحقق من الشحن المجاني من عدة مصادر
                $freeShippingFlag = $shippingAddress->getFreeShipping();
                $shouldApplyFreeShipping = $this->helperData->shouldApplyFreeShipping($subtotal);
                // تحقق من قواعد الشحن المجاني في ماجنتو
                $freeShippingRules = false;
                if ($shippingAddress->getShippingMethod() === 'freeshipping_freeshipping') {
                    $freeShippingRules = true;
                }
                $this->logger->info('Free shipping analysis', [
                    'subtotal' => $subtotal,
                    'free_shipping_flag' => $freeShippingFlag,
                    'threshold_check' => $shouldApplyFreeShipping,
                    'free_shipping_rules' => $freeShippingRules,
                    'shipping_method' => $shippingAddress->getShippingMethod()
                ]);
                // تطبيق الشحن المجاني إذا تحقق أي شرط
                if ($freeShippingFlag || $shouldApplyFreeShipping || $freeShippingRules) {
                    $shippingCost = 0.0;
                    // فرض الشحن المجاني على العنوان
                    $shippingAddress->setShippingAmount(0);
                    $shippingAddress->setBaseShippingAmount(0);
                    $shippingAddress->setFreeShipping(true);
                    $this->logger->info('Free shipping applied in calculation', [
                        'reason' => $freeShippingFlag ? 'flag' : ($shouldApplyFreeShipping ? 'threshold' : 'rules')
                    ]);
                } else {
                    $baseShippingAmount = $shippingAddress->getBaseShippingAmount();
                    $shippingAmount = $shippingAddress->getShippingAmount();
                    // استخدام القيمة الأساسية أو العادية حسب التوفر
                    $shippingCost = $baseShippingAmount ?: $shippingAmount;
                    // التأكد من أن القيمة منطقية
                    if ($shippingCost < 0) {
                        $shippingCost = 0.0;
                    }
                }
            }
            // 9. حساب النتائج النهائية
            $subtotal = $quote->getSubtotal();
            $grandTotal = $quote->getGrandTotal();
            $discount = $quote->getSubtotal() - $quote->getSubtotalWithDiscount();
            // FIXED: حساب سعر الوحدة الصحيح
            $productPrice = $subtotal;
            $items = $quote->getAllVisibleItems();
            if (!empty($items)) {
                $item = $items[0];
                $qty = (int)$item->getQty();
                if ($qty > 0) {
                    // سعر الوحدة = السعر الإجمالي ÷ الكمية
                    $productPrice = (float)$item->getRowTotal() / $qty;
                }
            }
            // 10. تسجيل النتائج للمراجعة
            $this->logger->info('Final calculation results', [
                'product_price' => $productPrice,
                'subtotal' => $subtotal,
                'shipping_cost' => $shippingCost,
                'discount' => $discount,
                'grand_total' => $grandTotal,
                'quote_id' => $quote->getId()
            ]);
            return [
                'product_price' => $productPrice, // FIXED: سعر الوحدة الصحيح
                'subtotal' => $subtotal,
                'shipping_cost' => $shippingCost,
                'discount' => $discount,
                'total' => $grandTotal
            ];
        } catch (\Exception $e) {
            $this->logger->error('Error in calculateOrderTotalWithDynamicRulesInternal: ' . $e->getMessage());
            return $this->getFallbackCalculationFromQuote($quote);
        }
    }
    /**
 * Create a single quote for calculation purposes
 */
private function createSingleQuoteForCalculation(int $productId, string $countryId, ?string $region = null, ?string $postcode = null, int $qty = 1)
{
    try {
        $product = $this->productRepository->getById($productId);
        $store = $this->storeManager->getStore();
        // Create clean quote
        $quote = $this->quoteFactory->create();
        $quote->setStore($store);
        $quote->setStoreId($store->getId());
        $quote->setCurrency();
        // Set customer context for rules
        $quote->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);
        $quote->setCustomerIsGuest(true);
        $quote->setCustomerEmail('guest@calculation.local');
        // Add product with correct quantity - ONCE
        $this->addProductToQuote($quote, $product, $qty);
        // Set addresses for location-based rules
        $this->setCalculationAddresses($quote, $countryId, $region, $postcode);
        // Save quote
        $this->cartRepository->save($quote);
        return $quote;
    } catch (\Exception $e) {
        $this->logger->error('Failed to create calculation quote: ' . $e->getMessage());
        throw $e;
    }
}
/**
 * Add product to quote without duplication
 */
private function addProductToQuote($quote, $product, int $qty)
{
    // Handle configurable products
    if ($product->getTypeId() === 'configurable') {
        $selectedAttributes = $this->getSelectedProductAttributes();
        if ($selectedAttributes && !empty($selectedAttributes)) {
            $simpleProduct = $product->getTypeInstance()->getProductByAttributes($selectedAttributes, $product);
            if ($simpleProduct) {
                $product = $simpleProduct;
            }
        } else {
            $simpleProduct = $this->getFirstAvailableSimpleProduct($product);
            if ($simpleProduct) {
                $product = $simpleProduct;
            }
        }
    }
    // Add product with exact quantity
    $request = new \Magento\Framework\DataObject([
        'qty' => $qty,
        'product' => $product->getId()
    ]);
    $quote->addProduct($product, $request);
}
/**
 * Set shipping method on quote
 */
private function setShippingMethodOnQuote($quote, string $shippingMethod)
{
    $shippingAddress = $quote->getShippingAddress();
    if (!$quote->isVirtual() && $shippingAddress) {
        // Force shipping rates collection
        $shippingAddress->setCollectShippingRates(true);
        $shippingAddress->removeAllShippingRates();
        $shippingAddress->collectShippingRates();
        // Set shipping method
        $shippingAddress->setShippingMethod($shippingMethod);
    }
}
/**
 * Set addresses for calculation
 */
private function setCalculationAddresses($quote, string $countryId, ?string $region = null, ?string $postcode = null)
{
    $addressData = [
        'country_id' => $countryId,
        'region' => $region ?: 'Cairo',
        'postcode' => $postcode ?: '11511',
        'city' => 'Cairo',
        'street' => ['123 Main St'],
        'firstname' => 'Guest',
        'lastname' => 'Customer',
        'telephone' => '01234567890',
        'email' => 'guest@calculation.local'
    ];
    // Set billing address
    $billingAddress = $quote->getBillingAddress();
    $billingAddress->addData($addressData);
    // Set shipping address
    if (!$quote->isVirtual()) {
        $shippingAddress = $quote->getShippingAddress();
        $shippingAddress->addData($addressData);
    }
}
/**
     * Extract calculation results from quote
     */
    private function extractCalculationResults($quote): array
    {
        $subtotal = (float)$quote->getSubtotal();
        $grandTotal = (float)$quote->getGrandTotal();
        $shippingAmount = 0.0;
        $discountAmount = 0.0;
        // Get shipping cost
        if (!$quote->isVirtual()) {
            $shippingAddress = $quote->getShippingAddress();
            $shippingAmount = (float)$shippingAddress->getShippingAmount();
        }
        // Calculate discount using Magento's official methods
        $shippingAddress = $quote->getShippingAddress();
        $billingAddress = $quote->getBillingAddress();
        
        // Get total discount amount from addresses (Magento's standard way)
        $totalDiscountAmount = 0;
        
        // Cart rules discount from shipping address
        if ($shippingAddress) {
            $shippingDiscount = abs((float)$shippingAddress->getDiscountAmount());
            $totalDiscountAmount += $shippingDiscount;
        }
        
        // Additional discount from billing address if different
        if ($billingAddress && !$quote->isVirtual()) {
            $billingDiscount = abs((float)$billingAddress->getDiscountAmount());
            // Only add if different from shipping (to avoid double counting)
            if ($billingDiscount != $shippingDiscount) {
                $totalDiscountAmount += $billingDiscount;
            }
        }
        
        // For virtual quotes, use billing address discount
        if ($quote->isVirtual() && $billingAddress) {
            $totalDiscountAmount = abs((float)$billingAddress->getDiscountAmount());
        }
        
        // Alternative method: Calculate from totals if above method gives 0
        if ($totalDiscountAmount == 0) {
            // Use official Magento way: difference between subtotal and subtotal with discount
            $catalogPriceDiscount = $subtotal - (float)$quote->getSubtotalWithDiscount();
            $totalDiscountAmount = $catalogPriceDiscount;
        }
        
        $discountAmount = $totalDiscountAmount;
        
        // Log discount calculation for debugging
        $this->logger->info('Magento 2.4.7 Standard Discount Calculation', [
            'quote_id' => $quote->getId(),
            'subtotal' => $subtotal,
            'subtotal_with_discount' => $quote->getSubtotalWithDiscount(),
            'shipping_address_discount' => $shippingAddress ? $shippingAddress->getDiscountAmount() : 0,
            'billing_address_discount' => $billingAddress ? $billingAddress->getDiscountAmount() : 0,
            'calculated_total_discount' => $discountAmount,
            'applied_rule_ids' => $quote->getAppliedRuleIds(),
            'coupon_code' => $quote->getCouponCode(),
            'is_virtual' => $quote->isVirtual()
        ]);
        // Calculate unit price correctly using Magento's methods
        $productPrice = 0;
        $items = $quote->getAllVisibleItems();
        if (!empty($items)) {
            $item = $items[0];
            $qty = (int)$item->getQty();
            
            if ($qty > 0) {
                // Use the actual price per unit after catalog rules
                $productPrice = (float)$item->getPrice();
                
                // If price is 0, fallback to calculated price from row total
                if ($productPrice == 0) {
                    $productPrice = (float)$item->getRowTotal() / $qty;
                }
                
                // Log product pricing details
                $this->logger->info('Product price calculation', [
                    'item_id' => $item->getId(),
                    'product_id' => $item->getProductId(),
                    'qty' => $qty,
                    'original_price' => $item->getProduct()->getPrice(),
                    'item_price' => $item->getPrice(),
                    'row_total' => $item->getRowTotal(),
                    'calculated_unit_price' => $productPrice,
                    'item_discount_amount' => $item->getDiscountAmount()
                ]);
            }
        }
        return [
            'product_price' => $productPrice,
            'subtotal' => $subtotal,
            'shipping_cost' => $shippingAmount,
            'discount_amount' => $discountAmount,
            'total' => $grandTotal,
            'applied_rule_ids' => $quote->getAppliedRuleIds() ?: '',
            'has_discount' => $discountAmount > 0,
            'coupon_code' => $quote->getCouponCode() ?: ''
        ];
    }
/**
 * Enhanced quote creation that prevents price doubling
 */
private function createEnhancedQuoteForCalculation(int $productId, string $countryId, ?string $region = null, ?string $postcode = null, int $qty = 1)
{
    static $quoteCache = [];
    $cacheKey = md5($productId . $countryId . $region . $postcode . $qty);
    // Return cached quote if exists and valid
    if (isset($quoteCache[$cacheKey])) {
        $cachedQuote = $quoteCache[$cacheKey];
        if ($cachedQuote && $cachedQuote->getId()) {
            return $cachedQuote;
        }
    }
    try {
        $product = $this->productRepository->getById($productId);
        $store = $this->storeManager->getStore();
        // Create fresh quote
        $quote = $this->quoteFactory->create();
        $quote->setStore($store);
        $quote->setStoreId($store->getId());
        $quote->setCurrency();
        // Set customer context for proper rule application
        $quote->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);
        $quote->setCustomerIsGuest(true);
        $quote->setCustomerEmail('calculation@guest.local');
        // Handle product variants properly
        if ($product->getTypeId() === 'configurable') {
            $selectedAttributes = $this->getSelectedProductAttributes();
            if ($selectedAttributes && !empty($selectedAttributes)) {
                $simpleProduct = $product->getTypeInstance()->getProductByAttributes($selectedAttributes, $product);
                if ($simpleProduct) {
                    $product = $this->productRepository->getById($simpleProduct->getId());
                }
            } else {
                $firstSimple = $this->getFirstAvailableSimpleProduct($product);
                if ($firstSimple) {
                    $product = $firstSimple;
                }
            }
        }
        // Add product ONCE with correct quantity
        $request = new \Magento\Framework\DataObject([
            'qty' => $qty,
            'product' => $product->getId()
        ]);
        $quote->addProduct($product, $request);
        // Set proper addresses for location-based rules
        $this->setEnhancedAddresses($quote, $countryId, $region, $postcode);
        // Single totals collection
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->cartRepository->save($quote);
        // Cache the quote
        $quoteCache[$cacheKey] = $quote;
        $this->logger->info('Enhanced quote created successfully', [
            'quote_id' => $quote->getId(),
            'product_id' => $productId,
            'qty' => $qty,
            'subtotal' => $quote->getSubtotal(),
            'items_count' => count($quote->getAllVisibleItems())
        ]);
        return $quote;
    } catch (\Exception $e) {
        $this->logger->error('Enhanced quote creation failed: ' . $e->getMessage());
        throw $e;
    }
}
    /**
     * Fallback calculation from existing quote
     */
    private function getFallbackCalculationFromQuote($quote): array
    {
        try {
            $subtotal = $quote->getSubtotal();
            $grandTotal = $quote->getGrandTotal();
            $shippingAmount = $quote->getShippingAddress()->getShippingAmount();
            // FIXED: حساب سعر الوحدة الصحيح
            $productPrice = $subtotal;
            $items = $quote->getAllVisibleItems();
            if (!empty($items)) {
                $item = $items[0];
                $qty = (int)$item->getQty();
                if ($qty > 0) {
                    $productPrice = (float)$item->getRowTotal() / $qty;
                }
            }
            return [
                'product_price' => $productPrice, // سعر الوحدة الواحدة
                'subtotal' => $subtotal,
                'shipping_cost' => $shippingAmount ?: 0.0,
                'discount' => 0.0,
                'total' => $grandTotal
            ];
        } catch (\Exception $e) {
            $this->logger->error('Fallback calculation failed: ' . $e->getMessage());
            return [
                'product_price' => 0.0,
                'subtotal' => 0.0,
                'shipping_cost' => 0.0,
                'discount' => 0.0,
                'total' => 0.0
            ];
        }
    }
    /**
     * تطبيق قواعد الكتالوج بالطريقة الصحيحة - مثل checkout
     */
    private function applyCatalogRules($quote): void
    {
        try {
            $this->logger->info('بدء تطبيق قواعد الكتالوج على نفس الـ Quote', [
                'quote_id' => $quote->getId(),
                'customer_group_id' => $quote->getCustomerGroupId(),
                'website_id' => $this->storeManager->getStore()->getWebsiteId()
            ]);
            foreach ($quote->getAllVisibleItems() as $item) {
                try {
                    $product = $item->getProduct();
                    if (!$product || !$product->getId() || !$this->catalogRuleResource) {
                        continue;
                    }
                    $currentDate = $this->timezone->date();
                    $rulePrice = $this->catalogRuleResource->getRulePrice(
                        $currentDate,
                        $this->storeManager->getStore()->getWebsiteId(),
                        $quote->getCustomerGroupId(),
                        $product->getId()
                    );
                    if ($rulePrice !== false && $rulePrice !== null && is_numeric($rulePrice)) {
                        $originalPrice = (float)$product->getPrice();
                        $rulePrice = (float)$rulePrice;
                        
                        // Only apply if rule price is lower and valid
                        if ($rulePrice < $originalPrice && $rulePrice > 0) {
                            // Use Magento's standard way to set custom price
                            $item->setCustomPrice($rulePrice);
                            $item->setOriginalCustomPrice($rulePrice);
                            $item->getProduct()->setSpecialPrice($rulePrice);
                            
                            // Recalculate row total
                            $item->calcRowTotal();
                            
                            $this->logger->info('Catalog rule applied to item', [
                                'product_id' => $product->getId(),
                                'original_price' => $originalPrice,
                                'rule_price' => $rulePrice,
                                'new_row_total' => $item->getRowTotal()
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->error('خطأ في تطبيق قاعدة كتالوج للمنتج: ' . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('خطأ عام في تطبيق قواعد الكتالوج: ' . $e->getMessage());
        }
    }
    /**
     * Apply cart price rules using Magento's official methods (2.4.7)
     */
    private function applyCartRules($quote): void
    {
        try {
            $this->logger->info('Applying cart rules using Magento 2.4.7 methods', [
                'quote_id' => $quote->getId(),
                'coupon_code' => $quote->getCouponCode(),
                'customer_group_id' => $quote->getCustomerGroupId(),
                'website_id' => $quote->getStore()->getWebsiteId()
            ]);
            
            // Ensure quote addresses are set properly for rules validation
            $shippingAddress = $quote->getShippingAddress();
            $billingAddress = $quote->getBillingAddress();
            
            if ($shippingAddress) {
                $shippingAddress->setCollectShippingRates(true);
            }
            
            // Clear previous totals and force recalculation
            $quote->setTotalsCollectedFlag(false);
            
            // Use Magento's standard totals collection (this applies cart rules)
            $quote->collectTotals();
            
            // Save quote to persist rule applications
            $this->cartRepository->save($quote);
            
            // Log the results of cart rule application
            $this->logger->info('Cart rules applied', [
                'applied_rule_ids' => $quote->getAppliedRuleIds(),
                'subtotal' => $quote->getSubtotal(),
                'subtotal_with_discount' => $quote->getSubtotalWithDiscount(),
                'shipping_discount' => $shippingAddress ? $shippingAddress->getDiscountAmount() : 0,
                'grand_total' => $quote->getGrandTotal()
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Error applying cart rules: ' . $e->getMessage());
        }
    }
    /**
     * الحصول على قواعد السلة النشطة
     */
    private function getActiveCartRules($quote): array
    {
        $rules = [];
        try {
            $ruleCollection = $this->cartRuleFactory->create()->getCollection()
                ->addWebsiteFilter($quote->getStore()->getWebsiteId())
                ->addCustomerGroupFilter($quote->getCustomerGroupId())
                ->addDateFilter()
                ->addIsActiveFilter();
            foreach ($ruleCollection as $rule) {
                $rules[] = $rule;
            }
        } catch (\Exception $e) {
            $this->logger->error('خطأ في الحصول على قواعد السلة النشطة: ' . $e->getMessage());
        }
        return $rules;
    }
    /**
     * إعادة حساب طرق الشحن بعد تطبيق قواعد الأسعار
     */
    private function recalculateShippingAfterPriceRules($quote, string $requestedShippingMethod): void
    {
        $this->logger->info('إعادة حساب طرق الشحن بعد تطبيق قواعد الأسعار', [
            'quote_id' => $quote->getId(),
            'subtotal_before_shipping' => $quote->getSubtotal(),
            'requested_shipping_method' => $requestedShippingMethod
        ]);
        $shippingAddress = $quote->getShippingAddress();
        // إزالة طرق الشحن الحالية لإعادة حسابها
        $shippingAddress->removeAllShippingRates();
        $shippingAddress->setCollectShippingRates(true);
        // إعادة حساب طرق الشحن بناءً على السعر الجديد
        $shippingAddress->collectShippingRates();
        // البحث عن طريقة الشحن المطلوبة في الطرق المحدثة
        $availableRates = $shippingAddress->getAllShippingRates();
        $methodFound = false;
        foreach ($availableRates as $rate) {
            if ($rate->getCode() === $requestedShippingMethod) {
                $shippingAddress->setShippingMethod($requestedShippingMethod);
                $methodFound = true;
                $this->logger->info('تم العثور على طريقة الشحن المطلوبة بعد إعادة الحساب', [
                    'shipping_method' => $requestedShippingMethod,
                    'shipping_cost' => $rate->getPrice(),
                    'method_title' => $rate->getMethodTitle()
                ]);
                break;
            }
        }
        if (!$methodFound) {
            // إذا لم تعد طريقة الشحن متاحة، استخدم أول طريقة متاحة
            if (!empty($availableRates)) {
                $firstRate = reset($availableRates);
                $shippingAddress->setShippingMethod($firstRate->getCode());
                $this->logger->warning('طريقة الشحن المطلوبة غير متاحة، تم استخدام البديل', [
                    'requested_method' => $requestedShippingMethod,
                    'fallback_method' => $firstRate->getCode(),
                    'fallback_cost' => $firstRate->getPrice()
                ]);
            } else {
                $this->logger->error('لا توجد طرق شحن متاحة بعد إعادة الحساب');
            }
        }
        // لوج جميع طرق الشحن المتاحة للتشخيص
        $this->logger->info('طرق الشحن المتاحة بعد إعادة الحساب', [
            'available_methods' => array_map(function($rate) {
                return [
                    'code' => $rate->getCode(),
                    'method_title' => $rate->getMethodTitle(),
                    'carrier_title' => $rate->getCarrierTitle(),
                    'price' => $rate->getPrice()
                ];
            }, $availableRates)
        ]);
    }

    /**
     * If a free shipping method is available, select it to match frontend summary
     */
    private function enforceFreeShippingMethodIfAvailable($quote): void
    {
        try {
            $shippingAddress = $quote->getShippingAddress();
            $availableRates = $shippingAddress->getAllShippingRates();
            $freeRate = null;
            foreach ($availableRates as $rate) {
                $price = (float)$rate->getPrice();
                if ($price <= 0) {
                    $freeRate = $rate;
                    break;
                }
            }
            if ($freeRate) {
                $code = $freeRate->getCarrier() . '_' . $freeRate->getMethod();
                $shippingAddress->setShippingMethod($code);
                $shippingAddress->setFreeShipping(true);
                $quote->setTotalsCollectedFlag(false);
                $quote->collectTotals();
                $this->logger->info('Free shipping method enforced', [
                    'method' => $code
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to enforce free shipping: ' . $e->getMessage());
        }
    }

    /**
     * Ensure shipping method and amounts at order placement match the latest collected rates/thresholds
     * This fixes discrepancies where frontend summary shows free shipping/discount but order totals differ.
     */
    private function enforceFinalShippingConsistency($quote, string $requestedShippingMethod): void
    {
        try {
            $shippingAddress = $quote->getShippingAddress();
            if ($quote->isVirtual() || !$shippingAddress) {
                return;
            }

            // Re-collect rates one last time after coupon/rules
            $shippingAddress->setCollectShippingRates(true);
            $shippingAddress->removeAllShippingRates();
            $shippingAddress->collectShippingRates();

            // Force totals with current address data to ensure shipping is recalculated on Magento side
            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();

            // Try to keep requested method if still available
            $selectedRate = null;
            foreach ($shippingAddress->getAllShippingRates() as $rate) {
                if ($rate->getCode() === $requestedShippingMethod) {
                    $selectedRate = $rate;
                    break;
                }
            }
            // If requested not available, pick a zero-cost rate if exists, otherwise first
            if (!$selectedRate) {
                foreach ($shippingAddress->getAllShippingRates() as $rate) {
                    if ((float)$rate->getPrice() <= 0) { $selectedRate = $rate; break; }
                }
            }
            if (!$selectedRate) {
                $rates = $shippingAddress->getAllShippingRates();
                if (!empty($rates)) { $selectedRate = reset($rates); }
            }

            if ($selectedRate) {
                $code = $selectedRate->getCarrier() . '_' . $selectedRate->getMethod();
                $shippingAddress->setShippingMethod($code);
                $shippingAddress->setShippingDescription($selectedRate->getCarrierTitle() . ' - ' . $selectedRate->getMethodTitle());
                // Apply free shipping when rate is zero or threshold says free
                $shouldBeFree = ((float)$selectedRate->getPrice() == 0) || $this->helperData->shouldApplyFreeShippingForQuote($quote);
                if ($shouldBeFree) {
                    $shippingAddress->setShippingAmount(0);
                    $shippingAddress->setBaseShippingAmount(0);
                    $shippingAddress->setFreeShipping(true);
                    $this->logger->info('Free shipping applied in final consistency check', [
                        'method' => $code,
                        'rate_price' => $selectedRate->getPrice(),
                        'threshold_met' => $this->helperData->shouldApplyFreeShippingForQuote($quote)
                    ]);
                } else {
                    // Set exact rate price to avoid Magento recalculating a different amount later
                    $shippingAmount = (float)$selectedRate->getPrice();
                    $shippingAddress->setShippingAmount($shippingAmount);
                    $shippingAddress->setBaseShippingAmount($shippingAmount);
                    $shippingAddress->setFreeShipping(false);
                    $this->logger->info('Shipping amount set from rate', [
                        'method' => $code,
                        'amount' => $shippingAmount
                    ]);
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed enforcing final shipping consistency: ' . $e->getMessage());
        }
    }

    /**
     * Ensure all Magento rules are properly applied to quote
     */
    private function ensureQuoteRulesApplication($quote, $orderData): void
    {
        try {
            $this->logger->info('Ensuring proper rules application on quote', [
                'quote_id' => $quote->getId(),
                'before_subtotal' => $quote->getSubtotal(),
                'before_grand_total' => $quote->getGrandTotal(),
                'existing_applied_rules' => $quote->getAppliedRuleIds()
            ]);

            // Apply catalog price rules first (these affect item prices)
            $this->applyCatalogRules($quote);
            
            // Apply cart price rules (these affect totals)
            $this->applyCartRules($quote);
            
            // Apply coupon if provided
            $couponCode = $orderData->getCouponCode();
            if ($couponCode && $couponCode !== $quote->getCouponCode()) {
                $quote->setCouponCode($couponCode);
            }
            
            // Final totals collection to ensure everything is calculated
            $quote->setTotalsCollectedFlag(false);
            $quote->setData('trigger_recollect', true);
            $this->cartRepository->save($quote);
            $quote->collectTotals();
            $this->cartRepository->save($quote);
            
            $this->logger->info('Rules applied successfully to quote', [
                'after_subtotal' => $quote->getSubtotal(),
                'after_shipping' => $quote->getShippingAddress()->getShippingAmount(),
                'after_discount' => $quote->getShippingAddress()->getDiscountAmount(),
                'after_grand_total' => $quote->getGrandTotal(),
                'coupon_code' => $quote->getCouponCode(),
                'applied_rule_ids' => $quote->getAppliedRuleIds(),
                'discount_description' => $quote->getShippingAddress()->getDiscountDescription()
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to ensure quote rules application: ' . $e->getMessage());
        }
    }

    /**
     * Force frontend totals directly on quote before order creation
     */
    private function forceFrontendTotalsOnOrder($quote, array $frontendValues): void
    {
        try {
            $this->logger->info('Forcing frontend values directly on quote before order', [
                'quote_id' => $quote->getId(),
                'frontend_values' => $frontendValues
            ]);

            // Force subtotal
            if (isset($frontendValues['subtotal'])) {
                $quote->setSubtotal($frontendValues['subtotal']);
                $quote->setBaseSubtotal($frontendValues['subtotal']);
            }

            // Force shipping
            $shippingAddress = $quote->getShippingAddress();
            if (isset($frontendValues['shipping'])) {
                $shippingAddress->setShippingAmount($frontendValues['shipping']);
                $shippingAddress->setBaseShippingAmount($frontendValues['shipping']);
                if ($frontendValues['shipping'] == 0) {
                    $shippingAddress->setFreeShipping(true);
                }
            }

            // Force discount
            if (isset($frontendValues['discount']) && $frontendValues['discount'] > 0) {
                $discountAmount = -$frontendValues['discount']; // Negative for discount
                $shippingAddress->setDiscountAmount($discountAmount);
                $shippingAddress->setBaseDiscountAmount($discountAmount);
                $quote->setSubtotalWithDiscount($frontendValues['subtotal'] + $discountAmount);
            }

            // Force grand total
            if (isset($frontendValues['grand_total'])) {
                $quote->setGrandTotal($frontendValues['grand_total']);
                $quote->setBaseGrandTotal($frontendValues['grand_total']);
            }

            // Prevent any recalculation
            $quote->setTotalsCollectedFlag(true);
            $quote->setData('trigger_recollect', false);
            
            $this->cartRepository->save($quote);

            $this->logger->info('Frontend values forced on quote successfully', [
                'final_subtotal' => $quote->getSubtotal(),
                'final_shipping' => $shippingAddress->getShippingAmount(),
                'final_discount' => $shippingAddress->getDiscountAmount(),
                'final_grand_total' => $quote->getGrandTotal()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to force frontend values on quote: ' . $e->getMessage());
        }
    }

    /**
     * Force exact frontend values on order after creation
     */
    private function forceExactFrontendValuesOnOrder($order, array $frontendValues): void
    {
        try {
            $this->logger->info('=== FORCING FRONTEND VALUES ON ORDER ===', [
                'order_id' => $order->getId(),
                'order_increment_id' => $order->getIncrementId(),
                'frontend_values' => $frontendValues,
                'before_forcing' => [
                    'subtotal' => $order->getSubtotal(),
                    'shipping' => $order->getShippingAmount(),
                    'discount' => $order->getDiscountAmount(),
                    'grand_total' => $order->getGrandTotal(),
                    'applied_rule_ids' => $order->getAppliedRuleIds(),
                    'coupon_code' => $order->getCouponCode(),
                    'discount_description' => $order->getDiscountDescription()
                ]
            ]);
            
            // WARNING: Forcing values will remove rule information from Admin
            $this->logger->warning('=== RULES WILL BE LOST ===', [
                'reason' => 'forcing_frontend_values',
                'admin_impact' => 'Rules will not show in admin panel when values are forced',
                'current_rules' => $order->getAppliedRuleIds(),
                'current_coupon' => $order->getCouponCode(),
                'recommendation' => 'Use rule-based approach instead of forcing for Admin visibility'
            ]);

            // Force subtotal
            if (isset($frontendValues['subtotal'])) {
                $order->setSubtotal($frontendValues['subtotal']);
                $order->setBaseSubtotal($frontendValues['subtotal']);
                $this->logger->info('Set subtotal to: ' . $frontendValues['subtotal']);
            }

            // Force shipping
            if (isset($frontendValues['shipping'])) {
                $order->setShippingAmount($frontendValues['shipping']);
                $order->setBaseShippingAmount($frontendValues['shipping']);
                $this->logger->info('Set shipping to: ' . $frontendValues['shipping']);
            }

            // Force discount
            if (isset($frontendValues['discount']) && $frontendValues['discount'] > 0) {
                $discountAmount = -$frontendValues['discount']; // Negative for discount
                $order->setDiscountAmount($discountAmount);
                $order->setBaseDiscountAmount($discountAmount);
                $this->logger->info('Set discount to: ' . $discountAmount);
            }

            // Force grand total - MOST IMPORTANT
            if (isset($frontendValues['grand_total'])) {
                $order->setGrandTotal($frontendValues['grand_total']);
                $order->setBaseGrandTotal($frontendValues['grand_total']);
                $order->setTotalDue($frontendValues['grand_total']);
                $this->logger->info('Set grand total to: ' . $frontendValues['grand_total']);
            }

            // Save multiple times to ensure persistence
            $this->logger->info('Saving order with forced values...');
            $this->orderRepository->save($order);
            $order->getResource()->save($order);
            
            // Reload order to verify values were saved
            $reloadedOrder = $this->orderRepository->get($order->getId());

            $this->logger->info('=== ORDER FORCING COMPLETED ===', [
                'after_forcing_and_save' => [
                    'subtotal' => $reloadedOrder->getSubtotal(),
                    'shipping' => $reloadedOrder->getShippingAmount(),
                    'discount' => $reloadedOrder->getDiscountAmount(),
                    'grand_total' => $reloadedOrder->getGrandTotal()
                ],
                'values_match_frontend' => [
                    'subtotal_match' => $reloadedOrder->getSubtotal() == $frontendValues['subtotal'],
                    'shipping_match' => $reloadedOrder->getShippingAmount() == $frontendValues['shipping'],
                    'grand_total_match' => $reloadedOrder->getGrandTotal() == $frontendValues['grand_total']
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('CRITICAL: Failed to force frontend values on order: ' . $e->getMessage(), [
                'exception' => $e->getTraceAsString()
            ]);
        }
    }

        /**
     * Ensure order reflects quote rules properly - Magento 2.4.7 Standard Method
     */
    private function ensureOrderReflectsQuoteRules($order, $quote): void
    {
        try {
            $this->logger->info('Ensuring order reflects quote rules using Magento 2.4.7 standards', [
                'order_id' => $order->getId(),
                'quote_applied_rules' => $quote->getAppliedRuleIds(),
                'quote_coupon' => $quote->getCouponCode(),
                'quote_discount' => $quote->getShippingAddress()->getDiscountAmount(),
                'quote_subtotal' => $quote->getSubtotal(),
                'quote_subtotal_with_discount' => $quote->getSubtotalWithDiscount(),
                'quote_grand_total' => $quote->getGrandTotal()
            ]);

            // 1. Transfer applied rule IDs (critical for admin panel display)
            if ($quote->getAppliedRuleIds()) {
                $order->setAppliedRuleIds($quote->getAppliedRuleIds());
                $order->setData('applied_rule_ids', $quote->getAppliedRuleIds());
            }
            
            // 2. Transfer coupon code
            if ($quote->getCouponCode()) {
                $order->setCouponCode($quote->getCouponCode());
            }
            
            // 3. Ensure discount amount is correctly transferred
            $quoteDiscountAmount = (float)$quote->getShippingAddress()->getDiscountAmount();
            if ($quoteDiscountAmount != 0) {
                $order->setDiscountAmount($quoteDiscountAmount);
                $order->setBaseDiscountAmount($quoteDiscountAmount);
            }
            
            // 4. Transfer discount description (shows rule names in admin)
            $discountDescription = $quote->getShippingAddress()->getDiscountDescription();
            if ($discountDescription) {
                $order->setDiscountDescription($discountDescription);
            }
            
            // 5. Ensure subtotal with discount is transferred correctly
            $subtotalWithDiscount = (float)$quote->getSubtotalWithDiscount();
            if ($subtotalWithDiscount > 0) {
                $order->setSubtotalWithDiscount($subtotalWithDiscount);
            }
            
            // 6. Ensure all order items reflect catalog price rule discounts
            $this->transferCatalogRulesToOrderItems($order, $quote);
            
            // 7. Save order with all rule information
            $this->orderRepository->save($order);
            
            // 8. Reload and verify the order has the correct values
            $reloadedOrder = $this->orderRepository->get($order->getId());
            
            $this->logger->info('Order successfully updated with quote rule information', [
                'order_applied_rules' => $reloadedOrder->getAppliedRuleIds(),
                'order_coupon' => $reloadedOrder->getCouponCode(),
                'order_discount_amount' => $reloadedOrder->getDiscountAmount(),
                'order_discount_description' => $reloadedOrder->getDiscountDescription(),
                'order_subtotal' => $reloadedOrder->getSubtotal(),
                'order_subtotal_with_discount' => $reloadedOrder->getSubtotalWithDiscount(),
                'order_grand_total' => $reloadedOrder->getGrandTotal(),
                'verification_success' => true
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to ensure order reflects quote rules: ' . $e->getMessage(), [
                'exception' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Transfer catalog price rules to order items - ensures item-level discounts are preserved
     */
    private function transferCatalogRulesToOrderItems($order, $quote): void
    {
        try {
            $this->logger->info('Transferring catalog rules to order items');
            
            $quoteItems = $quote->getAllVisibleItems();
            $orderItems = $order->getAllVisibleItems();
            
            // Match quote items with order items and transfer catalog rule prices
            foreach ($quoteItems as $quoteItem) {
                foreach ($orderItems as $orderItem) {
                    // Match by product ID and SKU
                    if ($quoteItem->getProductId() == $orderItem->getProductId() && 
                        $quoteItem->getSku() == $orderItem->getSku()) {
                        
                        // Transfer catalog rule price if applied
                        $quotePrice = (float)$quoteItem->getPrice();
                        $originalPrice = (float)$quoteItem->getProduct()->getPrice();
                        
                        if ($quotePrice < $originalPrice && $quotePrice > 0) {
                            // Catalog rule was applied - transfer to order item
                            $orderItem->setPrice($quotePrice);
                            $orderItem->setBasePrice($quotePrice);
                            $orderItem->setOriginalPrice($originalPrice);
                            
                            // Recalculate row total for order item
                            $qty = (int)$orderItem->getQtyOrdered();
                            $orderItem->setRowTotal($quotePrice * $qty);
                            $orderItem->setBaseRowTotal($quotePrice * $qty);
                            
                            $this->logger->info('Catalog rule price transferred to order item', [
                                'product_id' => $orderItem->getProductId(),
                                'original_price' => $originalPrice,
                                'catalog_rule_price' => $quotePrice,
                                'qty' => $qty,
                                'new_row_total' => $orderItem->getRowTotal()
                            ]);
                        }
                        
                        break; // Found matching item, move to next quote item
                    }
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to transfer catalog rules to order items: ' . $e->getMessage());
        }
    }

    /**
     * Adjust order totals while preserving all price rule information
     */
    private function adjustOrderTotalsPreservingRules($order, array $frontendValues): void
    {
        try {
            $this->logger->info('Adjusting order totals while preserving price rules', [
                'order_id' => $order->getId(),
                'frontend_values' => $frontendValues,
                'current_applied_rules' => $order->getAppliedRuleIds(),
                'current_coupon' => $order->getCouponCode(),
                'current_discount_description' => $order->getDiscountDescription()
            ]);

            // Store rule information before any changes
            $preservedRuleInfo = [
                'applied_rule_ids' => $order->getAppliedRuleIds(),
                'coupon_code' => $order->getCouponCode(),
                'discount_description' => $order->getDiscountDescription()
            ];

            // Adjust totals only if they differ significantly
            $tolerance = 0.01; // Allow small differences due to rounding

            // Adjust subtotal if needed
            if (isset($frontendValues['subtotal'])) {
                $currentSubtotal = (float)$order->getSubtotal();
                $frontendSubtotal = (float)$frontendValues['subtotal'];
                
                if (abs($currentSubtotal - $frontendSubtotal) > $tolerance) {
                    $order->setSubtotal($frontendSubtotal);
                    $order->setBaseSubtotal($frontendSubtotal);
                    $this->logger->info('Adjusted subtotal', [
                        'from' => $currentSubtotal,
                        'to' => $frontendSubtotal
                    ]);
                }
            }

            // Adjust shipping if needed
            if (isset($frontendValues['shipping'])) {
                $currentShipping = (float)$order->getShippingAmount();
                $frontendShipping = (float)$frontendValues['shipping'];
                
                if (abs($currentShipping - $frontendShipping) > $tolerance) {
                    $order->setShippingAmount($frontendShipping);
                    $order->setBaseShippingAmount($frontendShipping);
                    $this->logger->info('Adjusted shipping', [
                        'from' => $currentShipping,
                        'to' => $frontendShipping
                    ]);
                }
            }

            // Adjust discount if needed (preserve sign and meaning)
            if (isset($frontendValues['discount'])) {
                $frontendDiscount = (float)$frontendValues['discount'];
                $currentDiscount = (float)$order->getDiscountAmount();
                
                // Frontend sends positive discount, but Magento stores as negative
                $expectedDiscountAmount = $frontendDiscount > 0 ? -$frontendDiscount : $frontendDiscount;
                
                if (abs($currentDiscount - $expectedDiscountAmount) > $tolerance) {
                    $order->setDiscountAmount($expectedDiscountAmount);
                    $order->setBaseDiscountAmount($expectedDiscountAmount);
                    $this->logger->info('Adjusted discount', [
                        'frontend_discount' => $frontendDiscount,
                        'magento_discount_amount' => $expectedDiscountAmount,
                        'previous_discount' => $currentDiscount
                    ]);
                }
            }

            // Adjust grand total if needed
            if (isset($frontendValues['grand_total'])) {
                $currentGrandTotal = (float)$order->getGrandTotal();
                $frontendGrandTotal = (float)$frontendValues['grand_total'];
                
                if (abs($currentGrandTotal - $frontendGrandTotal) > $tolerance) {
                    $order->setGrandTotal($frontendGrandTotal);
                    $order->setBaseGrandTotal($frontendGrandTotal);
                    $this->logger->info('Adjusted grand total', [
                        'from' => $currentGrandTotal,
                        'to' => $frontendGrandTotal
                    ]);
                }
            }

            // CRITICAL: Restore all rule information after adjustments
            if ($preservedRuleInfo['applied_rule_ids']) {
                $order->setAppliedRuleIds($preservedRuleInfo['applied_rule_ids']);
                $order->setData('applied_rule_ids', $preservedRuleInfo['applied_rule_ids']);
            }
            
            if ($preservedRuleInfo['coupon_code']) {
                $order->setCouponCode($preservedRuleInfo['coupon_code']);
            }
            
            if ($preservedRuleInfo['discount_description']) {
                $order->setDiscountDescription($preservedRuleInfo['discount_description']);
            }

            // Save order with preserved rule information
            $this->orderRepository->save($order);

            $this->logger->info('Order totals adjusted while preserving all price rule information', [
                'final_applied_rules' => $order->getAppliedRuleIds(),
                'final_coupon' => $order->getCouponCode(),
                'final_discount_description' => $order->getDiscountDescription(),
                'final_subtotal' => $order->getSubtotal(),
                'final_shipping' => $order->getShippingAmount(),
                'final_discount' => $order->getDiscountAmount(),
                'final_grand_total' => $order->getGrandTotal()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to adjust order totals while preserving rules: ' . $e->getMessage());
        }
    }

    

    
    /**
     * Prevent Magento from re-collecting totals during order placement by
     * locking the quote addresses and totals to the already computed values.
     */
    private function freezeQuoteTotalsForOrderPlacement($quote): void
    {
        try {
            // Lock totals flags to prevent any recalculation during order conversion
            $quote->setTotalsCollectedFlag(true);
            $quote->setData('trigger_recollect', false);
            $quote->setData('totals_collected_flag', true);

            // Freeze shipping values on shipping address
            $shippingAddress = $quote->getShippingAddress();
            if ($shippingAddress) {
                $shippingAmount = (float)$shippingAddress->getShippingAmount();
                $baseShippingAmount = (float)$shippingAddress->getBaseShippingAmount();
                
                // Cache current shipping values
                $shippingAddress->setData('cached_shipping_amount', $shippingAmount);
                $shippingAddress->setData('cached_base_shipping_amount', $baseShippingAmount);
                
                // Lock shipping calculation flags
                $shippingAddress->setCollectShippingRates(false);
                $shippingAddress->setData('cached_items_all', $shippingAddress->getAllItems());
                
                // Ensure free shipping flag is preserved if was applied
                if ($shippingAddress->getFreeShipping()) {
                    $shippingAddress->setFreeShipping(true);
                }
                
                $this->logger->info('Quote totals frozen before order placement', [
                    'quote_id' => $quote->getId(),
                    'subtotal' => $quote->getSubtotal(),
                    'shipping' => $shippingAmount,
                    'discount' => $quote->getSubtotal() - $quote->getSubtotalWithDiscount(),
                    'grand_total' => $quote->getGrandTotal(),
                    'totals_collected_flag' => $quote->getTotalsCollectedFlag()
                ]);
            }

            // Persist frozen state
            $this->cartRepository->save($quote);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to freeze quote totals: ' . $e->getMessage());
        }
    }

    /**
     * Build concise order summary for success response (matches admin totals)
     */
    private function buildOrderSummary($order): array
    {
        try {
            // Get values directly from Order object - the single source of truth
            $shippingAmount = (float)$order->getShippingAmount();
            $subtotal = (float)$order->getSubtotal();
            $discount = (float)$order->getDiscountAmount();
            $grandTotal = (float)$order->getGrandTotal();
            
            $this->logger->info('Building order summary from Order object', [
                'order_id' => $order->getId(),
                'increment_id' => $order->getIncrementId(),
                'subtotal' => $subtotal,
                'shipping' => $shippingAmount,
                'discount' => $discount,
                'grand_total' => $grandTotal
            ]);
            
            return [
                'subtotal' => $this->formatPrice($subtotal),
                'shipping' => $this->formatPrice($shippingAmount),
                'discount' => $discount ? '-' . $this->formatPrice(abs($discount)) : $this->formatPrice(0),
                'total' => $this->formatPrice($grandTotal)
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to build order summary: ' . $e->getMessage());
            return [];
        }
    }
    /**
     * Fallback calculation method
     */
    private function getFallbackCalculation(
        int $productId,
        int $qty,
        string $shippingMethod,
        string $countryId,
        ?string $region = null,
        ?string $postcode = null
    ): array {
        $product = $this->productRepository->getById($productId);
        $productPrice = (float)$product->getFinalPrice(); // سعر الوحدة الواحدة
        $subtotal = $productPrice * $qty;
        $shippingCost = $this->calculateShippingCost($productId, $shippingMethod, $countryId, $region, $postcode, $qty);
        return [
            'product_price' => $productPrice, // سعر الوحدة الواحدة
            'original_price' => $productPrice,
            'subtotal' => $subtotal,
            'subtotal_incl_tax' => $subtotal,
            'shipping_cost' => $shippingCost,
            'discount_amount' => 0,
            'total' => $subtotal + $shippingCost,
            'applied_rule_ids' => '',
            'has_discount' => false
        ];
    }
    /**
     * تطبيق كود الكوبون على الـ quote
     */
    public function applyCouponCode($quote, string $couponCode): array
    {
        try {
            $this->logger->info('تطبيق كود الكوبون', [
                'quote_id' => $quote->getId(),
                'coupon_code' => $couponCode
            ]);
            // تحقق من صلاحية الكوبون وفق وثائق ماجنتو (نشط/النطاق الزمني/المواقع)
            try {
                /** @var \Magento\Framework\App\ObjectManager $om */
                $om = \Magento\Framework\App\ObjectManager::getInstance();
                /** @var \Magento\SalesRule\Model\CouponFactory $couponFactory */
                $couponFactory = $om->get(\Magento\SalesRule\Model\CouponFactory::class);
                $couponModel = $couponFactory->create()->loadByCode($couponCode);
                if (!$couponModel->getId() || !(int)$couponModel->getRuleId()) {
                    return [
                        'success' => false,
                        'message' => __('Invalid coupon code'),
                        'coupon_code' => $couponCode
                    ];
                }
                /** @var \Magento\SalesRule\Api\RuleRepositoryInterface $ruleRepo */
                $ruleRepo = $om->get(\Magento\SalesRule\Api\RuleRepositoryInterface::class);
                $rule = $ruleRepo->getById((int)$couponModel->getRuleId());
                if (!$rule->getIsActive()) {
                    return [
                        'success' => false,
                        'message' => __('Coupon is not active'),
                        'coupon_code' => $couponCode
                    ];
                }
                $today = new \DateTimeImmutable('now');
                $from = $rule->getFromDate() ? new \DateTimeImmutable($rule->getFromDate()) : null;
                $to = $rule->getToDate() ? new \DateTimeImmutable($rule->getToDate()) : null;
                if (($from && $today < $from) || ($to && $today > $to)) {
                    return [
                        'success' => false,
                        'message' => __('Coupon is not within active date range'),
                        'coupon_code' => $couponCode
                    ];
                }
                // Check website scope
                $websiteId = (int)$quote->getStore()->getWebsiteId();
                $ruleWebsiteIds = (array)$rule->getWebsiteIds();
                if (!in_array($websiteId, $ruleWebsiteIds)) {
                    return [
                        'success' => false,
                        'message' => __('Coupon is not valid for this website'),
                        'coupon_code' => $couponCode
                    ];
                }
            } catch (\Throwable $t) {
                // If validation lookup fails, continue to standard Magento application which will reject ineffective codes via totals
                $this->logger->warning('Coupon pre-validation failed', ['error' => $t->getMessage()]);
            }

            // تطبيق كود الكوبون وفقاً لممارسات ماجنتو
            $quote->setCouponCode($couponCode);
            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();
            $this->cartRepository->save($quote);

            $appliedCoupon = (string)$quote->getCouponCode();
            if ($appliedCoupon === $couponCode) {
                // احسب قيمة الخصم من المجاميع الإجمالية (يشمل خصومات السلة/الكتالوج)
                $subtotal = (float)$quote->getSubtotal();
                $subtotalWithDiscount = (float)$quote->getSubtotalWithDiscount();
                $itemsDiscount = max(0.0, $subtotal - $subtotalWithDiscount);
                $shippingDiscount = abs((float)$quote->getShippingAddress()->getDiscountAmount());
                $discountAmount = max($itemsDiscount, $shippingDiscount);

                if ($discountAmount > 0.0001) {
                    return [
                        'success' => true,
                        'message' => __('Coupon code applied successfully'),
                        'discount_amount' => $discountAmount,
                        'coupon_code' => $appliedCoupon,
                        'applied_rule_ids' => $quote->getAppliedRuleIds()
                    ];
                }
            }

            return [
                'success' => false,
                'message' => __('Invalid coupon code or no discount applied'),
                'coupon_code' => $couponCode
            ];
        } catch (\Exception $e) {
            $this->logger->error('خطأ في تطبيق كود الكوبون: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => __('Error applying coupon code: %1', $e->getMessage())
            ];
        }
    }
    /**
     * إزالة كود الكوبون
     */
    public function removeCouponCode($quote): array
    {
        try {
            $quote->setCouponCode('');
            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();
            $this->cartRepository->save($quote);
            return [
                'success' => true,
                'message' => __('Coupon code removed successfully')
            ];
        } catch (\Exception $e) {
            $this->logger->error('خطأ في إزالة كود الكوبون: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => __('Error removing coupon code')
            ];
        }
    }
	/**
 * Update quantity and recalculate shipping methods and costs
 * يامان لازم تفهم ان الكمية وارد تزيد وتقل حسب ما العميل عايز بعد تحديد طريقة الشحن
 * يعني مفروض اي زيادة او نقص طرق الشحن تتحدث واتكلفة الشحن ايضا
 */
public function updateQuantityAndRecalculateShipping(
    int $quoteId,
    int $newQty,
    string $shippingMethod,
    string $countryId,
    ?string $region = null,
    ?string $postcode = null
): array {
    try {
        $this->logger->info('تحديث الكمية وإعادة حساب الشحن', [
            'quote_id' => $quoteId,
            'new_qty' => $newQty,
            'shipping_method' => $shippingMethod
        ]);
        // تحميل الـ quote
        $quote = $this->cartRepository->get($quoteId);
        // تحديث كمية المنتج
        $items = $quote->getAllVisibleItems();
        if (empty($items)) {
            throw new \Exception('No items found in quote');
        }
        $item = $items[0];
        $oldQty = $item->getQty();
        // تحديث الكمية
        $item->setQty($newQty);
        $this->logger->info('تم تحديث الكمية', [
            'item_id' => $item->getId(),
            'old_qty' => $oldQty,
            'new_qty' => $newQty,
            'product_sku' => $item->getSku()
        ]);
        // إعادة حساب الأسعار والقواعد
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->cartRepository->save($quote);
        // إعادة تطبيق قواعد الكتالوج والسلة
        $this->applyCatalogRules($quote);
        $this->applyCartRules($quote);
        $this->cartRepository->save($quote);
        // إعادة حساب طرق الشحن بناءً على الكمية والسعر الجديد
        $this->recalculateShippingAfterQuantityChange($quote, $shippingMethod, $countryId, $region, $postcode);
        // حساب نهائي
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->cartRepository->save($quote);
        // إرجاع النتائج المحدثة
        $updatedItem = $quote->getAllVisibleItems()[0];
        $shippingAddress = $quote->getShippingAddress();
        // FIXED: حساب سعر الوحدة الصحيح
        $qty = (int)$updatedItem->getQty();
        $unitPrice = $qty > 0 ? (float)$updatedItem->getRowTotal() / $qty : (float)$updatedItem->getPrice();
        return [
            'success' => true,
            'product_price' => $unitPrice, // سعر الوحدة الواحدة
            'subtotal' => (float)$updatedItem->getRowTotal(),
            'shipping_cost' => (float)$shippingAddress->getShippingAmount(),
            'discount_amount' => abs((float)$updatedItem->getDiscountAmount()),
            'total' => (float)$quote->getGrandTotal(),
            'qty' => $qty,
            'applied_rule_ids' => $quote->getAppliedRuleIds(),
            'shipping_method' => $shippingAddress->getShippingMethod(),
            'available_shipping_methods' => $this->getUpdatedShippingMethods($quote)
        ];
    } catch (\Exception $e) {
        $this->logger->error('خطأ في تحديث الكمية وإعادة حساب الشحن: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}
/**
 * إعادة حساب طرق الشحن بعد تغيير الكمية
 */
private function recalculateShippingAfterQuantityChange(
    $quote, 
    string $requestedShippingMethod, 
    string $countryId, 
    ?string $region = null, 
    ?string $postcode = null
): void {
    $this->logger->info('إعادة حساب طرق الشحن بعد تغيير الكمية', [
        'quote_id' => $quote->getId(),
        'new_subtotal' => $quote->getSubtotal(),
        'requested_shipping_method' => $requestedShippingMethod
    ]);
    $shippingAddress = $quote->getShippingAddress();
    // تحديث عنوان الشحن إذا لزم الأمر
    if ($region || $postcode) {
        $this->updateShippingAddress($shippingAddress, $countryId, $region, $postcode);
    }
    // إزالة جميع طرق الشحن الحالية
    $shippingAddress->removeAllShippingRates();
    $shippingAddress->setCollectShippingRates(true);
    // إعادة جمع طرق الشحن بناءً على الكمية والسعر الجديد
    $shippingAddress->collectShippingRates();
    // البحث عن طريقة الشحن المطلوبة
    $availableRates = $shippingAddress->getAllShippingRates();
    $methodFound = false;
    $this->logger->info('طرق الشحن المتاحة بعد تغيير الكمية', [
        'available_methods_count' => count($availableRates),
        'methods' => array_map(function($rate) {
            return [
                'code' => $rate->getCode(),
                'title' => $rate->getMethodTitle(),
                'price' => $rate->getPrice(),
                'carrier' => $rate->getCarrierTitle()
            ];
        }, $availableRates)
    ]);
    // محاولة تطبيق طريقة الشحن المطلوبة
    foreach ($availableRates as $rate) {
        if ($rate->getCode() === $requestedShippingMethod) {
            $shippingAddress->setShippingMethod($requestedShippingMethod);
            $methodFound = true;
            $this->logger->info('تم تطبيق طريقة الشحن المطلوبة بعد تغيير الكمية', [
                'shipping_method' => $requestedShippingMethod,
                'new_shipping_cost' => $rate->getPrice()
            ]);
            break;
        }
    }
    // إذا لم تعد طريقة الشحن متاحة، استخدم أول طريقة متاحة
    if (!$methodFound && !empty($availableRates)) {
        $firstRate = reset($availableRates);
        $shippingAddress->setShippingMethod($firstRate->getCode());
        $this->logger->warning('طريقة الشحن المطلوبة غير متاحة بعد تغيير الكمية، تم استخدام البديل', [
            'requested_method' => $requestedShippingMethod,
            'fallback_method' => $firstRate->getCode(),
            'fallback_cost' => $firstRate->getPrice()
        ]);
    }
}
/**
 * تحديث عنوان الشحن
 */
private function updateShippingAddress($shippingAddress, string $countryId, ?string $region = null, ?string $postcode = null): void
{
    if ($region) {
        $regionId = $this->getRegionIdByName($region, $countryId);
        if ($regionId) {
            $shippingAddress->setRegionId($regionId);
        }
        $shippingAddress->setRegion($region);
    }
    if ($postcode) {
        $shippingAddress->setPostcode($postcode);
    }
    $shippingAddress->setCountryId($countryId);
}
/**
 * الحصول على طرق الشحن المحدثة
 */
private function getUpdatedShippingMethods($quote): array
{
    $shippingAddress = $quote->getShippingAddress();
    $availableRates = $shippingAddress->getAllShippingRates();
    $methods = [];
    foreach ($availableRates as $rate) {
        $methods[] = [
            'code' => $rate->getCode(),
            'title' => $rate->getMethodTitle(),
            'carrier_title' => $rate->getCarrierTitle(),
            'price' => (float)$rate->getPrice(),
            'price_formatted' => $this->formatPrice($rate->getPrice())
        ];
    }
    return $methods;
}
/**
 * Get detailed product information for success message
 */
private function getOrderProductDetails($order): array
{
    $productDetails = [];
    foreach ($order->getAllItems() as $item) {
        $product = $item->getProduct();
        // FIXED: حساب السعر الصحيح
        $unitPrice = (float)$item->getPrice(); // سعر الوحدة الواحدة
        $totalPrice = (float)$item->getRowTotal(); // السعر الإجمالي للكمية
        $qty = (int)$item->getQtyOrdered();
        // التحقق من صحة الحسابات
        $calculatedTotal = $unitPrice * $qty;
        if (abs($calculatedTotal - $totalPrice) > 0.01) {
            $this->logger->warning('Price calculation mismatch detected', [
                'item_id' => $item->getId(),
                'unit_price' => $unitPrice,
                'qty' => $qty,
                'calculated_total' => $calculatedTotal,
                'actual_total' => $totalPrice
            ]);
        }
        $details = [
            'name' => $item->getName(),
            'sku' => $item->getSku(),
            'qty' => $qty,
            'price' => $this->formatPrice($unitPrice), // سعر الوحدة
            'row_total' => $this->formatPrice($totalPrice), // السعر الإجمالي
            'product_type' => $product->getTypeId()
        ];
        // إضافة تفاصيل المنتج المتعدد (Configurable Product)
        if ($product->getTypeId() === 'configurable') {
            $productOptions = $item->getProductOptions();
            if (isset($productOptions['attributes_info']) && is_array($productOptions['attributes_info'])) {
                $details['attributes'] = [];
                foreach ($productOptions['attributes_info'] as $attribute) {
                    $details['attributes'][] = [
                        'label' => $attribute['label'],
                        'value' => $attribute['value']
                    ];
                }
            }
        }
        // إضافة خيارات المنتج المخصصة
        if ($item->getProductOptions()) {
            $productOptions = $item->getProductOptions();
            if (isset($productOptions['options']) && is_array($productOptions['options'])) {
                $details['custom_options'] = [];
                foreach ($productOptions['options'] as $option) {
                    $details['custom_options'][] = [
                        'label' => $option['label'],
                        'value' => $option['value']
                    ];
                }
            }
        }
        $productDetails[] = $details;
    }
    return $productDetails;
}

    /**
     * Validate stock availability and product conditions before order placement
     * Critical validation to prevent overselling and invalid orders
     */
    private function validateStockAvailability($quote, $orderData): array
    {
        try {
            $this->logger->info('=== CRITICAL: Stock and availability validation ===', [
                'quote_id' => $quote->getId(),
                'requested_qty' => is_object($orderData) ? $orderData->getQty() : 'not_object',
                'product_id' => is_object($orderData) ? $orderData->getProductId() : 'not_object',
                'orderData_type' => is_object($orderData) ? get_class($orderData) : gettype($orderData)
            ]);

            // 1. Validate all quote items for stock
            foreach ($quote->getAllVisibleItems() as $item) {
                $product = $item->getProduct();
                $requestedQty = (int)$item->getQty();
                
                $this->logger->info('Validating item stock', [
                    'product_id' => $product->getId(),
                    'sku' => $product->getSku(),
                    'requested_qty' => $requestedQty,
                    'product_type' => $product->getTypeId()
                ]);

                // Determine if this is a child product by checking the original orderData
                $isChildProduct = false;
                $originalProductId = is_object($orderData) ? $orderData->getProductId() : (isset($orderData['product_id']) ? (int)$orderData['product_id'] : null);
                
                $this->logger->info('Child product detection details', [
                    'original_product_id' => $originalProductId,
                    'current_product_id' => $product->getId(),
                    'orderData_is_object' => is_object($orderData),
                    'comparison_result' => ($originalProductId && $product->getId() != $originalProductId)
                ]);
                
                // If the current product ID is different from the original product ID, 
                // it means this is a simple product selected from a configurable
                if ($originalProductId && $product->getId() != $originalProductId) {
                    $isChildProduct = true;
                    $this->logger->info('✓ CONFIRMED: Child product detected', [
                        'original_product_id' => $originalProductId,
                        'current_product_id' => $product->getId(),
                        'is_child_product' => true
                    ]);
                } else {
                    $this->logger->info('✗ MAIN PRODUCT: Not a child product', [
                        'original_product_id' => $originalProductId,
                        'current_product_id' => $product->getId(),
                        'is_child_product' => false,
                        'reason' => !$originalProductId ? 'no_original_id' : 'same_id'
                    ]);
                }

                // Stock validation - handle all products as simple with proper child detection
                if ($product->getTypeId() === 'configurable') {
                    // This shouldn't happen in quote items, but handle it anyway
                    $stockValidation = $this->validateConfigurableProductStock($item, $requestedQty);
                } else {
                    // For simple products, use proper child product detection
                    $stockValidation = $this->validateSimpleProductStock($product, $requestedQty, $isChildProduct);
                }

                if (!$stockValidation['valid']) {
                    return $stockValidation;
                }
            }

            // 2. Final quote-level validation
            $quoteValidation = $this->validateQuoteConditions($quote);
            if (!$quoteValidation['valid']) {
                return $quoteValidation;
            }

            $this->logger->info('Stock validation passed successfully', [
                'quote_id' => $quote->getId(),
                'all_items_available' => true
            ]);

            return [
                'valid' => true,
                'message' => 'Stock validation passed'
            ];

        } catch (\Exception $e) {
            $this->logger->error('Stock validation error: ' . $e->getMessage());
            return [
                'valid' => false,
                'message' => 'خطأ في التحقق من المخزون. يرجى المحاولة مرة أخرى.'
            ];
        }
    }

    /**
     * Validate basic product conditions (enabled, visible, etc.)
     */
    private function validateProductBasics($product): array
    {
        $this->logger->info('Product basics validation', [
            'product_id' => $product->getId(),
            'sku' => $product->getSku(),
            'status' => $product->getStatus(),
            'visibility' => $product->getVisibility()
        ]);

        // Check if product is enabled
        if (!$product->getStatus() || $product->getStatus() != \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED) {
            $this->logger->error('Product is not enabled', [
                'product_id' => $product->getId(),
                'status' => $product->getStatus()
            ]);
            return [
                'valid' => false,
                'message' => 'المنتج غير متاح حالياً. يرجى اختيار منتج آخر.'
            ];
        }

        // Check if product is visible
        $visibility = $product->getVisibility();
        if (!in_array($visibility, [
            \Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH,
            \Magento\Catalog\Model\Product\Visibility::VISIBILITY_IN_CATALOG,
            \Magento\Catalog\Model\Product\Visibility::VISIBILITY_IN_SEARCH
        ])) {
            $this->logger->error('Product visibility check failed', [
                'product_id' => $product->getId(),
                'visibility' => $visibility,
                'expected_visibility' => [
                    \Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH,
                    \Magento\Catalog\Model\Product\Visibility::VISIBILITY_IN_CATALOG,
                    \Magento\Catalog\Model\Product\Visibility::VISIBILITY_IN_SEARCH
                ]
            ]);
            return [
                'valid' => false,
                'message' => 'المنتج غير متاح للطلب.'
            ];
        }

        return [
            'valid' => true,
            'message' => 'Product basics validated'
        ];
    }

    /**
     * Validate stock for simple/virtual products
     */
    private function validateSimpleProductStock($product, int $requestedQty, bool $isChildProduct = false): array
    {
        try {
            $this->logger->info('Simple product stock validation start', [
                'product_id' => $product->getId(),
                'sku' => $product->getSku(),
                'is_child_product' => $isChildProduct,
                'product_status' => $product->getStatus(),
                'requested_qty' => $requestedQty
            ]);

            // Basic product validation (but skip visibility for child products)
            if (!$isChildProduct) {
                $basicValidation = $this->validateProductBasics($product);
                if (!$basicValidation['valid']) {
                    $this->logger->error('Product basics validation failed for main product', [
                        'product_id' => $product->getId(),
                        'validation_result' => $basicValidation
                    ]);
                    return $basicValidation;
                }
            } else {
                // For child products, only check if enabled (skip visibility check)
                if (!$product->getStatus() || $product->getStatus() != \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED) {
                    $this->logger->error('Child product is not enabled', [
                        'product_id' => $product->getId(),
                        'status' => $product->getStatus()
                    ]);
                    return [
                        'valid' => false,
                        'message' => 'المنتج المحدد غير متاح حالياً.'
                    ];
                }
                $this->logger->info('Child product basic validation passed', [
                    'product_id' => $product->getId(),
                    'status' => $product->getStatus()
                ]);
            }

            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $stockRegistry = $objectManager->get(\Magento\CatalogInventory\Api\StockRegistryInterface::class);
            
            $stockItem = $stockRegistry->getStockItem($product->getId());
            
            $this->logger->info('Simple product stock check', [
                'product_id' => $product->getId(),
                'sku' => $product->getSku(),
                'is_child_product' => $isChildProduct,
                'is_in_stock' => $stockItem->getIsInStock(),
                'qty_available' => $stockItem->getQty(),
                'requested_qty' => $requestedQty,
                'manage_stock' => $stockItem->getManageStock(),
                'backorders' => $stockItem->getBackorders(),
                'product_enabled' => $product->getStatus() == \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED
            ]);

            // Check if stock management is enabled
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
            if ($availableQty < $requestedQty) {
                // Check if backorders are allowed
                if ($stockItem->getBackorders() == \Magento\CatalogInventory\Model\Stock::BACKORDERS_NO) {
                    return [
                        'valid' => false,
                        'message' => sprintf(
                            'الكمية المطلوبة (%d) غير متوفرة. الكمية المتاحة: %d',
                            $requestedQty,
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
            $this->logger->error('Error validating simple product stock: ' . $e->getMessage());
            return [
                'valid' => false,
                'message' => 'خطأ في التحقق من مخزون المنتج.'
            ];
        }
    }

    /**
     * Validate stock for configurable products
     */
    private function validateConfigurableProductStock($item, int $requestedQty): array
    {
        try {
            $product = $item->getProduct();
            
            // Get the selected simple product from configurable
            $productOption = $item->getOptionByCode('simple_product');
            if (!$productOption) {
                return [
                    'valid' => false,
                    'message' => 'لم يتم تحديد خيارات المنتج بشكل صحيح.'
                ];
            }

            $simpleProduct = $productOption->getProduct();
            if (!$simpleProduct) {
                return [
                    'valid' => false,
                    'message' => 'المنتج المحدد غير متاح.'
                ];
            }

            $this->logger->info('Configurable product stock check', [
                'configurable_id' => $product->getId(),
                'simple_id' => $simpleProduct->getId(),
                'simple_sku' => $simpleProduct->getSku(),
                'requested_qty' => $requestedQty
            ]);

            // Validate the simple product stock (mark as child product to skip visibility check)
            return $this->validateSimpleProductStock($simpleProduct, $requestedQty, true);

        } catch (\Exception $e) {
            $this->logger->error('Error validating configurable product stock: ' . $e->getMessage());
            return [
                'valid' => false,
                'message' => 'خطأ في التحقق من مخزون المنتج المحدد.'
            ];
        }
    }

    /**
     * Validate quote-level conditions
     */
    private function validateQuoteConditions($quote): array
    {
        try {
            // Check if quote has items
            if (!$quote->getItemsCount() || $quote->getItemsCount() <= 0) {
                return [
                    'valid' => false,
                    'message' => 'لا توجد منتجات في الطلب.'
                ];
            }

            // Check quote totals
            $grandTotal = (float)$quote->getGrandTotal();
            if ($grandTotal <= 0) {
                return [
                    'valid' => false,
                    'message' => 'إجمالي الطلب غير صحيح.'
                ];
            }

            // Check shipping method for physical products
            if (!$quote->isVirtual()) {
                $shippingMethod = $quote->getShippingAddress()->getShippingMethod();
                if (empty($shippingMethod)) {
                    return [
                        'valid' => false,
                        'message' => 'يجب تحديد طريقة الشحن.'
                    ];
                }
            }

            // Check payment method
            $payment = $quote->getPayment();
            if (!$payment || !$payment->getMethod()) {
                return [
                    'valid' => false,
                    'message' => 'يجب تحديد طريقة الدفع.'
                ];
            }

            return [
                'valid' => true,
                'message' => 'Quote validation passed'
            ];

        } catch (\Exception $e) {
            $this->logger->error('Quote validation error: ' . $e->getMessage());
            return [
                'valid' => false,
                'message' => 'خطأ في التحقق من بيانات الطلب.'
            ];
        }
    }
}