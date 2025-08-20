<?php
namespace MagoArab\EasYorder\Controller\Adminhtml\OrderAttributes;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;

class Debug extends Action
{
    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        echo 'OrderAttributes Debug controller is working!<br>';
        echo 'Namespace: ' . __NAMESPACE__ . '<br>';
        echo 'Class: ' . __CLASS__ . '<br>';
        echo 'File: ' . __FILE__ . '<br>';
        exit;
    }

    protected function _isAllowed()
    {
        return true;
    }
}
