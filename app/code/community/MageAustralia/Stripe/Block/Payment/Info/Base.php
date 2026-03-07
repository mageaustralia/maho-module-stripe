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

    public function getCardBrand(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('card_brand');
    }

    public function getCardLast4(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('card_last4');
    }

    public function getCardExp(): ?string
    {
        $exp = $this->getInfo()->getAdditionalInformation('card_exp');
        return ($exp && $exp !== '/') ? $exp : null;
    }

    public function getCardFunding(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('card_funding');
    }

    public function getCardCountry(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('card_country');
    }

    public function getThreeDSecureResult(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('three_d_secure_result');
    }

    public function getThreeDSecureVersion(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('three_d_secure_version');
    }

    public function getRiskLevel(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('risk_level');
    }

    public function getRiskScore(): mixed
    {
        return $this->getInfo()->getAdditionalInformation('risk_score');
    }

    public function getSellerMessage(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('seller_message');
    }

    public function getStripeChargeId(): ?string
    {
        return $this->getInfo()->getAdditionalInformation('stripe_charge_id');
    }
}
