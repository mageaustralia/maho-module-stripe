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

class MageAustralia_Stripe_PaymentController extends Mage_Core_Controller_Front_Action
{
    public const REDIRECT_ERR_MSG = 'An error occurred while processing your payment request, please try again later.';
    public const RETURN_ERR_MSG = 'An error occurred while processing your payment, please try again with another method.';
    public const RETURN_CANCEL_MSG = 'Payment cancelled, please try again.';

    private ?MageAustralia_Stripe_Helper_Data $_stripeHelper = null;
    private ?MageAustralia_Stripe_Model_Stripe $_stripeModel = null;

    public function getStripeHelper(): MageAustralia_Stripe_Helper_Data
    {
        if ($this->_stripeHelper === null) {
            $this->_stripeHelper = Mage::helper('stripe');
        }
        return $this->_stripeHelper;
    }

    public function getStripeModel(): MageAustralia_Stripe_Model_Stripe
    {
        if ($this->_stripeModel === null) {
            $this->_stripeModel = Mage::getModel('stripe/stripe');
        }
        return $this->_stripeModel;
    }

    /**
     * Bypass CSRF form key validation for the webhook action only.
     * Stripe sends webhooks as POST requests without a Magento form key.
     */
    public function preDispatch(): static
    {
        if ($this->getRequest()->getActionName() === 'webhook') {
            $this->getRequest()->setParam('form_key', Mage::getSingleton('core/session')->getFormKey());
        }
        return parent::preDispatch();
    }

    /**
     * Redirect action: creates a Stripe Checkout session and redirects the customer
     */
    public function redirectAction(): void
    {
        try {
            $order = $this->getStripeHelper()->getOrderFromSession();

            if (!$order) {
                $this->getStripeHelper()->setError(self::REDIRECT_ERR_MSG);
                $this->getStripeHelper()->addToLog('error', 'Order not found in session.');
                $this->_redirect('checkout/cart');
                return;
            }

            $methodInstance = $order->getPayment()->getMethodInstance();
            $redirectUrl = $methodInstance->startTransaction($order);

            if ($redirectUrl) {
                $this->_redirectUrl($redirectUrl);
                return;
            } else {
                $this->getStripeHelper()->setError(self::REDIRECT_ERR_MSG);
                $error = sprintf('Missing redirect URL, increment ID: #%s', $order->getIncrementId());
                $this->getStripeHelper()->addToLog('error', $error);
                $this->getStripeHelper()->restoreCart();
                $this->_redirect('checkout/cart');
                return;
            }
        } catch (\Exception $e) {
            $this->getStripeHelper()->setError(self::REDIRECT_ERR_MSG);
            $this->getStripeHelper()->addToLog('error', $e->getMessage());
            $this->getStripeHelper()->restoreCart();
            $this->_redirect('checkout/cart');
            return;
        }
    }

    /**
     * Return action: customer returns from Stripe after payment
     */
    public function returnAction(): void
    {
        $orderId = $this->getRequest()->getParam('order_id');
        $paymentToken = $this->getRequest()->getParam('payment_token');

        if ($orderId === null) {
            $this->getStripeHelper()->setError(self::RETURN_ERR_MSG);
            $this->getStripeHelper()->addToLog('error', 'Invalid return, missing order_id param.');
            $this->_redirect('checkout/cart');
            return;
        }

        $orderId = (int)$orderId;

        try {
            $status = $this->getStripeModel()->processTransaction($orderId, 'return', $paymentToken);
        } catch (\Exception $e) {
            $this->getStripeHelper()->setError(self::RETURN_ERR_MSG);
            $this->getStripeHelper()->addToLog('error', $e->getMessage());
            $this->getStripeHelper()->restoreCart();
            $this->_redirect('checkout/cart');
            return;
        }

        if (!empty($status['success'])) {
            $this->_redirect('checkout/onepage/success', ['_query' => 'utm_nooverride=1']);
            return;
        } else {
            if (isset($status['status']) && $status['status'] === 'canceled') {
                $this->getStripeHelper()->setError(self::RETURN_CANCEL_MSG);
            } else {
                $this->getStripeHelper()->setError(self::RETURN_ERR_MSG);
            }

            $this->getStripeHelper()->restoreCart();
            $this->_redirect('checkout/cart');
            return;
        }
    }

    /**
     * Create a PaymentIntent for Stripe Elements inline checkout.
     * Returns JSON with clientSecret and paymentIntentId.
     */
    public function createPaymentIntentAction(): void
    {
        if (!$this->getRequest()->isPost()) {
            $this->_sendJson(['error' => true, 'message' => 'POST required'], 405);
            return;
        }

        // Verify integration mode is elements
        $integrationMode = $this->getStripeHelper()->getStoreConfig('payment/stripe_card/integration_mode');
        if ($integrationMode !== 'elements') {
            $this->_sendJson(['error' => true, 'message' => 'Elements mode not enabled'], 400);
            return;
        }

        // Get quote from checkout session
        $quote = Mage::getSingleton('checkout/session')->getQuote();
        if (!$quote || !$quote->getId() || !$quote->getItemsCount()) {
            $this->_sendJson(['error' => true, 'message' => 'No active cart found'], 400);
            return;
        }

        try {
            $result = $this->getStripeModel()->createPaymentIntent($quote);
            $this->_sendJson($result);
        } catch (\Exception $e) {
            $this->getStripeHelper()->addToLog('error', $e->getMessage());
            $this->_sendJson(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Webhook action: receives and processes Stripe webhook events
     *
     * CRITICAL: Signature verification is mandatory. Without it, anyone could
     * spoof webhook calls and mark orders as paid.
     */
    public function webhookAction(): void
    {
        if (!$this->getRequest()->isPost()) {
            $this->norouteAction();
            return;
        }

        $payload = file_get_contents('php://input');
        $sigHeader = $this->getRequest()->getServer('HTTP_STRIPE_SIGNATURE');

        if (empty($payload) || empty($sigHeader)) {
            $this->getStripeHelper()->addToLog('webhook', 'Empty payload or missing signature header');
            $this->getResponse()->setHttpResponseCode(400);
            return;
        }

        $webhookSecret = $this->getStripeHelper()->getWebhookSecret();
        if (empty($webhookSecret)) {
            $this->getStripeHelper()->addToLog('error', 'Webhook secret not configured');
            $this->getResponse()->setHttpResponseCode(500);
            return;
        }

        // Verify the webhook signature
        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (\UnexpectedValueException $e) {
            $this->getStripeHelper()->addToLog('error', Mage::helper('stripe')->__('Webhook payload parse error: %s', $e->getMessage()));
            $this->getResponse()->setHttpResponseCode(400);
            return;
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            $this->getStripeHelper()->addToLog('error', Mage::helper('stripe')->__('Webhook signature verification failed: %s', $e->getMessage()));
            $this->getResponse()->setHttpResponseCode(400);
            return;
        }

        $this->getStripeHelper()->addToLog('webhook', [
            'event_type' => $event->type,
            'event_id'   => $event->id,
        ]);

        try {
            $this->handleWebhookEvent($event);
            $this->getResponse()->setHttpResponseCode(200);
        } catch (\Exception $e) {
            $this->getStripeHelper()->addToLog('error', $e->getMessage());
            Mage::logException($e);
            $this->getResponse()->setHttpResponseCode(503);
        }
    }

    /**
     * Route a verified webhook event to the appropriate handler
     */
    private function handleWebhookEvent(\Stripe\Event $event): void
    {
        switch ($event->type) {
            case 'checkout.session.completed':
                $session = $event->data->object;
                $orderId = $session->metadata->order_id ?? null;
                if ($orderId) {
                    $this->getStripeModel()->processTransaction((int)$orderId, 'webhook');
                }
                break;

            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object;
                $orderId = $this->getStripeModel()->getOrderIdByPaymentIntentId($paymentIntent->id);
                if ($orderId) {
                    // Check if this was an Elements checkout — already processed by authorize()
                    $order = Mage::getModel('sales/order')->load($orderId);
                    $checkoutType = $order->getPayment()->getAdditionalInformation('checkout_type');
                    if ($checkoutType === 'elements') {
                        $this->getStripeHelper()->addToLog('webhook', Mage::helper('stripe')->__(
                            'Skipping PI webhook for elements order %s (already processed)',
                            $orderId
                        ));
                        break;
                    }
                    $this->getStripeModel()->processTransaction((int)$orderId, 'webhook');
                }
                break;

            case 'payment_intent.payment_failed':
                $paymentIntent = $event->data->object;
                $orderId = $this->getStripeModel()->getOrderIdByPaymentIntentId($paymentIntent->id);
                if ($orderId) {
                    $this->getStripeHelper()->addToLog('webhook', Mage::helper('stripe')->__('Payment failed for order %s', $orderId));
                    $this->getStripeModel()->processTransaction((int)$orderId, 'webhook');
                }
                break;

            case 'checkout.session.expired':
                $session = $event->data->object;
                $orderId = $session->metadata->order_id ?? null;
                if ($orderId) {
                    $this->getStripeHelper()->addToLog('webhook', Mage::helper('stripe')->__('Checkout session expired for order %s', $orderId));
                    $this->getStripeModel()->processTransaction((int)$orderId, 'webhook');
                }
                break;

            default:
                $this->getStripeHelper()->addToLog('webhook', Mage::helper('stripe')->__('Unhandled event type: %s', $event->type));
                break;
        }
    }

    private function _sendJson(array $data, int $httpCode = 200): void
    {
        $this->getResponse()
            ->setHttpResponseCode($httpCode)
            ->setHeader('Content-Type', 'application/json')
            ->setBody(json_encode($data));
    }
}
