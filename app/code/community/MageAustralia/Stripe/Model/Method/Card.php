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

class MageAustralia_Stripe_Model_Method_Card extends MageAustralia_Stripe_Model_Method_Abstract
{
    protected $_code = 'stripe_card';

    /**
     * Override form block type based on integration mode config.
     * If 'elements' mode is configured, use the embedded Stripe Elements form.
     * Otherwise, use the default hosted checkout form.
     */
    public function getFormBlockType(): string
    {
        if ($this->_isElementsMode()) {
            return 'stripe/payment_form_elements';
        }

        return $this->_formBlockType;
    }

    /**
     * In elements mode, no redirect — payment is confirmed client-side.
     * In checkout mode, redirect to Stripe hosted checkout.
     */
    public function getOrderPlaceRedirectUrl(): string
    {
        if ($this->_isElementsMode()) {
            return '';
        }

        return parent::getOrderPlaceRedirectUrl();
    }

    /**
     * Elements mode uses authorize_capture so Maho creates the invoice automatically.
     * Checkout (redirect) mode uses the parent config (typically authorize_capture too).
     */
    public function getConfigPaymentAction(): ?string
    {
        if ($this->_isElementsMode()) {
            return Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE_CAPTURE;
        }

        return parent::getConfigPaymentAction();
    }

    /**
     * Store the Stripe PaymentIntent ID from the form POST into additional_information.
     */
    public function assignData($data): static
    {
        parent::assignData($data);

        if ($data instanceof \Maho\DataObject) {
            $piId = $data->getData('stripe_payment_intent_id');
        } elseif (is_array($data)) {
            $piId = $data['stripe_payment_intent_id'] ?? null;
        } else {
            $piId = null;
        }

        if ($piId && str_starts_with((string) $piId, 'pi_')) {
            $this->getInfoInstance()->setAdditionalInformation('stripe_payment_intent_id', (string) $piId);
        }

        return $this;
    }

    /**
     * Capture: pull money from the customer's card.
     *
     * The storefront creates the PaymentIntent with `capture_method=manual`,
     * meaning `stripe.confirmCardPayment` only AUTHORISES the card. Money
     * doesn't actually move until we call paymentIntents->capture here —
     * which means a Maho order placement failure (validation, race, DB
     * outage, anything) before this method runs leaves the customer with
     * an auth that auto-releases instead of a real charge.
     *
     * Legacy flows that auto-captured client-side (PI status already
     * `succeeded` on entry) are still supported for in-flight transactions
     * and downstream modules that capture without going through here.
     */
    public function capture(\Maho\DataObject $payment, $amount): static
    {
        $piId = $payment->getAdditionalInformation('stripe_payment_intent_id');

        if (!$piId || !str_starts_with($piId, 'pi_')) {
            Mage::throwException(Mage::helper('stripe')->__('Invalid or missing Payment Intent ID.'));
        }

        /** @var Mage_Sales_Model_Order $order */
        $order = $payment->getOrder();
        $storeId = (int) $order->getStoreId();
        $stripe = $this->getStripeHelper()->getStripeClient($storeId);

        try {
            $pi = $stripe->paymentIntents->retrieve($piId, [
                'expand' => ['latest_charge'],
            ]);
        } catch (\Exception $e) {
            $this->getStripeHelper()->addToLog('error', Mage::helper('stripe')->__('PI retrieve failed: %s', $e->getMessage()));
            Mage::throwException(Mage::helper('stripe')->__('Could not verify payment: %s', $e->getMessage()));
        }

        // Verify amount + currency BEFORE we call capture. A mismatch here
        // means the order total diverged from what the customer authorised,
        // and we must not capture — call paymentIntents->cancel to release
        // the auth so the customer isn't charged.
        $currency = strtolower($order->getOrderCurrencyCode());
        $expectedAmount = $this->getStripeHelper()->formatAmountForStripe((float) $amount, $currency);

        if ($pi->amount !== $expectedAmount || strtolower($pi->currency) !== $currency) {
            $this->getStripeHelper()->addToLog('error', [
                'msg' => 'Amount/currency mismatch',
                'pi_amount' => $pi->amount,
                'expected_amount' => $expectedAmount,
                'pi_currency' => $pi->currency,
                'expected_currency' => $currency,
            ]);
            // Auth-only state? Release the auth. Already captured? Leave as-is
            // (refund path is the appropriate remediation in that case).
            if (in_array($pi->status, ['requires_capture', 'requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
                try {
                    $stripe->paymentIntents->cancel($piId);
                    $this->getStripeHelper()->addToLog('cancel', ['pi_id' => $piId, 'reason' => 'amount_mismatch']);
                } catch (\Exception $e) {
                    $this->getStripeHelper()->addToLog('error', Mage::helper('stripe')->__('Failed to cancel PI %s: %s', $piId, $e->getMessage()));
                }
            }
            Mage::throwException(Mage::helper('stripe')->__('Payment amount does not match the order total.'));
        }

        // If the PI is in requires_capture state, capture it now (manual-capture
        // flow). If it's already succeeded, the storefront created it with
        // capture_method=automatic (legacy/PRB paths) and we just record.
        if ($pi->status === 'requires_capture') {
            try {
                $pi = $stripe->paymentIntents->capture($piId, [], [
                    'expand' => ['latest_charge'],
                ]);
            } catch (\Exception $e) {
                $this->getStripeHelper()->addToLog('error', Mage::helper('stripe')->__('PI capture failed: %s', $e->getMessage()));
                Mage::throwException(Mage::helper('stripe')->__('Could not capture payment: %s', $e->getMessage()));
            }
        }

        if ($pi->status !== 'succeeded') {
            $this->getStripeHelper()->addToLog('error', Mage::helper('stripe')->__('PI status is %s after capture attempt, expected succeeded', $pi->status));
            Mage::throwException(Mage::helper('stripe')->__('Payment was not completed. Status: %s', $pi->status));
        }

        // Store PI ID on the order for refunds/lookups
        $order->setStripePaymentIntentId($piId);

        // Mark checkout type
        $payment->setAdditionalInformation('checkout_type', 'elements');
        $payment->setAdditionalInformation('payment_status', $pi->status);

        // Store charge details (card brand, last4, 3DS, risk, etc.)
        $charge = $pi->latest_charge ?? null;
        if ($charge !== null) {
            $this->storeChargeDetails($payment, $charge);
        }

        // Set transaction ID — use charge ID so invoice links to the actual Stripe charge
        $transactionId = $charge->id ?? $piId;
        $payment->setTransactionId($transactionId);
        $payment->setIsTransactionClosed(true);

        // Send order email
        if (!$order->getEmailSent()) {
            try {
                $order->sendNewOrderEmail()->setEmailSent(true);
            } catch (\Exception $e) {
                $order->addStatusHistoryComment(
                    Mage::helper('stripe')->__('Unable to send order email: %s', $e->getMessage()),
                );
            }
        }

        $this->getStripeHelper()->addToLog('capture', [
            'pi_id' => $piId,
            'charge_id' => $charge->id ?? null,
            'order' => $order->getIncrementId(),
            'amount' => $expectedAmount,
            'status' => $pi->status,
        ]);

        return $this;
    }

    private function _isElementsMode(): bool
    {
        return $this->getStripeHelper()->getStoreConfig('payment/stripe_card/integration_mode') === 'elements';
    }

    /**
     * Cancel: release a Stripe authorisation when Maho voids the order
     * before capture (e.g. payment validation failed, order was cancelled
     * from the admin before being invoiced). Called by Maho through the
     * Mage_Payment_Model_Method_Abstract::cancel() hook.
     *
     * Only cancellable while the PI is in an auth-only state. Once it has
     * been captured ('succeeded'), the customer has been charged and the
     * correct remediation is a refund, not a cancel.
     */
    #[\Override]
    public function cancel(\Maho\DataObject $payment): static
    {
        $piId = $payment->getAdditionalInformation('stripe_payment_intent_id');
        if (!$piId || !str_starts_with($piId, 'pi_')) {
            return $this;
        }

        $storeId = (int) $payment->getOrder()->getStoreId();
        $stripe = $this->getStripeHelper()->getStripeClient($storeId);

        try {
            $pi = $stripe->paymentIntents->retrieve($piId);
            if (in_array($pi->status, ['requires_capture', 'requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
                $stripe->paymentIntents->cancel($piId);
                $this->getStripeHelper()->addToLog('cancel', ['pi_id' => $piId, 'reason' => 'order_cancelled']);
            }
        } catch (\Exception $e) {
            $this->getStripeHelper()->addToLog('error', Mage::helper('stripe')->__('Failed to cancel PI %s on order cancel: %s', $piId, $e->getMessage()));
        }

        return $this;
    }
}
