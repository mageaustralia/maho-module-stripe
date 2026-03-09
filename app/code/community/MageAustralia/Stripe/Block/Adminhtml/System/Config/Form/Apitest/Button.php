<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Mage Australia Pty Ltd
 * All rights reserved.
 *
 * @category    MageAustralia
 * @package     MageAustralia_Stripe
 * @author      Mage Australia Pty Ltd
 * @copyright   Copyright (c) 2026 Mage Australia Pty Ltd
 * @license     http://www.opensource.org/licenses/bsd-license.php  BSD-License 2
 */

class MageAustralia_Stripe_Block_Adminhtml_System_Config_Form_Apitest_Button extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    #[\Override]
    protected function _construct(): void
    {
        parent::_construct();
        $this->setTemplate('mageaustralia/stripe/system/config/apitest_button.phtml');
    }

    #[\Override]
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element): string
    {
        return $this->_toHtml();
    }

    public function getAjaxCheckUrl(): string
    {
        return Mage::helper('adminhtml')->getUrl('adminhtml/stripe/apiTest');
    }

    public function getButtonHtml(): string
    {
        $button = $this->getLayout()->createBlock('adminhtml/widget_button')
            ->setData([
                'id'      => 'stripe_apitest_button',
                'label'   => Mage::helper('stripe')->__('Run Self Test'),
                'onclick' => 'stripeApiTest(); return false;',
            ]);

        return $button->toHtml();
    }
}
