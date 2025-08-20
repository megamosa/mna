<?php
/**
 * MagoArab_EasYorder Quick Order Block - Properly Architected
 *
 * @category    MagoArab
 * @package     MagoArab_EasYorder
 * @author      MagoArab Development Team
 * @copyright   Copyright (c) 2025 MagoArab
 * @license     https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace MagoArab\EasYorder\Block\Product;

use MagoArab\EasYorder\Helper\Data as HelperData;
use MagoArab\EasYorder\Api\QuickOrderServiceInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Catalog\Model\Product;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Directory\Model\Config\Source\Country;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\Framework\Data\Form\FormKey;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\AddressRepositoryInterface;

/**
 * Class QuickOrder
 * 
 * Block for rendering quick order form with proper dependency management
 */
class QuickOrder extends Template
{
    /**
     * @var HelperData
     */
    private $helperData;

    /**
     * @var QuickOrderServiceInterface
     */
    private $quickOrderService;

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var PriceHelper
     */
    private $priceHelper;

    /**
     * @var Country
     */
    private $countrySource;

    /**
     * @var JsonHelper
     */
    private $jsonHelper;

    /**
     * @var FormKey
     */
    private $formKey;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var AddressRepositoryInterface
     */
    private $addressRepository;

    /**
     * Constructor
     */
    public function __construct(
        Context $context,
        HelperData $helperData,
        QuickOrderServiceInterface $quickOrderService,
        Registry $registry,
        StoreManagerInterface $storeManager,
        PriceHelper $priceHelper,
        Country $countrySource,
        JsonHelper $jsonHelper,
        FormKey $formKey,
        CustomerSession $customerSession,
        CustomerRepositoryInterface $customerRepository,
        AddressRepositoryInterface $addressRepository,
        array $data = []
    ) {
        $this->helperData = $helperData;
        $this->quickOrderService = $quickOrderService;
        $this->registry = $registry;
        $this->storeManager = $storeManager;
        $this->priceHelper = $priceHelper;
        $this->countrySource = $countrySource;
        $this->jsonHelper = $jsonHelper;
        $this->formKey = $formKey;
        $this->customerSession = $customerSession;
        $this->customerRepository = $customerRepository;
        $this->addressRepository = $addressRepository;
        parent::__construct($context, $data);
    }

    /**
     * Check if quick order form should be displayed
     *
     * @return bool
     */
    public function canShowQuickOrder(): bool
    {
        if (!$this->helperData->isEnabled()) {
            return false;
        }
        
        $product = $this->getCurrentProduct();
        if (!$product) {
            return false;
        }

        // Global display mode
        $displayMode = $this->helperData->getDisplayMode();
        if ($displayMode === 'disabled') {
            return false;
        }
        if ($displayMode === 'selected') {
            // Respect product-level toggle when mode is selected only
            $productEasyOrderEnabled = $product->getData('easyorder_enabled');
            if (!$productEasyOrderEnabled) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Check if region field should be hidden for this product
     */
    public function shouldHideRegionForProduct(): bool
    {
        $product = $this->getCurrentProduct();
        if (!$product) {
            return false;
        }
        
        return (bool)$product->getData('easyorder_hide_region');
    }

    /**
     * Check if region field should be hidden (global or product level)
     */
    public function shouldHideRegion(): bool
    {
        return $this->helperData->isRegionFieldHidden() || $this->shouldHideRegionForProduct();
    }

    /**
     * Get current product
     *
     * @return Product|null
     */
    public function getCurrentProduct(): ?Product
    {
        return $this->registry->registry('current_product');
    }

    /**
     * Get form title
     *
     * @return string
     */
    public function getFormTitle(): string
    {
        return $this->helperData->getFormTitle();
    }

    /**
     * Get available payment methods from service
     * This now properly respects enabled/disabled payment methods from admin
     *
     * @return array
     */
    public function getAvailablePaymentMethods(): array
    {
        try {
            // Use the service which implements proper Magento APIs
            $methods = $this->quickOrderService->getAvailablePaymentMethods();
            
            $this->_logger->info('EasYorder Block: Retrieved payment methods', [
                'count' => count($methods),
                'methods' => array_column($methods, 'code')
            ]);
            
            return $methods;
        } catch (\Exception $e) {
            $this->_logger->error('EasYorder Block: Error getting payment methods: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get countries for dropdown
     *
     * @return array
     */
    public function getCountries(): array
    {
        return $this->countrySource->toOptionArray();
    }

    /**
     * Get country options HTML for select dropdown
     *
     * @return string
     */
    /**
     * Get country options HTML using Magento's standard country source
     *
     * @return string
     */
    public function getCountryOptionsHtml(): string
    {
        $countries = $this->getCountries();
        $html = '';
        
        foreach ($countries as $country) {
            if (isset($country['value']) && $country['value']) {
                $selected = ($country['value'] === $this->helperData->getDefaultCountry()) ? ' selected="selected"' : '';
                $html .= sprintf(
                    '<option value="%s"%s>%s</option>',
                    $this->escapeHtmlAttr($country['value']),
                    $selected,
                    $this->escapeHtml($country['label'])
                );
            }
        }
        
        return $html;
    }

    /**
     * Get regions options HTML for select dropdown
     *
     * @param string $countryId
     * @return string
     */
    public function getRegionOptionsHtml(string $countryId = ''): string
    {
        if (empty($countryId)) {
            return '<option value="">' . __('Please select a region, state or province') . '</option>';
        }
        
        $regions = $this->getRegions($countryId);
        $html = '<option value="">' . __('Please select a region, state or province') . '</option>';
        
        foreach ($regions as $region) {
            $html .= '<option value="' . $this->escapeHtmlAttr($region['value']) . '">';
            $html .= $this->escapeHtml($region['label']);
            $html .= '</option>';
        }
        
        return $html;
    }

    /**
     * Get regions for specific country
     *
     * @param string $countryId
     * @return array
     */
    public function getRegions(string $countryId): array
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $regionCollection = $objectManager->create(\Magento\Directory\Model\ResourceModel\Region\Collection::class);
        $regionCollection->addCountryFilter($countryId)->load();
        
        $regions = [];
        foreach ($regionCollection as $region) {
            $regions[] = [
                'value' => $region->getId(),
                'label' => $region->getName()
            ];
        }
        
        return $regions;
    }

    /**
     * Check if country requires regions
     *
     * @param string $countryId
     * @return bool
     */
    public function countryHasRegions(string $countryId): bool
    {
        $regions = $this->getRegions($countryId);
        return !empty($regions);
    }

    /**
     * Check if postcode is required for country
     *
     * @param string $countryId
     * @return bool
     */
    public function isPostcodeRequired(string $countryId): bool
    {
        // Check helper configuration first
        if ($this->helperData->isPostcodeRequired()) {
            return true;
        }
        
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $directoryHelper = $objectManager->get(\Magento\Directory\Helper\Data::class);
        
        return $directoryHelper->isZipCodeOptional($countryId) === false;
    }

    /**
     * Check if email is required based on configuration
     *
     * @return bool
     */
    public function isEmailRequired(): bool
    {
        return $this->helperData->isEmailRequired();
    }

    /**
     * Check if region is required based on configuration
     *
     * @return bool
     */
    public function isRegionRequired(): bool
    {
        return $this->helperData->isRegionRequired();
    }

    /**
     * Check if city is required based on configuration
     *
     * @return bool
     */
    public function isCityRequired(): bool
    {
        return $this->helperData->isCityRequired();
    }

    /**
     * Check if second street line should be shown
     *
     * @return bool
     */
    public function showStreet2(): bool
    {
        return $this->helperData->showStreet2();
    }

    /**
     * Get helper data instance for template access
     *
     * @return HelperData
     */
    public function getHelperData(): HelperData
    {
        return $this->helperData;
    }

    /**
     * Get default country from Magento configuration
     *
     * @return string
     */
    public function getDefaultCountry(): string
    {
        return $this->_scopeConfig->getValue(
            'general/country/default',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        ) ?: 'EG';
    }
    
    /**
     * Get form action URL
     *
     * @return string
     */
    public function getFormActionUrl(): string
    {
        return $this->getUrl('easyorder/order/create');
    }

    /**
     * Get shipping methods URL
     *
     * @return string
     */
    public function getShippingMethodsUrl(): string
    {
        return $this->getUrl('easyorder/ajax/shipping');
    }

    /**
     * Get calculate total URL
     *
     * @return string
     */
    public function getCalculateTotalUrl(): string
    {
        return $this->getUrl('easyorder/ajax/calculate');
    }

    /**
     * Get regions URL
     *
     * @return string
     */
    public function getRegionsUrl(): string
    {
        return $this->getUrl('easyorder/ajax/regions');
    }

    /**
     * Format price
     *
     * @param float $price
     * @return string
     */
    public function formatPrice(float $price): string
    {
        return $this->priceHelper->currency($price, true, false);
    }

    /**
     * Get current product price
     *
     * @return float
     */
    public function getCurrentProductPrice(): float
    {
        $product = $this->getCurrentProduct();
        if (!$product) {
            return 0.0;
        }

        return (float)$product->getFinalPrice();
    }

    /**
     * Get current product formatted price
     *
     * @return string
     */
    public function getCurrentProductFormattedPrice(): string
    {
        return $this->formatPrice($this->getCurrentProductPrice());
    }

    /**
     * Get JSON configuration for JavaScript
     * Enhanced with proper configuration support
     *
     * @return string
     */
    public function getJsonConfig(): string
    {
        $product = $this->getCurrentProduct();
        if (!$product) {
            return '{}';
        }

        $config = [
            'productId' => $product->getId(),
            'productPrice' => $this->getCurrentProductPrice(),
            'formattedPrice' => $this->getCurrentProductFormattedPrice(),
            'defaultCountry' => $this->getDefaultCountry(),
            'defaultPaymentMethod' => $this->getDefaultPaymentMethod(),
            // إضافة معلومات العملة
            'currency' => [
                'code' => $this->getCurrentCurrencyCode(),
                'symbol' => $this->getCurrentCurrencySymbol(),
                'precision' => $this->getCurrencyPrecision()
            ],
            'settings' => [
                'requireEmail' => $this->isEmailRequired(),
                'requirePostcode' => $this->helperData->isPostcodeRequired(),
                'postcodeFieldType' => $this->helperData->getPostcodeFieldType(), // إضافة هذا السطر
                'requireRegion' => $this->isRegionRequired(),
                'requireCity' => $this->isCityRequired(),
                'showStreet2' => $this->showStreet2(),
                'autoGenerateEmail' => $this->helperData->isAutoGenerateEmailEnabled(),
                'emailDomain' => $this->helperData->getEmailDomain(),
                'phoneValidation' => $this->helperData->isPhoneValidationEnabled(),
                'hideCountry' => $this->helperData->isCountryHiddenCss(),
                'additionalFields' => $this->helperData->getAdditionalFields(),
                'onepageEnabled' => $this->helperData->isOnepageEnabled(),
                'enableCouponToggle' => $this->helperData->isCouponToggleEnabled(),
                'enableCustomerNoteToggle' => $this->helperData->isCustomerNoteToggleEnabled(),
                'customerNoteMaxLength' => $this->helperData->getCustomerNoteMaxLength(),
                'enableSuccessSound' => $this->helperData->isSuccessSoundEnabled(),
                'enableConfetti' => $this->helperData->isConfettiEnabled(),
                'autoScrollToSuccess' => $this->helperData->isAutoScrollToSuccessEnabled()
            ],
            'customer' => [
                'isLoggedIn' => $this->isCustomerLoggedIn(),
                'name' => $this->getPrefilledCustomerName(),
                'email' => $this->getPrefilledCustomerEmail(),
                'phone' => $this->getPrefilledCustomerPhone(),
                'address' => $this->getPrefilledCustomerAddress(),
                'addresses' => $this->getCustomerAddressesForJs()
            ],
            'urls' => [
                'shipping' => $this->getShippingMethodsUrl(),
                'calculate' => $this->getCalculateTotalUrl(),
                'regions' => $this->getRegionsUrl(),
                'submit' => $this->getFormActionUrl(),
                'getPrice' => $this->getUrl('easyorder/ajax/getprice'),
                'coupon' => $this->getUrl('easyorder/ajax/coupon')
            ],
            'messages' => [
                'loading' => __('Loading...'),
                'error' => __('An error occurred. Please try again.'),
                'selectShipping' => __('Please select a shipping method'),
                'selectPayment' => __('Please select a payment method'),
                'fillRequired' => __('Please fill all required fields'),
                'loadingShipping' => __('جارٍ تحميل طرق الشحن...'),
                'noShippingMethods' => __('لا توجد طرق شحن متاحة لهذا المكان'),
                'shippingError' => __('خطأ في تحميل طرق الشحن'),
                'invalidPhone' => __('رقم الهاتف غير صحيح'),
                'invalidEmail' => __('البريد الإلكتروني غير صحيح')
            ]
        ];

        return $this->jsonHelper->jsonEncode($config);
    }

    /**
     * Check customer logged-in status
     */
    public function isCustomerLoggedIn(): bool
    {
        return (bool)$this->customerSession->isLoggedIn();
    }

    /**
     * Best-effort prefill name for logged-in customer
     */
    public function getPrefilledCustomerName(): string
    {
        if (!$this->isCustomerLoggedIn()) {
            return '';
        }
        try {
            $customerId = (int)$this->customerSession->getCustomerId();
            if ($customerId <= 0) {
                return '';
            }
            $customer = $this->customerRepository->getById($customerId);
            $firstname = (string)$customer->getFirstname();
            $lastname = (string)$customer->getLastname();
            return trim($firstname . ' ' . $lastname);
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Prefill email for logged-in customer
     */
    public function getPrefilledCustomerEmail(): string
    {
        if (!$this->isCustomerLoggedIn()) {
            return '';
        }
        try {
            $customerId = (int)$this->customerSession->getCustomerId();
            if ($customerId <= 0) {
                return '';
            }
            $customer = $this->customerRepository->getById($customerId);
            return (string)$customer->getEmail();
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Prefill phone from default shipping/billing telephone if available
     */
    public function getPrefilledCustomerPhone(): string
    {
        if (!$this->isCustomerLoggedIn()) {
            return '';
        }
        try {
            $customerId = (int)$this->customerSession->getCustomerId();
            if ($customerId <= 0) {
                return '';
            }
            $customer = $this->customerRepository->getById($customerId);
            $defaultShippingId = $customer->getDefaultShipping();
            $defaultBillingId = $customer->getDefaultBilling();
            $addressId = $defaultShippingId ?: $defaultBillingId;
            if ($addressId) {
                $address = $this->addressRepository->getById((int)$addressId);
                $telephone = (string)$address->getTelephone();
                return $telephone ?: '';
            }
        } catch (\Exception $e) {
            // ignore and return empty
        }
        return '';
    }

    /**
     * Prefill customer default address data (shipping preferred, billing fallback)
     * Returns associative array suitable for frontend prefill.
     */
    public function getPrefilledCustomerAddress(): array
    {
        $prefill = [
            'country_id' => '',
            'region_id' => '',
            'region' => '',
            'city' => '',
            'postcode' => '',
            'street_1' => '',
            'street_2' => ''
        ];
        if (!$this->isCustomerLoggedIn()) {
            return $prefill;
        }
        try {
            $customerId = (int)$this->customerSession->getCustomerId();
            if ($customerId <= 0) {
                return $prefill;
            }
            $customer = $this->customerRepository->getById($customerId);
            $defaultShippingId = $customer->getDefaultShipping();
            $defaultBillingId = $customer->getDefaultBilling();
            $addressId = $defaultShippingId ?: $defaultBillingId;
            if (!$addressId) {
                return $prefill;
            }
            $address = $this->addressRepository->getById((int)$addressId);
            $prefill['country_id'] = (string)$address->getCountryId();
            $prefill['region_id'] = (string)($address->getRegionId() ?? '');
            $prefill['region'] = (string)($address->getRegion() ? $address->getRegion()->getRegion() : '');
            $prefill['city'] = (string)$address->getCity();
            $prefill['postcode'] = (string)$address->getPostcode();
            $streets = $address->getStreet();
            if (is_array($streets)) {
                $prefill['street_1'] = (string)($streets[0] ?? '');
                $prefill['street_2'] = (string)($streets[1] ?? '');
            }
            return $prefill;
        } catch (\Exception $e) {
            return $prefill;
        }
    }

    /**
     * Return all customer addresses for selection on frontend
     */
    public function getCustomerAddressesForJs(): array
    {
        $result = [];
        if (!$this->isCustomerLoggedIn()) {
            return $result;
        }
        try {
            $customerId = (int)$this->customerSession->getCustomerId();
            if ($customerId <= 0) {
                return $result;
            }
            $customer = $this->customerRepository->getById($customerId);
            $defaultShippingId = (int)($customer->getDefaultShipping() ?: 0);
            $defaultBillingId = (int)($customer->getDefaultBilling() ?: 0);
            foreach ($customer->getAddresses() as $address) {
                $result[] = [
                    'id' => (int)$address->getId(),
                    'label' => trim(($address->getFirstname() ?: '') . ' ' . ($address->getLastname() ?: '')) . ' - ' . implode(', ', (array)$address->getStreet()) . ', ' . ($address->getCity() ?: ''),
                    'country_id' => (string)$address->getCountryId(),
                    'region_id' => (string)($address->getRegionId() ?: ''),
                    'region' => (string)($address->getRegion() ? $address->getRegion()->getRegion() : ''),
                    'city' => (string)$address->getCity(),
                    'postcode' => (string)$address->getPostcode(),
                    'street_1' => (string)((array)$address->getStreet())[0] ?? '',
                    'street_2' => (string)((array)$address->getStreet())[1] ?? '',
                    'telephone' => (string)$address->getTelephone(),
                    'is_default_shipping' => ((int)$address->getId() === $defaultShippingId),
                    'is_default_billing' => ((int)$address->getId() === $defaultBillingId)
                ];
            }
        } catch (\Exception $e) {
            return $result;
        }
        return $result;
    }

    /**
     * Get form security key
     *
     * @return string
     */
    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    /**
     * Get default payment method for pre-selection
     *
     * @return string
     */
    public function getDefaultPaymentMethod(): string
    {
        return $this->helperData->getDefaultPaymentMethod();
    }

    /**
     * Check if product has configurable options
     *
     * @return bool
     */
    public function hasConfigurableOptions()
    {
        $product = $this->getCurrentProduct();
        return $product && $product->getTypeId() === 'configurable';
    }

    /**
     * Get product options HTML
     *
     * @return string
     */
    public function getProductOptionsHtml()
    {
        $product = $this->getCurrentProduct();
        if (!$product || $product->getTypeId() !== 'configurable') {
            return '';
        }
        
        $html = '';
        $configurableAttributes = $product->getTypeInstance()->getConfigurableAttributes($product);
        
        foreach ($configurableAttributes as $attribute) {
            $productAttribute = $attribute->getProductAttribute();
            $attributeId = $productAttribute->getId();
            $attributeLabel = $productAttribute->getStoreLabel();
            
            $html .= '<div class="field">';
            $html .= '<label class="label" for="attribute' . $attributeId . '">';
            $html .= '<span>' . $this->escapeHtml($attributeLabel) . ' *</span>';
            $html .= '</label>';
            $html .= '<div class="control">';
            $html .= '<select name="super_attribute[' . $attributeId . ']" ';
            $html .= 'id="attribute' . $attributeId . '" ';
            $html .= 'class="select product-option-select" required>';
            $html .= '<option value="">' . __('اختر...') . '</option>';
            
            foreach ($attribute->getOptions() as $option) {
                if ($option['value_index']) {
                    $html .= '<option value="' . $option['value_index'] . '">';
                    $html .= $this->escapeHtml($option['label']) . '</option>';
                }
            }
            
            $html .= '</select>';
            $html .= '</div>';
            $html .= '</div>';
        }
        
        return $html;
    }

    /**
     * Get current store currency code
     *
     * @return string
     */
    public function getCurrentCurrencyCode(): string
    {
        return $this->storeManager->getStore()->getCurrentCurrencyCode();
    }

    /**
     * Get current store currency symbol
     *
     * @return string
     */
    public function getCurrentCurrencySymbol(): string
    {
        return $this->storeManager->getStore()->getCurrentCurrency()->getCurrencySymbol();
    }

    /**
     * Get currency precision
     *
     * @return int
     */
    public function getCurrencyPrecision(): int
    {
        return 2; // يمكن جعلها قابلة للتخصيص من الإعدادات
    }
}