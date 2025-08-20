<?php
/**
 * MagoArab_EasYorder Checkout Fields Save Controller
 *
 * @category    MagoArab
 * @package     MagoArab_EasYorder
 * @author      MagoArab Development Team
 * @copyright   Copyright (c) 2025 MagoArab
 * @license     https://opensource.org/licenses/MIT MIT License
 */

namespace MagoArab\EasYorder\Controller\Adminhtml\CheckoutFields;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;

class Save extends Action
{
    /**
     * @var WriterInterface
     */
    protected $configWriter;

    /**
     * @var JsonFactory
     */
    protected $jsonFactory;

    /**
     * @param Context $context
     * @param WriterInterface $configWriter
     * @param JsonFactory $jsonFactory
     */
    public function __construct(
        Context $context,
        WriterInterface $configWriter,
        JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
        $this->configWriter = $configWriter;
        $this->jsonFactory = $jsonFactory;
    }

    /**
     * Save configuration
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->jsonFactory->create();
        
        try {
            $data = $this->getRequest()->getPostValue();
            
            if (empty($data)) {
                throw new LocalizedException(__('No data provided'));
            }

            // Save configuration values
            $this->saveConfigValues($data);

            $result->setData([
                'success' => true,
                'message' => __('Configuration saved successfully')
            ]);

        } catch (\Exception $e) {
            $result->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }

        return $result;
    }

    /**
     * Save configuration values
     *
     * @param array $data
     * @return void
     */
    protected function saveConfigValues($data)
    {
        $configFields = [
            'customer_fields_enabled',
            'customer_name_required',
            'customer_email_required',
            'customer_phone_required',
            'shipping_fields_enabled',
            'shipping_address_required',
            'payment_fields_enabled',
            'summary_fields_enabled',
            'enable_coupon_toggle',
            'enable_customer_note_toggle',
            'customer_note_max_length',
            'enable_success_sound',
            'enable_confetti',
            'auto_scroll_to_success'
        ];

        foreach ($configFields as $field) {
            if (isset($data[$field])) {
                $value = $data[$field];
                if (is_array($value)) {
                    $value = implode(',', $value);
                }
                $this->configWriter->save('easyorder/form_fields/' . $field, $value);
            }
        }
    }

    /**
     * Check if user has permissions to access this controller
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('MagoArab_EasYorder::easyorder_checkout_fields');
    }
}
