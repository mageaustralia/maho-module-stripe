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

class MageAustralia_Stripe_Adminhtml_StripeController extends Mage_Adminhtml_Controller_Action
{
    /**
     * CSRF protection for state-changing actions
     */
    #[\Override]
    public function preDispatch()
    {
        $this->_setForcedFormKeyActions(['apiTest']);
        return parent::preDispatch();
    }

    /**
     * Test Stripe API connectivity
     */
    public function apiTestAction(): void
    {
        /** @var MageAustralia_Stripe_Helper_Data $helper */
        $helper = Mage::helper('stripe');
        $results = [];

        // Check PHP version
        if (version_compare(PHP_VERSION, '8.3', '>=')) {
            $results[] = '<span style="color:green;">' . Mage::helper('stripe')->__('PHP version: %s', PHP_VERSION) . '</span>';
        } else {
            $results[] = '<span style="color:red;">' . Mage::helper('stripe')->__('PHP version %s is below the required 8.3', PHP_VERSION) . '</span>';
        }

        // Check cURL extension
        if (extension_loaded('curl')) {
            $results[] = '<span style="color:green;">' . Mage::helper('stripe')->__('cURL extension: enabled') . '</span>';
        } else {
            $results[] = '<span style="color:red;">' . Mage::helper('stripe')->__('cURL extension: not enabled') . '</span>';
        }

        // Check JSON extension
        if (extension_loaded('json')) {
            $results[] = '<span style="color:green;">' . Mage::helper('stripe')->__('JSON extension: enabled') . '</span>';
        } else {
            $results[] = '<span style="color:red;">' . Mage::helper('stripe')->__('JSON extension: not enabled') . '</span>';
        }

        // Check Stripe PHP SDK
        if (class_exists('\Stripe\Stripe')) {
            $results[] = '<span style="color:green;">' . Mage::helper('stripe')->__('Stripe PHP SDK: installed (v%s)', \Stripe\Stripe::VERSION) . '</span>';
        } else {
            $results[] = '<span style="color:red;">' . Mage::helper('stripe')->__('Stripe PHP SDK: not installed. Run: composer require stripe/stripe-php') . '</span>';
        }

        // Test API key
        $secretKey = $helper->getSecretKey();
        if (empty($secretKey)) {
            $results[] = '<span style="color:red;">' . Mage::helper('stripe')->__('API Key: not configured') . '</span>';
        } else {
            try {
                $stripe = $helper->getStripeClient();
                $account = $stripe->accounts->retrieve('me');
                $accountId = $this->escapeHtml($account->id);
                $results[] = '<span style="color:green;">' . Mage::helper('stripe')->__('API Key: connected to account %s (%s mode)', $accountId, $helper->getMode()) . '</span>';
            } catch (\Exception $e) {
                $errorMsg = $this->escapeHtml($e->getMessage());
                $results[] = '<span style="color:red;">' . Mage::helper('stripe')->__('API Key error: %s', $errorMsg) . '</span>';
            }
        }

        // Webhook URL
        $results[] = Mage::helper('stripe')->__('Webhook URL: %s', $helper->getWebhookUrl());

        $this->getResponse()->setBody(implode('<br/>', $results));
    }

    /**
     * ACL check
     */
    #[\Override]
    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')->isAllowed('admin/system/config/stripe');
    }

    /**
     * Redirect to the external Stripe Dashboard.
     * Admin menu <action> tags cannot hold external URLs — this controller action bridges the gap.
     */
    public function dashboardAction(): void
    {
        $this->_redirectUrl('https://dashboard.stripe.com');
    }
}
