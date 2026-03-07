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

class MageAustralia_Stripe_Model_Adminhtml_System_Config_Source_Mode
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'test', 'label' => Mage::helper('stripe')->__('Test')],
            ['value' => 'live', 'label' => Mage::helper('stripe')->__('Live')],
        ];
    }
}
