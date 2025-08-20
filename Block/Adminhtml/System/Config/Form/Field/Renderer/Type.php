<?php
declare(strict_types=1);

namespace MagoArab\EasYorder\Block\Adminhtml\System\Config\Form\Field\Renderer;

use Magento\Framework\View\Element\Html\Select;

class Type extends Select
{
    protected function _toHtml()
    {
        if (!$this->getOptions()) {
            $this->setOptions([
                ['label' => __('Text'), 'value' => 'text'],
                ['label' => __('Textarea'), 'value' => 'textarea'],
                ['label' => __('Select'), 'value' => 'select'],
                ['label' => __('Checkbox'), 'value' => 'checkbox'],
            ]);
        }
        return parent::_toHtml();
    }
}



