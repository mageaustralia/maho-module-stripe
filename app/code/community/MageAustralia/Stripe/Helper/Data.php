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

class MageAustralia_Stripe_Helper_Data extends Mage_Core_Helper_Abstract
{
    const XPATH_MODULE_ACTIVE = 'payment/stripe/active';
    const XPATH_MODE = 'payment/stripe/mode';
    const XPATH_SECRET_KEY_TEST = 'payment/stripe/secret_key_test';
    const XPATH_SECRET_KEY_LIVE = 'payment/stripe/secret_key_live';
    const XPATH_PUBLISHABLE_KEY_TEST = 'payment/stripe/publishable_key_test';
    const XPATH_PUBLISHABLE_KEY_LIVE = 'payment/stripe/publishable_key_live';
    const XPATH_WEBHOOK_SECRET_TEST = 'payment/stripe/webhook_secret_test';
    const XPATH_WEBHOOK_SECRET_LIVE = 'payment/stripe/webhook_secret_live';
    const XPATH_DEBUG = 'payment/stripe/debug';
    const XPATH_STATUS_PENDING = 'payment/stripe/order_status_pending';
    const XPATH_STATUS_PROCESSING = 'payment/stripe/order_status_processing';

    /**
     * Zero-decimal currencies where amounts are NOT multiplied by 100
     */
    const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * Method code to Stripe payment_method_type mapping
     */
    const METHOD_TYPE_MAP = [
        'stripe_card'       => 'card',
        'stripe_ideal'      => 'ideal',
        'stripe_bancontact' => 'bancontact',
        'stripe_sepa'       => 'sepa_debit',
        'stripe_klarna'     => 'klarna',
        'stripe_paypal'     => 'paypal',
        'stripe_applepay'   => 'card',
        'stripe_googlepay'  => 'card',
        'stripe_link'       => 'link',
    ];

    private ?bool $debug = null;
    private ?string $mode = null;
    private array $secretKeys = [];
    private ?\Stripe\StripeClient $stripeClient = null;

    /**
     * Check if the module is available for a given store
     */
    public function isAvailable(?int $storeId = null): bool
    {
        $active = $this->getStoreConfig(self::XPATH_MODULE_ACTIVE, $storeId);
        if (!$active) {
            return false;
        }

        $secretKey = $this->getSecretKey($storeId);
        if (empty($secretKey)) {
            return false;
        }

        return true;
    }

    /**
     * Get current mode (test or live)
     */
    public function getMode(?int $storeId = null): string
    {
        if ($this->mode === null) {
            $this->mode = (string)$this->getStoreConfig(self::XPATH_MODE, $storeId);
        }

        return $this->mode ?: 'test';
    }

    /**
     * Wrapper around Mage::getStoreConfig
     */
    public function getStoreConfig(string $path, ?int $storeId = null): ?string
    {
        if ($storeId !== null) {
            $value = Mage::getStoreConfig($path, $storeId);
        } else {
            $value = Mage::getStoreConfig($path);
        }

        return $value !== null ? trim((string)$value) : null;
    }

    /**
     * Get the Stripe secret key for the current mode, decrypted and validated
     */
    public function getSecretKey(?int $storeId = null): string
    {
        $cacheKey = (string)$storeId;
        if (array_key_exists($cacheKey, $this->secretKeys)) {
            return $this->secretKeys[$cacheKey];
        }

        $mode = $this->getMode($storeId);
        $xpath = ($mode === 'live') ? self::XPATH_SECRET_KEY_LIVE : self::XPATH_SECRET_KEY_TEST;

        $key = trim((string)Mage::helper('core')->decrypt((string)$this->getStoreConfig($xpath, $storeId)));

        if (empty($key)) {
            $this->addToLog('error', Mage::helper('stripe')->__('Stripe secret key not set (%s mode)', $mode));
            $this->secretKeys[$cacheKey] = '';
            return '';
        }

        $expectedPrefix = ($mode === 'live') ? 'sk_live_' : 'sk_test_';
        if (strpos($key, $expectedPrefix) !== 0) {
            $this->addToLog(
                'error',
                Mage::helper('stripe')->__('Stripe set to %s mode, but secret key does not start with "%s"', $mode, $expectedPrefix)
            );
        }

        $this->secretKeys[$cacheKey] = $key;
        return $key;
    }

    /**
     * Get the Stripe publishable key for the current mode, decrypted and validated
     */
    public function getPublishableKey(?int $storeId = null): string
    {
        $mode = $this->getMode($storeId);
        $xpath = ($mode === 'live') ? self::XPATH_PUBLISHABLE_KEY_LIVE : self::XPATH_PUBLISHABLE_KEY_TEST;

        $key = trim((string)Mage::helper('core')->decrypt((string)$this->getStoreConfig($xpath, $storeId)));

        if (empty($key)) {
            $this->addToLog('error', Mage::helper('stripe')->__('Stripe publishable key not set (%s mode)', $mode));
            return '';
        }

        $expectedPrefix = ($mode === 'live') ? 'pk_live_' : 'pk_test_';
        if (strpos($key, $expectedPrefix) !== 0) {
            $this->addToLog(
                'error',
                Mage::helper('stripe')->__('Stripe set to %s mode, but publishable key does not start with "%s"', $mode, $expectedPrefix)
            );
        }

        return $key;
    }

    /**
     * Get the Stripe webhook signing secret for the current mode, decrypted and validated
     */
    public function getWebhookSecret(?int $storeId = null): string
    {
        $mode = $this->getMode($storeId);
        $xpath = ($mode === 'live') ? self::XPATH_WEBHOOK_SECRET_LIVE : self::XPATH_WEBHOOK_SECRET_TEST;

        $key = trim((string)Mage::helper('core')->decrypt((string)$this->getStoreConfig($xpath, $storeId)));

        if (empty($key)) {
            $this->addToLog('error', Mage::helper('stripe')->__('Stripe webhook secret not set (%s mode)', $mode));
            return '';
        }

        if (strpos($key, 'whsec_') !== 0) {
            $this->addToLog(
                'error',
                Mage::helper('stripe')->__('Stripe webhook secret does not start with "whsec_"')
            );
        }

        return $key;
    }

    /**
     * Create and cache a Stripe API client instance
     */
    public function getStripeClient(?int $storeId = null): \Stripe\StripeClient
    {
        if ($this->stripeClient !== null) {
            return $this->stripeClient;
        }

        $secretKey = $this->getSecretKey($storeId);

        \Stripe\Stripe::setAppInfo('MageAustralia_Stripe', '1.0.0', 'https://mageaustralia.com.au');

        $this->stripeClient = new \Stripe\StripeClient($secretKey);

        return $this->stripeClient;
    }

    /**
     * Build the return URL for after Stripe payment
     */
    public function getReturnUrl(int $orderId, string $paymentToken, ?int $storeId = null): string
    {
        $params = [
            'order_id'      => $orderId,
            'payment_token' => $paymentToken,
            '_secure'       => true,
        ];

        if ($storeId !== null) {
            $params['_store'] = $storeId;
        }

        return Mage::getUrl('stripe_payment/payment/return', $params);
    }

    /**
     * Build the webhook URL for Stripe event notifications
     */
    public function getWebhookUrl(?int $storeId = null): string
    {
        $params = ['_secure' => true];

        if ($storeId !== null) {
            $params['_store'] = $storeId;
        }

        return Mage::getUrl('stripe_payment/payment/webhook', $params);
    }

    /**
     * Get the configured pending order status
     */
    public function getStatusPending(?int $storeId = null): string
    {
        return (string)$this->getStoreConfig(self::XPATH_STATUS_PENDING, $storeId);
    }

    /**
     * Get the configured processing order status
     */
    public function getStatusProcessing(?int $storeId = null): string
    {
        return (string)$this->getStoreConfig(self::XPATH_STATUS_PROCESSING, $storeId);
    }

    /**
     * Convert a decimal amount to Stripe's integer format
     * Zero-decimal currencies are not multiplied by 100
     */
    public function formatAmountForStripe(float $amount, string $currency): int
    {
        $currency = strtoupper($currency);

        if (in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (int)round($amount);
        }

        return (int)round($amount * 100);
    }

    /**
     * Convert a Stripe integer amount back to decimal
     */
    public function formatAmountFromStripe(int $amount, string $currency): float
    {
        $currency = strtoupper($currency);

        if (in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (float)$amount;
        }

        return (float)($amount / 100);
    }

    /**
     * Generate a secure random payment token
     */
    public function getPaymentToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Log data to stripe.log if debug mode is enabled
     */
    public function addToLog(string $type, mixed $data): void
    {
        if ($this->debug === null) {
            $this->debug = (bool)$this->getStoreConfig(self::XPATH_DEBUG);
        }

        if ($this->debug) {
            if (is_array($data) || is_object($data)) {
                $log = $type . ': ' . json_encode($data);
            } else {
                $log = $type . ': ' . $data;
            }

            Mage::log($log, null, 'stripe.log');
        }
    }

    /**
     * Get the last order from the checkout session
     */
    public function getOrderFromSession(): ?Mage_Sales_Model_Order
    {
        $orderId = Mage::getSingleton('checkout/session')->getLastOrderId();
        if (!empty($orderId)) {
            /** @var Mage_Sales_Model_Order $order */
            $order = Mage::getModel('sales/order')->load($orderId);
            if ($order->getId()) {
                return $order;
            }
        }

        return null;
    }

    /**
     * Reactivate the quote from the checkout session so the cart is restored
     */
    public function restoreCart(): void
    {
        $orderId = Mage::getSingleton('checkout/session')->getLastOrderId();
        if (!empty($orderId)) {
            /** @var Mage_Sales_Model_Order $order */
            $order = Mage::getModel('sales/order')->load($orderId);
            $quoteId = $order->getQuoteId();
            $quote = Mage::getModel('sales/quote')->load($quoteId);
            $quote->setIsActive(true)->save();
            Mage::getSingleton('checkout/session')->replaceQuote($quote);
        }
    }

    /**
     * Set an error message on the core session
     */
    public function setError(string $message): void
    {
        $msg = Mage::helper('stripe')->__($message);
        Mage::getSingleton('core/session')->addError($msg);
    }

    /**
     * Map an internal method code to a Stripe payment_method_type
     */
    public function getMethodStripeType(string $methodCode): string
    {
        return self::METHOD_TYPE_MAP[$methodCode] ?? 'card';
    }
}
