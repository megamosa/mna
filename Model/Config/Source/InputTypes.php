<?php
/**
 * MagoArab_EasYorder Input Types Source Model
 *
 * @category    MagoArab
 * @package     MagoArab_EasYorder
 * @author      MagoArab Development Team
 * @copyright   Copyright (c) 2025 MagoArab
 * @license     https://opensource.org/licenses/MIT MIT License
 */

namespace MagoArab\EasYorder\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class InputTypes implements OptionSourceInterface
{
    /**
     * Get options
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'text', 'label' => __('Text')],
            ['value' => 'textarea', 'label' => __('Text Area')],
            ['value' => 'date', 'label' => __('Date')],
            ['value' => 'select', 'label' => __('Dropdown')],
            ['value' => 'multiselect', 'label' => __('Multiple Select')],
            ['value' => 'boolean', 'label' => __('Yes/No')],
            ['value' => 'file', 'label' => __('File Upload')]
        ];
    }
}
