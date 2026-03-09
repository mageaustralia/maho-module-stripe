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

class MageAustralia_Stripe_Block_Adminhtml_Render_Heading extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    #[\Override]
    public function render(Varien_Data_Form_Element_Abstract $element): string
    {
        $label = $element->getLabel();
        return '<tr><td colspan="4"><h4 style="margin:10px 0 5px; padding:5px 0; border-bottom:1px solid #dfdfdf;">'
            . $this->escapeHtml($label) . '</h4></td></tr>';
    }
}
