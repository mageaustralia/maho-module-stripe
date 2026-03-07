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

class MageAustralia_Stripe_Block_Payment_Info_Base extends Mage_Payment_Block_Info
{
    #[\Override]
    protected function _construct(): void
    {
        parent::_construct();
        $this->setTemplate('mageaustralia/stripe/payment/info/base.phtml');
    }

    public function getPaymentStatus(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('payment_status');
    }

    public function getPaymentIntentId(): ?string
    {
        return $this->getInfo()->getOrder()?->getStripePaymentIntentId();
    }

    public function getCheckoutType(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('checkout_type');
    }

    public function getStripePaymentMethod(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('stripe_payment_method');
    }
}
