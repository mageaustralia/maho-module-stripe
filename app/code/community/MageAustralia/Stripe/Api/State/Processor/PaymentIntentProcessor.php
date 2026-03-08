<?php

declare(strict_types=1);

namespace MageAustralia\Stripe\Api\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use MageAustralia\Stripe\Api\Resource\PaymentIntent;

/**
 * Creates a Stripe PaymentIntent for Elements inline checkout.
 * Accepts a masked cart ID, loads the quote, and returns the client secret.
 */
class PaymentIntentProcessor implements ProcessorInterface
{
    /**
     * @param PaymentIntent $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PaymentIntent
    {
        if (!$data->cartId) {
            throw new \InvalidArgumentException('cartId is required');
        }

        // Load quote from masked cart ID using the same method as GuestCartController
        $quote = $this->loadQuoteByMaskedId($data->cartId);
        if (!$quote || !$quote->getId()) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Cart not found');
        }

        if (!$quote->getItemsCount()) {
            throw new \InvalidArgumentException('Cart is empty');
        }

        // Verify Stripe Elements mode is enabled
        $integrationMode = \Mage::getStoreConfig('payment/stripe_card/integration_mode');
        if ($integrationMode !== 'elements') {
            throw new \InvalidArgumentException('Stripe Elements mode is not enabled');
        }

        // Create PaymentIntent via the Stripe model
        /** @var \MageAustralia_Stripe_Model_Stripe $stripeModel */
        $stripeModel = \Mage::getModel('stripe/stripe');
        $result = $stripeModel->createPaymentIntent($quote);

        $data->id = $result['paymentIntentId'];
        $data->clientSecret = $result['clientSecret'];
        $data->paymentIntentId = $result['paymentIntentId'];

        return $data;
    }

    private function loadQuoteByMaskedId(string $maskedId): ?\Mage_Sales_Model_Quote
    {
        // Validate masked ID format (32-char hex)
        if (!preg_match('/^[a-f0-9]{32}$/i', $maskedId)) {
            return null;
        }

        // Database lookup - same approach as CartService::getCartIdFromMaskedId
        $resource = \Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $quoteTable = $resource->getTableName('sales/quote');

        $quoteId = $read->fetchOne(
            $read->select()
                ->from($quoteTable, ['entity_id'])
                ->where('masked_quote_id = ?', $maskedId)
                ->where('is_active = ?', 1)
        );

        if (!$quoteId) {
            return null;
        }

        /** @var \Mage_Sales_Model_Quote $quote */
        $quote = \Mage::getModel('sales/quote')->loadByIdWithoutStore((int) $quoteId);
        if (!$quote->getId()) {
            return null;
        }

        // Set store context
        if ($quote->getStoreId()) {
            $quote->setStore(\Mage::app()->getStore($quote->getStoreId()));
        }

        return $quote;
    }
}
