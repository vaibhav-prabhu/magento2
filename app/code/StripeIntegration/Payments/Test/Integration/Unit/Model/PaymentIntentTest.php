<?php

namespace StripeIntegration\Payments\Test\Integration\Unit\Model;

use PHPUnit\Framework\Constraint\StringContains;

class PaymentIntentTest extends \PHPUnit\Framework\TestCase
{
    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->paymentIntentModel = $this->objectManager->get(\StripeIntegration\Payments\Model\PaymentIntent::class);
        $this->paymentElement = $this->objectManager->get(\StripeIntegration\Payments\Model\PaymentElement::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testCacheInvalidation()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California");

        // Create the payment intent
        $quote = $this->quote->getQuote();
        $clientSecret = $this->paymentElement->getClientSecret($quote->getId());
        $this->assertNotEmpty($clientSecret);

        // Check if it can be loaded from cache
        $params = $this->paymentIntentModel->getParamsFrom($quote);
        $paymentIntent = $this->paymentIntentModel->loadFromCache($params, $quote, null);
        $this->assertEquals($clientSecret, $paymentIntent->client_secret);
        $this->assertEquals(53.3, $quote->getGrandTotal());
        $this->assertEquals($quote->getGrandTotal() * 100, $paymentIntent->amount);

        // Load attempt 2
        $clientSecret = $this->paymentElement->getClientSecret($quote->getId());
        $this->assertEquals($clientSecret, $paymentIntent->client_secret);

        // Change the cart totals
        $this->quote
            ->setShippingAddress("NewYork")
            ->setShippingMethod("Best")
            ->setBillingAddress("NewYork");

        // Make sure that the payment intent was updated
        $quote = $this->quote->getQuote();
        $params = $this->paymentIntentModel->getParamsFrom($quote);
        $cachedPaymentIntent = $this->paymentIntentModel->loadFromCache($params, $quote, null);
        $this->assertNotEmpty($cachedPaymentIntent->id);
        $this->assertEquals(43.35, $quote->getGrandTotal());
        $this->assertEquals($quote->getGrandTotal() * 100, $cachedPaymentIntent->amount);

        $clientSecret = $this->paymentElement->getClientSecret($quote->getId());
        $this->assertNotEmpty($clientSecret);
        $this->assertEquals($clientSecret, $paymentIntent->client_secret);
    }
}
