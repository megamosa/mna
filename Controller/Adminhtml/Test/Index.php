<?php
namespace MagoArab\EasYorder\Controller\Adminhtml\Test;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\RawFactory;

class Index extends Action
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
        $result->setContents('Test controller is working!');
        return $result;
    }

    protected function _isAllowed()
    {
        return true; // Allow access for testing
    }
}
