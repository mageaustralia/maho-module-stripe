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

use Mage_Sales_Model_Order as Order;

class MageAustralia_Stripe_Model_Observer
{
    /**
     * Reactivate the quote when the customer returns from Stripe
     * and the order is still in pending/new state.
     * This allows the cart to be restored if the customer clicks "back"
     * or the payment was not completed.
     */
    public function restoreQuoteWhenReturningFromStripe(Varien_Event_Observer $observer): void
    {
        $session = Mage::getSingleton('checkout/session');
        $quoteId = $session->getLastQuoteId();
        $orderId = $session->getLastOrderId();

        /** @var Order $order */
        $order = Mage::getModel('sales/order')->load($orderId);

        if (!$quoteId || !in_array($order->getState(), [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT])) {
            return;
        }

        if (!$order->getPayment() || stripos((string)$order->getPayment()->getMethod(), 'stripe') === false) {
            return;
        }

        try {
            $quote = Mage::getModel('sales/quote')->load($quoteId);

            if (!$quote->getIsActive()) {
                $quote->setIsActive(true)->save();
            }
        } catch (\Exception $e) {
            Mage::logException($e);
        }
    }
}
