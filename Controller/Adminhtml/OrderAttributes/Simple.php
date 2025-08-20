<?php
namespace MagoArab\EasYorder\Controller\Adminhtml\OrderAttributes;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;

class Simple extends Action
{
    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        echo 'OrderAttributes Simple controller is working!';
        exit;
    }

    protected function _isAllowed()
    {
        return true;
    }
}
