<?php
namespace MagoArab\EasYorder\Controller\Adminhtml\OrderAttributes;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\RawFactory;

class Test extends Action
{
    protected $resultRawFactory;

    public function __construct(
        Context $context,
        RawFactory $resultRawFactory
    ) {
        parent::__construct($context);
        $this->resultRawFactory = $resultRawFactory;
    }

    public function execute()
    {
        $result = $this->resultRawFactory->create();
        $result->setContents('OrderAttributes Test controller is working!');
        return $result;
    }

    protected function _isAllowed()
    {
        return true; // Allow access for testing
    }
}
