<?php
namespace MagoArab\EasYorder\Controller\Adminhtml\CheckoutFields;

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
        $resultPage->addBreadcrumb(__('Manage Checkout Fields'), __('Manage Checkout Fields'));
        $resultPage->getConfig()->getTitle()->prepend(__('EasyOrder - Checkout Fields Management'));
        
        return $resultPage;
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('MagoArab_EasYorder::easyorder');
    }
}
