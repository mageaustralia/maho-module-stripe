<?php

declare(strict_types=1);

namespace MageAustralia\Stripe\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use MageAustralia\Stripe\Api\State\Processor\PaymentIntentProcessor;

#[ApiResource(
    shortName: 'StripePaymentIntent',
    description: 'Create a Stripe PaymentIntent for Elements inline checkout',
    processor: PaymentIntentProcessor::class,
    operations: [
        new Post(
            uriTemplate: '/payments/stripe/payment-intents',
            description: 'Create a PaymentIntent for the given cart',
            security: "true",
        ),
    ],
)]
class PaymentIntent
{
    #[ApiProperty(identifier: true, writable: false)]
    public ?string $id = null;

    /** Masked cart ID (input) */
    public ?string $cartId = null;

    /** Stripe client secret (output) */
    #[ApiProperty(writable: false)]
    public ?string $clientSecret = null;

    /** Stripe PaymentIntent ID (output) */
    #[ApiProperty(writable: false)]
    public ?string $paymentIntentId = null;
}
