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

class MageAustralia_Stripe_Block_Payment_Redirect extends Mage_Core_Block_Template
{
    /**
     * Get the Stripe redirect URL from the checkout session order's additional info.
     * Falls back to retrieving the URL from the Stripe API if not stored locally.
     */
    public function getRedirectUrl(): ?string
    {
        /** @var MageAustralia_Stripe_Helper_Data $helper */
        $helper = Mage::helper('stripe');
        $order = $helper->getOrderFromSession();

        if (!$order) {
            return null;
        }

        $payment = $order->getPayment();
        if (!$payment) {
            return null;
        }

        $sessionId = $payment->getAdditionalInformation('stripe_checkout_session_id');
        if (empty($sessionId)) {
            return null;
        }

        try {
            $stripe = $helper->getStripeClient((int) $order->getStoreId());
            $session = $stripe->checkout->sessions->retrieve($sessionId);
            return $session->url;
        } catch (\Exception $e) {
            $helper->addToLog('error', Mage::helper('stripe')->__('Could not retrieve redirect URL: %s', $e->getMessage()));
            return null;
        }
    }
}
