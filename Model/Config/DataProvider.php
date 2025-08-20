<?php
/**
 * MagoArab_EasYorder Configuration DataProvider
 *
 * @category    MagoArab
 * @package     MagoArab_EasYorder
 * @author      MagoArab Development Team
 * @copyright   Copyright (c) 2025 MagoArab
 * @license     https://opensource.org/licenses/MIT MIT License
 */

namespace MagoArab\EasYorder\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Magento\Framework\Data\Collection;

class DataProvider extends AbstractDataProvider
{
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param ScopeConfigInterface $scopeConfig
     * @param array $meta
     * @param array $data
     */
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        ScopeConfigInterface $scopeConfig,
        array $meta = [],
        array $data = []
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->collection = new Collection();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * Get data
     *
     * @return array
     */
    public function getData()
    {
        if (!$this->getCollection()->getSize()) {
            $data = [
                'id' => 1,
                'customer_fields_enabled' => $this->getConfigValue('customer_fields_enabled', 1),
                'customer_name_required' => $this->getConfigValue('customer_name_required', 1),
                'customer_email_required' => $this->getConfigValue('customer_email_required', 1),
                'customer_phone_required' => $this->getConfigValue('customer_phone_required', 1),
                'shipping_fields_enabled' => $this->getConfigValue('shipping_fields_enabled', 1),
                'shipping_address_required' => $this->getConfigValue('shipping_address_required', 1),
                'payment_fields_enabled' => $this->getConfigValue('payment_fields_enabled', 1),
                'summary_fields_enabled' => $this->getConfigValue('summary_fields_enabled', 1),
                'enable_coupon_toggle' => $this->getConfigValue('enable_coupon_toggle', 1),
                'enable_customer_note_toggle' => $this->getConfigValue('enable_customer_note_toggle', 1),
                'customer_note_max_length' => $this->getConfigValue('customer_note_max_length', 200),
                'enable_success_sound' => $this->getConfigValue('enable_success_sound', 1),
                'enable_confetti' => $this->getConfigValue('enable_confetti', 1),
                'auto_scroll_to_success' => $this->getConfigValue('auto_scroll_to_success', 1),
            ];
            
            $this->getCollection()->addItem(new \Magento\Framework\DataObject($data));
        }
        
        $items = $this->getCollection()->toArray();
        return $items;
    }

    /**
     * Get configuration value
     *
     * @param string $field
     * @param mixed $default
     * @return mixed
     */
    protected function getConfigValue($field, $default = null)
    {
        $path = 'magoarab_easyorder/form_fields/' . $field;
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);
        return $value !== null ? $value : $default;
    }
}
