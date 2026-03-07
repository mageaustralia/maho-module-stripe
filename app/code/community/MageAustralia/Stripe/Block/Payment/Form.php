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

class MageAustralia_Stripe_Block_Payment_Form extends Mage_Payment_Block_Form
{
    #[\Override]
    protected function _construct(): void
    {
        parent::_construct();
        $this->setTemplate('mageaustralia/stripe/payment/form.phtml');
    }
}
