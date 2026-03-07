<?php
declare(strict_types=1);

/**
 * Copyright (c) 2026 Mage Australia Pty Ltd
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * - Redistributions of source code must retain the above copyright notice,
 *   this list of conditions and the following disclaimer.
 * - Redistributions in binary form must reproduce the above copyright notice,
 *   this list of conditions and the following disclaimer in the documentation
 *   and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE AUTHOR AND CONTRIBUTORS ``AS IS'' AND ANY
 * EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE AUTHOR OR CONTRIBUTORS BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY
 * OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH
 * DAMAGE.
 *
 * @category    MageAustralia
 * @package     MageAustralia_Stripe
 * @author      Mage Australia Pty Ltd
 * @copyright   Copyright (c) 2026 Mage Australia Pty Ltd
 * @license     http://www.opensource.org/licenses/bsd-license.php  BSD-License 2
 */

class MageAustralia_Stripe_Model_Method_Abstract extends MageAustralia_Stripe_Model_Stripe
{
    /**
     * Type of block that generates the payment method form
     */
    protected $_formBlockType = 'stripe/payment_form';

    /**
     * Type of block that displays payment method information
     */
    protected $_infoBlockType = 'stripe/payment_info_base';

    /**
     * Availability options
     */
    protected $_isGateway = true;
    protected $_canAuthorize = true;
    protected $_canUseCheckout = true;
    protected $_canUseInternal = false;
    protected $_canUseForMultishipping = false;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canCapture = true;

    /**
     * Check if this payment method is available
     */
    public function isAvailable($quote = null): bool
    {
        if (!$this->stripeHelper->isAvailable($quote ? (int)$quote->getStoreId() : null)) {
            return false;
        }

        return parent::isAvailable($quote);
    }

    /**
     * Get the URL to redirect to after order placement
     */
    public function getOrderPlaceRedirectUrl(): string
    {
        return Mage::getUrl('stripe_payment/payment/redirect', ['_secure' => true]);
    }

    /**
     * Assign additional data from payment form
     */
    public function assignData($data): static
    {
        parent::assignData($data);
        return $this;
    }
}
