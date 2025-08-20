<?php
/**
 * MagoArab_EasYorder Quick Orders Management Controller
 *
 * @category    MagoArab
 * @package     MagoArab_EasYorder
 * @author      MagoArab Development Team
 * @copyright   Copyright (c) 2025 MagoArab
 * @license     https://opensource.org/licenses/MIT MIT License
 */

namespace MagoArab\EasYorder\Controller\Adminhtml\Orders;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    /**
     * @var PageFactory
     */
    protected $resultPageFactory;

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
    }

    /**
     * Quick Orders management page
     *
     * @return \Magento\Framework\View\Result\Page
     */
    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('MagoArab_EasYorder::easyorder');
        $resultPage->addBreadcrumb(__('EasyOrder'), __('EasyOrder'));
        $resultPage->addBreadcrumb(__('Quick Orders'), __('Quick Orders'));
        $resultPage->getConfig()->getTitle()->prepend(__('EasyOrder - Quick Orders Management'));
        
        return $resultPage;
    }

    /**
     * Check if user has permissions to access this controller
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('MagoArab_EasYorder::easyorder_orders');
    }
}
