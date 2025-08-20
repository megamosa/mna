<?php
namespace MagoArab\EasYorder\Controller\Adminhtml\Test;

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
        echo 'Test Simple Controller is working!';
        echo '<br>Namespace: ' . __NAMESPACE__;
        echo '<br>Class: ' . __CLASS__;
        echo '<br>File: ' . __FILE__;
        exit;
    }

    protected function _isAllowed()
    {
        return true;
    }
}
