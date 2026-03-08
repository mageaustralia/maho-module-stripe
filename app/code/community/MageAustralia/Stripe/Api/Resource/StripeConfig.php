<?php

declare(strict_types=1);

namespace MageAustralia\Stripe\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use MageAustralia\Stripe\Api\State\Provider\StripeConfigProvider;

#[ApiResource(
    shortName: 'StripeConfig',
    description: 'Stripe frontend configuration (publishable key)',
    provider: StripeConfigProvider::class,
    operations: [
        new Get(
            uriTemplate: '/payments/stripe/config',
            description: 'Get Stripe publishable key for frontend SDK initialization',
        ),
    ],
)]
class StripeConfig
{
    #[ApiProperty(identifier: true, writable: false)]
    public string $id = 'stripe';

    /** Stripe publishable key for Stripe.js initialization */
    #[ApiProperty(writable: false)]
    public ?string $publishableKey = null;

    /** Integration mode: 'elements' or 'checkout' */
    #[ApiProperty(writable: false)]
    public ?string $integrationMode = null;
}
