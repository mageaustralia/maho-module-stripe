# MageAustralia_Stripe for Maho

[![CI](https://github.com/mageaustralia/maho-module-stripe/actions/workflows/ci.yml/badge.svg)](https://github.com/mageaustralia/maho-module-stripe/actions/workflows/ci.yml)
[![License: BSD-2-Clause](https://img.shields.io/badge/license-BSD--2--Clause-blue.svg)](LICENSE)

> **Alpha Release** -- This module is under active development and has not been extensively tested in production. Community feedback, bug reports, and pull requests are welcome.

Stripe payment gateway integration for [Maho](https://mahocommerce.com), the open-source e-commerce platform.

## Supported Payment Methods

| Method | Type | Regions |
|--------|------|---------|
| Credit / Debit Card | Stripe Elements (embedded) or Stripe Checkout | Global |
| Afterpay / Clearpay | Redirect via Stripe Checkout | Australia, Canada, NZ, US, UK |
| Klarna | Redirect via Stripe Checkout | Global |
| PayPal | Redirect via Stripe Checkout | Global |
| BECS Direct Debit (AU) | Redirect via Stripe Checkout | Australia |
| PayTo | Redirect via Stripe Checkout | Australia |
| iDEAL | Redirect via Stripe Checkout | Netherlands |
| Bancontact | Redirect via Stripe Checkout | Belgium |
| SEPA Direct Debit | Redirect via Stripe Checkout | Europe |
| Apple Pay | Stripe Elements / Payment Request Button | Global |
| Google Pay | Stripe Elements / Payment Request Button | Global |
| Stripe Link | Stripe Elements | Global |

## Requirements

- Maho 24.12 or later
- PHP 8.3 or later
- A Stripe account ([stripe.com](https://stripe.com))
- SSL certificate (required by Stripe for live transactions)

## Installation

```bash
composer require mageaustralia/maho-module-stripe
```

After installation, clear the Maho cache:

```bash
php maho cache:flush
```

## Configuration

1. Log in to the Maho admin panel.
2. Navigate to **System > Configuration > Payment Methods > Stripe Payment Settings**.
3. Enter your Stripe API keys (available from your [Stripe Dashboard](https://dashboard.stripe.com/apikeys)):
   - **Publishable Key** (starts with `pk_test_` or `pk_live_`)
   - **Secret Key** (starts with `sk_test_` or `sk_live_`)
4. Select your preferred **Mode** (`Test` or `Live`).
5. Enable the payment methods you want to offer.
6. Save the configuration.

### Webhook Setup

Webhooks allow Stripe to notify your store about payment events (successful charges, refunds, disputes, etc.).

1. Go to [Stripe Dashboard > Webhooks](https://dashboard.stripe.com/webhooks).
2. Click **Add endpoint**.
3. Set the endpoint URL to:
   ```
   https://yourdomain.com/stripe_payment/payment/webhook
   ```
4. Select the events to listen for (recommended):
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
   - `checkout.session.completed`
   - `charge.refunded`
   - `charge.dispute.created`
5. Copy the **Webhook Signing Secret** (`whsec_...`) and enter it in the Maho admin configuration.

## Integration Modes

### Stripe Checkout (Hosted Page)

The customer is redirected to a Stripe-hosted payment page. This is the simplest integration and supports all payment methods. After completing payment, the customer is redirected back to your store.

Best for: stores that want minimal frontend complexity, maximum payment method coverage.

### Stripe Elements (Embedded Form)

A card input field is embedded directly in your checkout page using Stripe Elements. The card details are collected in a Stripe-hosted iframe -- card numbers never touch your server.

Best for: stores that want a seamless checkout experience without leaving the site.

## Features

- **Multiple payment methods** -- Credit cards, Afterpay, Klarna, PayPal, BECS, PayTo, iDEAL, Bancontact, SEPA, Apple Pay, Google Pay, and Stripe Link
- **Stripe Checkout and Elements** -- Choose between hosted payment page or embedded card form
- **Automatic order status updates** -- Webhook-driven order state management
- **Refunds from admin** -- Process full and partial refunds directly from the Maho admin panel
- **Multi-currency support** -- Charges in the customer's selected currency
- **Admin API test button** -- Verify your API keys are working without leaving the configuration page
- **PCI-compliant** -- Card data is handled entirely by Stripe; no sensitive data stored on your server

## Security

- **PCI Compliance**: Card numbers are collected via Stripe Elements (iframe) or Stripe Checkout (hosted page). Your server never processes or stores raw card data.
- **Webhook Signature Verification**: All incoming webhook requests are verified against your webhook signing secret to prevent tampering.
- **Encrypted API Keys**: Secret keys are stored using Maho's built-in configuration encryption (`backend_model` = `adminhtml/system_config_backend_encrypted`).
- **CSRF Protection**: All admin AJAX requests include the `form_key` token.

## License

This module is licensed under the [BSD-2-Clause License](LICENSE).
