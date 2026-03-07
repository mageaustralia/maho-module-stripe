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
    public MageAustralia_Stripe_Helper_Data $stripeHelper;

    public function __construct()
    {
        parent::_construct();
        $this->stripeHelper = Mage::helper('stripe');
    }

    /**
     * Start a transaction: creates a Stripe Checkout Session and returns the redirect URL
     */
    public function startTransaction(Mage_Sales_Model_Order $order): ?string
    {
        $storeId = (int)$order->getStoreId();
        $secretKey = $this->stripeHelper->getSecretKey($storeId);
        if (!$secretKey) {
            return null;
        }

        $stripe = $this->stripeHelper->getStripeClient($storeId);
        $methodCode = $order->getPayment()->getMethod();
        $stripeType = $this->stripeHelper->getMethodStripeType($methodCode);
        $paymentToken = $this->stripeHelper->getPaymentToken();
        $currency = strtolower($order->getOrderCurrencyCode());
        $amount = $this->stripeHelper->formatAmountForStripe((float)$order->getGrandTotal(), $currency);

        // Check if order already has a checkout session
        $existingSessionId = $order->getPayment()->getAdditionalInformation('stripe_checkout_session_id');
        if (!empty($existingSessionId)) {
            try {
                $existingSession = $stripe->checkout->sessions->retrieve($existingSessionId);
                if ($existingSession->status === 'open' && !empty($existingSession->url)) {
                    $this->stripeHelper->addToLog('info', Mage::helper('stripe')->__('Reusing existing checkout session %s', $existingSessionId));
                    return $existingSession->url;
                }
            } catch (\Exception $e) {
                $this->stripeHelper->addToLog('error', Mage::helper('stripe')->__('Could not retrieve existing session: %s', $e->getMessage()));
            }
        }

        // Create Checkout Session
        $sessionParams = [
            'payment_method_types' => [$stripeType],
            'mode'                 => 'payment',
            'success_url'          => $this->stripeHelper->getReturnUrl((int)$order->getId(), $paymentToken, $storeId),
            'cancel_url'           => Mage::getUrl('checkout/cart', ['_secure' => true]),
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

        $this->stripeHelper->addToLog('request', $sessionParams);

        try {
            $session = $stripe->checkout->sessions->create($sessionParams);
        } catch (\Exception $e) {
            $this->stripeHelper->addToLog('error', Mage::helper('stripe')->__('Stripe session creation failed: %s', $e->getMessage()));
            Mage::throwException(Mage::helper('stripe')->__('Unable to create Stripe payment session: %s', $e->getMessage()));
            return null;
        }

        $this->stripeHelper->addToLog('response', ['id' => $session->id, 'url' => $session->url]);

        // Store IDs on payment
        $order->getPayment()->setAdditionalInformation('stripe_checkout_session_id', $session->id);
        $order->getPayment()->setAdditionalInformation('stripe_payment_token', $paymentToken);
        $order->getPayment()->setAdditionalInformation('checkout_type', 'checkout');

        $status = $this->stripeHelper->getStatusPending($storeId);
        $order->addStatusToHistory($status, Mage::helper('stripe')->__('Customer redirected to Stripe'), false);
        $order->setStripePaymentIntentId($session->payment_intent ?? $session->id);
        $order->save();

        return $session->url;
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
            $this->stripeHelper->addToLog('error', $msg);
            return $msg;
        }

        $storeId = (int)$order->getStoreId();
        $stripe = $this->stripeHelper->getStripeClient($storeId);
        $sessionId = $order->getPayment()->getAdditionalInformation('stripe_checkout_session_id');

        if (empty($sessionId)) {
            $msg = ['error' => true, 'msg' => 'Checkout session ID not found'];
            $this->stripeHelper->addToLog('error', $msg);
            return $msg;
        }

        try {
            $session = $stripe->checkout->sessions->retrieve($sessionId, ['expand' => ['payment_intent']]);
        } catch (\Exception $e) {
            $this->stripeHelper->addToLog('error', Mage::helper('stripe')->__('Failed to retrieve checkout session: %s', $e->getMessage()));
            return ['error' => true, 'msg' => $e->getMessage()];
        }

        $this->stripeHelper->addToLog($type, [
            'session_id'     => $session->id,
            'status'         => $session->status,
            'payment_status' => $session->payment_status,
        ]);

        // Verify payment token on return (not webhook)
        if ($type !== 'webhook' && $paymentToken !== null) {
            $storedToken = $order->getPayment()->getAdditionalInformation('stripe_payment_token');
            if ($paymentToken !== $storedToken) {
                $this->stripeHelper->addToLog('error', 'Payment token mismatch');
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
                    $session->payment_intent->payment_method
                );
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
                            Mage::helper('stripe')->__('Unable to send order email: %s', $e->getMessage())
                        );
                    }
                }

                $statusProcessing = $this->stripeHelper->getStatusProcessing($storeId);
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
                            Mage::helper('stripe')->__('Unable to send invoice email: %s', $e->getMessage())
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
        $storeId = (int)$order->getStoreId();
        $piId = $order->getStripePaymentIntentId();

        if (empty($piId)) {
            $this->stripeHelper->addToLog('error', 'Cannot refund: Payment Intent ID not found');
            Mage::throwException(Mage::helper('stripe')->__('Cannot refund: Payment Intent ID not found'));
            return $this;
        }

        $stripe = $this->stripeHelper->getStripeClient($storeId);
        $currency = strtolower($order->getOrderCurrencyCode());
        $amountInCents = $this->stripeHelper->formatAmountForStripe((float)$amount, $currency);

        try {
            $refund = $stripe->refunds->create([
                'payment_intent' => $piId,
                'amount'         => $amountInCents,
            ]);
            $this->stripeHelper->addToLog('refund', [
                'id'     => $refund->id,
                'status' => $refund->status,
                'amount' => $amountInCents,
            ]);
        } catch (\Exception $e) {
            $this->stripeHelper->addToLog('error', $e->getMessage());
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
            return (int)$orderId;
        }

        $this->stripeHelper->addToLog(
            'error',
            Mage::helper('stripe')->__('No order found for payment intent ID %s', $paymentIntentId)
        );
        return false;
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
            $this->stripeHelper->addToLog('info', $order->getIncrementId() . ' ' . $comment);
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
            $status = $this->stripeHelper->getStatusPending((int)$order->getStoreId());
            $message = Mage::helper('stripe')->__('Order uncanceled by webhook.');
            $state = Mage_Sales_Model_Order::STATE_NEW;
            $order->setState($state, $status, $message, false)->save();

            foreach ($order->getAllItems() as $item) {
                $item->setQtyCanceled(0)->save();
            }
        } catch (\Exception $e) {
            $this->stripeHelper->addToLog('error', $e->getMessage());
        }

        return $order;
    }
}
