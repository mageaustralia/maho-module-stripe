<?php

declare(strict_types=1);

namespace MageAustralia\Stripe\Api\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use MageAustralia\Stripe\Api\Resource\StripeConfig;

/**
 * Provides Stripe frontend configuration (publishable key, integration mode).
 * This is public — only exposes non-secret config needed by Stripe.js.
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

        return $config;
    }
}
