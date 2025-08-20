<?php
namespace MagoArab\EasYorder\Controller\Adminhtml\Test;

use Magento\Backend\App\Action;

class Basic extends Action
{
    public function execute()
    {
        echo 'Basic Test Controller is working!';
        exit;
    }

    protected function _isAllowed()
    {
        return true;
    }
}
