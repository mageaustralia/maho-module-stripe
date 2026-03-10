<?php

declare(strict_types=1);

namespace MageAustralia\Stripe\Api\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use MageAustralia\Stripe\Api\Resource\StripeConfig;

/**
 * Provides Stripe frontend configuration (publishable key, integration mode).
 * Public endpoint — only exposes non-secret config needed by Stripe.js.
 * When X-Storefront-Sync header matches the sync secret, also includes the secret key
 * for server-side PaymentIntent creation by the storefront worker.
 */
class StripeConfigProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StripeConfig
    {
        /** @var \MageAustralia_Stripe_Helper_Data $helper */
        $helper = \Mage::helper('stripe');

        $config = new StripeConfig();
        $config->publishableKey = $helper->getPublishableKey();
        $config->integrationMode = \Mage::getStoreConfig('payment/stripe_card/integration_mode') ?: 'checkout';

        // Include secret key only for authenticated storefront sync requests
        $request = \Mage::app()->getRequest();
        $syncHeader = $request->getHeader('X-Storefront-Sync');
        if ($syncHeader) {
            $syncSecret = \Mage::getStoreConfig('mageaustralia_storefront/worker/sync_secret');
            if ($syncSecret && hash_equals($syncSecret, $syncHeader)) {
                $config->secretKey = $helper->getSecretKey();
            }
        }

        return $config;
    }
}
