<?php
declare(strict_types=1);

namespace MagoArab\EasYorder\Block\Adminhtml\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Element\Html\Select;

class AdditionalFields extends AbstractFieldArray
{
    /** @var \MagoArab\EasYorder\Block\Adminhtml\System\Config\Form\Field\Renderer\Type */
    private $typeRenderer;

    /** @var \MagoArab\EasYorder\Block\Adminhtml\System\Config\Form\Field\Renderer\YesNo */
    private $yesnoRenderer;

    protected function _prepareToRender()
    {
        $this->addColumn('code', ['label' => __('Code'), 'class' => 'required-entry']);
        $this->addColumn('label', ['label' => __('Label'), 'class' => 'required-entry']);
        $this->addColumn('type', [
            'label' => __('Type'),
            'renderer' => $this->getTypeRenderer()
        ]);
        $this->addColumn('required', [
            'label' => __('Required'),
            'renderer' => $this->getYesNoRenderer()
        ]);
        $this->addColumn('sort_order', ['label' => __('Sort Order')]);
        $this->addColumn('options', ['label' => __('Options (comma-separated)')]);
        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Field');
    }

    private function getTypeRenderer(): Select
    {
        if (!$this->typeRenderer) {
            $this->typeRenderer = $this->getLayout()->createBlock(
                \MagoArab\EasYorder\Block\Adminhtml\System\Config\Form\Field\Renderer\Type::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->typeRenderer;
    }

    private function getYesNoRenderer(): Select
    {
        if (!$this->yesnoRenderer) {
            $this->yesnoRenderer = $this->getLayout()->createBlock(
                \MagoArab\EasYorder\Block\Adminhtml\System\Config\Form\Field\Renderer\YesNo::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->yesnoRenderer;
    }

    // Render field array inside system.xml as frontend_model
    protected function _getElementHtml(AbstractElement $element)
    {
        $this->setElement($element);
        return parent::_toHtml();
    }
}



