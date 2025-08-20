<?php
namespace MagoArab\EasYorder\Controller\Adminhtml\CustomerAttributes;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    protected $resultPageFactory;

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('MagoArab_EasYorder::easyorder');
        $resultPage->addBreadcrumb(__('EasyOrder'), __('EasyOrder'));
        $resultPage->addBreadcrumb(__('Customer Attributes'), __('Customer Attributes'));
        $resultPage->getConfig()->getTitle()->prepend(__('EasyOrder - Customer Attributes Management'));
        
        return $resultPage;
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('MagoArab_EasYorder::easyorder');
    }
}
