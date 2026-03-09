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

class MageAustralia_Stripe_Model_Stripe extends Mage_Payment_Model_Method_Abstract
{
    private ?MageAustralia_Stripe_Helper_Data $_stripeHelper = null;

    public function getStripeHelper(): MageAustralia_Stripe_Helper_Data
    {
        if ($this->_stripeHelper === null) {
            $this->_stripeHelper = Mage::helper('stripe');
        }
        return $this->_stripeHelper;
    }

    /**
     * Start a transaction: creates a Stripe Checkout Session and returns the redirect URL
     */
    public function startTransaction(Mage_Sales_Model_Order $order): ?string
    {
        $storeId = (int) $order->getStoreId();
        $secretKey = $this->getStripeHelper()->getSecretKey($storeId);
        if (!$secretKey) {
            return null;
        }

        $stripe = $this->getStripeHelper()->getStripeClient($storeId);
        $methodCode = $order->getPayment()->getMethod();
        $stripeType = $this->getStripeHelper()->getMethodStripeType($methodCode);
        $paymentToken = $this->getStripeHelper()->getPaymentToken();
        $currency = strtolower($order->getOrderCurrencyCode());
        $amount = $this->getStripeHelper()->formatAmountForStripe((float) $order->getGrandTotal(), $currency);

        // Check if order already has a checkout session
        $existingSessionId = $order->getPayment()->getAdditionalInformation('stripe_checkout_session_id');
        if (!empty($existingSessionId)) {
            try {
                $existingSession = $stripe->checkout->sessions->retrieve($existingSessionId);
                if ($existingSession->status === 'open' && !empty($existingSession->url)) {
                    $this->getStripeHelper()->addToLog('info', Mage::helper('stripe')->__('Reusing existing checkout session %s', $existingSessionId));
                    return $existingSession->url;
                }
            } catch (\Exception $e) {
                $this->getStripeHelper()->addToLog('error', Mage::helper('stripe')->__('Could not retrieve existing session: %s', $e->getMessage()));
            }
        }

        // Determine cancel URL — redirect storefront orders back to their origin
        $storefrontOrigin = $order->getData('storefront_origin');
        $cancelUrl = $storefrontOrigin
            ? rtrim($storefrontOrigin, '/') . '/checkout'
            : Mage::getUrl('checkout/cart', ['_secure' => true]);

        // Create Checkout Session
        $sessionParams = [
            'payment_method_types' => [$stripeType],
            'mode'                 => 'payment',
            'success_url'          => $this->getStripeHelper()->getReturnUrl((int) $order->getId(), $paymentToken, $storeId),
            'cancel_url'           => $cancelUrl,
            'client_reference_id'  => $order->getIncrementId(),
            'customer_email'       => $order->getCustomerEmail(),
            'metadata'             => [
                'order_id'      => $order->getId(),
                'store_id'      => $storeId,
                'payment_token' => $paymentToken,
            ],
            'line_items' => [[
                'price_data' => [
                    'currency'     => $currency,
                    'unit_amount'  => $amount,
                    'product_data' => [
                        'name' => Mage::helper('stripe')->__('Order #%s', $order->getIncrementId()),
                    ],
                ],
                'quantity' => 1,
            ]],
        ];

        $this->getStripeHelper()->addToLog('request', $sessionParams);

        try {
            $session = $stripe->checkout->sessions->create($sessionParams);
        } catch (\Exception $e) {
            $this->getStripeHelper()->addToLog('error', Mage::helper('stripe')->__('Stripe session creation failed: %s', $e->getMessage()));
            Mage::throwException(Mage::helper('stripe')->__('Unable to create Stripe payment session: %s', $e->getMessage()));
            return null;
        }

        $this->getStripeHelper()->addToLog('response', ['id' => $session->id, 'url' => $session->url]);

        // Store IDs on payment
        $order->getPayment()->setAdditionalInformation('stripe_checkout_session_id', $session->id);
        $order->getPayment()->setAdditionalInformation('stripe_payment_token', $paymentToken);
        $order->getPayment()->setAdditionalInformation('checkout_type', 'checkout');

        $status = $this->getStripeHelper()->getStatusPending($storeId);
        $order->addStatusHistoryComment(Mage::helper('stripe')->__('Customer redirected to Stripe'))
            ->setStatus($status);

        $order->setStripePaymentIntentId($session->payment_intent ?? $session->id);
        $order->save();

        return $session->url;
    }

    /**
     * Create a PaymentIntent for inline Elements checkout (no redirect)
     *
     * @return array{clientSecret: string, paymentIntentId: string}
     */
    public function createPaymentIntent(Mage_Sales_Model_Quote $quote): array
    {
        $storeId = (int) $quote->getStoreId();
        $stripe = $this->getStripeHelper()->getStripeClient($storeId);
        $currency = strtolower($quote->getQuoteCurrencyCode());
        $amount = $this->getStripeHelper()->formatAmountForStripe((float) $quote->getGrandTotal(), $currency);

        $params = [
            'amount'               => $amount,
            'currency'             => $currency,
            'payment_method_types' => ['card'],
            'metadata'             => [
                'quote_id' => $quote->getId(),
                'store_id' => $storeId,
            ],
        ];

        $this->getStripeHelper()->addToLog('request', ['createPaymentIntent' => $params]);

        try {
            $pi = $stripe->paymentIntents->create($params);
        } catch (\Exception $e) {
            $this->getStripeHelper()->addToLog('error', Mage::helper('stripe')->__('PaymentIntent creation failed: %s', $e->getMessage()));
            Mage::throwException(Mage::helper('stripe')->__('Unable to create payment: %s', $e->getMessage()));
        }

        $this->getStripeHelper()->addToLog('response', ['id' => $pi->id, 'status' => $pi->status]);

        return [
            'clientSecret'    => $pi->client_secret,
            'paymentIntentId' => $pi->id,
        ];
    }

    /**
     * Process a transaction from webhook or customer return
     *
     * @return array{success?: bool, error?: bool, status: string, order_id?: int, msg?: string, type?: string}
     */
    public function processTransaction(int $orderId, string $type = 'webhook', ?string $paymentToken = null): array
    {
        /** @var Mage_Sales_Model_Order $order */
        $order = Mage::getModel('sales/order')->load($orderId);
        if (empty($order) || !$order->getId()) {
            $msg = ['error' => true, 'msg' => 'Order not found'];
            $this->getStripeHelper()->addToLog('error', $msg);
            return $msg;
        }

        $storeId = (int) $order->getStoreId();
        $stripe = $this->getStripeHelper()->getStripeClient($storeId);
        $sessionId = $order->getPayment()->getAdditionalInformation('stripe_checkout_session_id');

        if (empty($sessionId)) {
            $msg = ['error' => true, 'msg' => 'Checkout session ID not found'];
            $this->getStripeHelper()->addToLog('error', $msg);
            return $msg;
        }

        try {
            $session = $stripe->checkout->sessions->retrieve($sessionId, [
                'expand' => ['payment_intent', 'payment_intent.latest_charge'],
            ]);
        } catch (\Exception $e) {
            $this->getStripeHelper()->addToLog('error', Mage::helper('stripe')->__('Failed to retrieve checkout session: %s', $e->getMessage()));
            return ['error' => true, 'msg' => $e->getMessage()];
        }

        $this->getStripeHelper()->addToLog($type, [
            'session_id'     => $session->id,
            'status'         => $session->status,
            'payment_status' => $session->payment_status,
        ]);

        // Verify payment token on return (not webhook)
        if ($type !== 'webhook' && $paymentToken !== null) {
            $storedToken = $order->getPayment()->getAdditionalInformation('stripe_payment_token');
            if ($paymentToken !== $storedToken) {
                $this->getStripeHelper()->addToLog('error', 'Payment token mismatch');
                return ['success' => false, 'status' => 'token_mismatch', 'order_id' => $orderId];
            }
        }

        $paymentStatus = $session->payment_status; // 'paid', 'unpaid', 'no_payment_required'
        $piStatus = ($session->payment_intent !== null) ? $session->payment_intent->status : null;

        // Store payment intent ID if available
        if ($session->payment_intent !== null && $session->payment_intent->id) {
            $order->setStripePaymentIntentId($session->payment_intent->id);
            $order->getPayment()->setAdditionalInformation('payment_status', $piStatus);

            if (isset($session->payment_intent->payment_method)) {
                $order->getPayment()->setAdditionalInformation(
                    'stripe_payment_method',
                    $session->payment_intent->payment_method,
                );
            }

            // Extract charge details (card info, 3DS, risk)
            $charge = $session->payment_intent->latest_charge ?? null;
            if ($charge !== null) {
                $this->storeChargeDetails($order->getPayment(), $charge);
            }
        }

        // Paid + webhook: register capture notification
        if ($paymentStatus === 'paid' && $type === 'webhook') {
            $payment = $order->getPayment();
            if (!$payment->getIsTransactionClosed()) {
                if ($order->isCanceled()) {
                    $order = $this->uncancelOrder($order);
                }

                $payment->setTransactionId($session->payment_intent->id);
                $payment->setCurrencyCode($order->getBaseCurrencyCode());
                $payment->setIsTransactionClosed(true);
                $payment->registerCaptureNotification($order->getBaseGrandTotal(), true);
                $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING);

                if (!$order->getEmailSent()) {
                    try {
                        $order->sendNewOrderEmail()->setEmailSent(true);
                    } catch (\Exception $e) {
                        $order->addStatusHistoryComment(
                            Mage::helper('stripe')->__('Unable to send order email: %s', $e->getMessage()),
                        );
                    }
                }

                $statusProcessing = $this->getStripeHelper()->getStatusProcessing($storeId);
                if ($statusProcessing && $statusProcessing !== $order->getStatus()) {
                    $order->setStatus($statusProcessing);
                }

                // Send invoice email
                $invoice = $payment->getCreatedInvoice();
                if ($invoice && !$invoice->getEmailSent()) {
                    try {
                        $invoice->setEmailSent(true)->sendEmail()->save();
                    } catch (\Exception $e) {
                        $order->addStatusHistoryComment(
                            Mage::helper('stripe')->__('Unable to send invoice email: %s', $e->getMessage()),
                        );
                    }
                }
            }

            $order->save();
            return ['success' => true, 'status' => 'paid', 'order_id' => $orderId, 'type' => $type];
        }

        // Paid + return: optimistic redirect to success
        if ($paymentStatus === 'paid' && $type !== 'webhook') {
            $this->checkCheckoutSession($order, $paymentToken);
            $order->save();
            return ['success' => true, 'status' => 'paid', 'order_id' => $orderId, 'type' => $type];
        }

        // Async payment methods (BECS, PayTo, SEPA): session is complete but payment is still processing.
        // Treat as success on return so customer sees the success page; webhook confirms actual payment later.
        if ($paymentStatus === 'unpaid' && $type !== 'webhook' && $session->status === 'complete') {
            $this->checkCheckoutSession($order, $paymentToken);
            $order->save();
            return ['success' => true, 'status' => 'processing', 'order_id' => $orderId, 'type' => $type];
        }

        // Unpaid
        if ($paymentStatus === 'unpaid') {
            if ($session->status === 'expired' && $type === 'webhook') {
                $this->registerCancellation($order, 'expired');
            }
            $order->save();
            return ['success' => false, 'status' => 'unpaid', 'order_id' => $orderId, 'type' => $type];
        }

        $order->save();
        return ['success' => false, 'status' => $paymentStatus ?? 'unknown', 'order_id' => $orderId, 'type' => $type];
    }

    /**
     * Refund a payment via the Stripe API
     */
    public function refund(Varien_Object $payment, $amount): static
    {
        /** @var Mage_Sales_Model_Order $order */
        $order = $payment->getOrder();
        $storeId = (int) $order->getStoreId();
        $piId = $order->getStripePaymentIntentId();

        if (empty($piId)) {
            $this->getStripeHelper()->addToLog('error', 'Cannot refund: Payment Intent ID not found');
            Mage::throwException(Mage::helper('stripe')->__('Cannot refund: Payment Intent ID not found'));
            return $this;
        }

        $stripe = $this->getStripeHelper()->getStripeClient($storeId);
        $currency = strtolower($order->getOrderCurrencyCode());
        $amountInCents = $this->getStripeHelper()->formatAmountForStripe((float) $amount, $currency);

        try {
            $refund = $stripe->refunds->create([
                'payment_intent' => $piId,
                'amount'         => $amountInCents,
            ]);
            $this->getStripeHelper()->addToLog('refund', [
                'id'     => $refund->id,
                'status' => $refund->status,
                'amount' => $amountInCents,
            ]);
        } catch (\Exception $e) {
            $this->getStripeHelper()->addToLog('error', $e->getMessage());
            Mage::throwException(Mage::helper('stripe')->__('Stripe refund error: %s', $e->getMessage()));
        }

        return $this;
    }

    /**
     * Find an order by its Stripe Payment Intent ID
     */
    public function getOrderIdByPaymentIntentId(string $paymentIntentId): int|false
    {
        $orderId = Mage::getModel('sales/order')->getCollection()
            ->addFieldToFilter('stripe_payment_intent_id', $paymentIntentId)
            ->getFirstItem()
            ->getId();

        if ($orderId) {
            return (int) $orderId;
        }

        $this->getStripeHelper()->addToLog(
            'error',
            Mage::helper('stripe')->__('No order found for payment intent ID %s', $paymentIntentId),
        );
        return false;
    }

    /**
     * Extract and store charge details (card, 3DS, risk) as additional information
     */
    protected function storeChargeDetails(Mage_Sales_Model_Order_Payment $payment, object $charge): void
    {
        // Card details
        $card = $charge->payment_method_details->card ?? null;
        if ($card !== null) {
            $payment->setAdditionalInformation('card_brand', $card->brand ?? null);
            $payment->setAdditionalInformation('card_last4', $card->last4 ?? null);
            $payment->setAdditionalInformation('card_exp', ($card->exp_month ?? '') . '/' . ($card->exp_year ?? ''));
            $payment->setAdditionalInformation('card_funding', $card->funding ?? null);
            $payment->setAdditionalInformation('card_country', $card->country ?? null);

            // 3D Secure / authentication
            $threeDSecure = $card->three_d_secure ?? null;
            if ($threeDSecure !== null) {
                $payment->setAdditionalInformation('three_d_secure_authenticated', $threeDSecure->authenticated ?? null);
                $payment->setAdditionalInformation('three_d_secure_result', $threeDSecure->result ?? null);
                $payment->setAdditionalInformation('three_d_secure_version', $threeDSecure->version ?? null);
            }
        }

        // Outcome / risk
        $outcome = $charge->outcome ?? null;
        if ($outcome !== null) {
            $payment->setAdditionalInformation('risk_level', $outcome->risk_level ?? null);
            $payment->setAdditionalInformation('risk_score', $outcome->risk_score ?? null);
            $payment->setAdditionalInformation('seller_message', $outcome->seller_message ?? null);
            $payment->setAdditionalInformation('network_status', $outcome->network_status ?? null);
        }

        // BECS Direct Debit (AU)
        $becs = $charge->payment_method_details->au_becs_debit ?? null;
        if ($becs !== null) {
            $payment->setAdditionalInformation('becs_bsb', $becs->bsb_number ?? null);
            $payment->setAdditionalInformation('becs_last4', $becs->last4 ?? null);
            $payment->setAdditionalInformation('becs_mandate', $becs->mandate ?? null);
            $payment->setAdditionalInformation('becs_fingerprint', $becs->fingerprint ?? null);
        }

        // SEPA Direct Debit
        $sepa = $charge->payment_method_details->sepa_debit ?? null;
        if ($sepa !== null) {
            $payment->setAdditionalInformation('sepa_last4', $sepa->last4 ?? null);
            $payment->setAdditionalInformation('sepa_bank_code', $sepa->bank_code ?? null);
            $payment->setAdditionalInformation('sepa_country', $sepa->country ?? null);
            $payment->setAdditionalInformation('sepa_mandate', $sepa->mandate_reference ?? null);
            $payment->setAdditionalInformation('sepa_fingerprint', $sepa->fingerprint ?? null);
        }

        // PayTo
        $payto = $charge->payment_method_details->payto ?? null;
        if ($payto !== null) {
            $payment->setAdditionalInformation('payto_bsb', $payto->bsb_number ?? null);
            $payment->setAdditionalInformation('payto_last4', $payto->last4 ?? null);
            $payment->setAdditionalInformation('payto_mandate_id', $payto->mandate_id ?? null);
            $payment->setAdditionalInformation('payto_pay_id', $payto->pay_id ?? null);
        }

        // Klarna
        $klarna = $charge->payment_method_details->klarna ?? null;
        if ($klarna !== null) {
            $payment->setAdditionalInformation('klarna_payment_method', $klarna->payment_method_category ?? null);
        }

        // PayPal
        $paypal = $charge->payment_method_details->paypal ?? null;
        if ($paypal !== null) {
            $payment->setAdditionalInformation('paypal_payer_email', $paypal->payer_email ?? null);
            $payment->setAdditionalInformation('paypal_payer_id', $paypal->payer_id ?? null);
            $payment->setAdditionalInformation('paypal_transaction_id', $paypal->transaction_id ?? null);
        }

        // Payment method type (card, au_becs_debit, klarna, paypal, etc.)
        $payment->setAdditionalInformation('payment_method_type', $charge->payment_method_details->type ?? null);

        // Charge ID for reference
        $payment->setAdditionalInformation('stripe_charge_id', $charge->id ?? null);
    }

    /**
     * Restore the checkout session so the success page renders correctly
     */
    private function checkCheckoutSession(Mage_Sales_Model_Order $order, ?string $paymentToken): void
    {
        $session = Mage::getSingleton('checkout/session');

        // Ensure the checkout session references this order
        if ($session->getLastOrderId() != $order->getId()) {
            $session->setLastOrderId($order->getId());
            $session->setLastRealOrderId($order->getIncrementId());
            $session->setLastQuoteId($order->getQuoteId());
            $session->setLastSuccessQuoteId($order->getQuoteId());
        }
    }

    /**
     * Cancel an order with a status reason
     */
    private function registerCancellation(Mage_Sales_Model_Order $order, string $status): void
    {
        if ($order->getId() && $order->getState() !== Mage_Sales_Model_Order::STATE_CANCELED) {
            $comment = Mage::helper('stripe')->__('The order was canceled, reason: payment %s', $status);
            $this->getStripeHelper()->addToLog('info', $order->getIncrementId() . ' ' . $comment);
            $order->cancel();
            $order->addStatusHistoryComment($comment);
            $order->save();
        }
    }

    /**
     * Uncancel a previously canceled order (e.g. late webhook after customer canceled)
     */
    private function uncancelOrder(Mage_Sales_Model_Order $order): Mage_Sales_Model_Order
    {
        try {
            $status = $this->getStripeHelper()->getStatusPending((int) $order->getStoreId());
            $message = Mage::helper('stripe')->__('Order uncanceled by webhook.');
            $state = Mage_Sales_Model_Order::STATE_NEW;
            $order->setState($state, $status, $message, false)->save();

            foreach ($order->getAllItems() as $item) {
                $item->setQtyCanceled(0)->save();
            }
        } catch (\Exception $e) {
            $this->getStripeHelper()->addToLog('error', $e->getMessage());
        }

        return $order;
    }
}
