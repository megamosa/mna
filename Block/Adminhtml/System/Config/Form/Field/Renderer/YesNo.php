<?php
declare(strict_types=1);

namespace MagoArab\EasYorder\Block\Adminhtml\System\Config\Form\Field\Renderer;

use Magento\Framework\View\Element\Html\Select;

class YesNo extends Select
{
    protected function _toHtml()
    {
        if (!$this->getOptions()) {
            $this->setOptions([
                ['label' => __('No'), 'value' => '0'],
                ['label' => __('Yes'), 'value' => '1'],
            ]);
        }
        return parent::_toHtml();
    }
}



