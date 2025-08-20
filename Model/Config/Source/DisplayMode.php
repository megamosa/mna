<?php
namespace MagoArab\EasYorder\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class DisplayMode implements ArrayInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'disabled', 'label' => __('Disabled')],
            ['value' => 'all', 'label' => __('All Products')],
            ['value' => 'selected', 'label' => __('Selected Products Only')],
        ];
    }
}


