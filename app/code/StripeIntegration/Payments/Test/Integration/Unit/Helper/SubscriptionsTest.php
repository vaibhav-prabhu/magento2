<?php

namespace StripeIntegration\Payments\Test\Integration\Unit\Helper;

class SubscriptionsTest extends \PHPUnit\Framework\TestCase
{
    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($this);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testGetSubscriptionDetails()
    {
        $subscriptionsHelper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Subscriptions::class);

        $this->quote->create()
            ->setCustomer('Guest')
            ->addProduct('configurable-subscription', 10, [["subscription" => "monthly"]])
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $quote = $this->quote->getQuote();

        foreach ($quote->getAllItems() as $quoteItem)
        {
            $this->assertNotEmpty($quoteItem->getProduct()->getId());
            $product = $this->tests->helper()->loadProductById($quoteItem->getProduct()->getId());
            if (!$product->getStripeSubEnabled())
                continue;

            $profile = $subscriptionsHelper->getSubscriptionDetails($product, $quote, $quoteItem);

            $this->tests->compare($profile, [
                "name" => "Configurable Subscription",
                "qty" => 10,
                "interval" => "month",
                "amount_magento" => 10,
                "amount_stripe" => 1000,
                "shipping_magento" => 50,
                "shipping_stripe" => 5000,
                "currency" => "usd",
                "tax_percent" => 8.25,
                "tax_percent_shipping" => 0,
                "tax_amount_item" => 8.25,
                "tax_amount_item_stripe" => 825
            ]);
        }
    }
}
