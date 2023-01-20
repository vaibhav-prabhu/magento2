<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Normal;

use PHPUnit\Framework\Constraint\StringContains;

class UpdateCartTest extends \PHPUnit\Framework\TestCase
{
    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($this);
        $this->paymentIntentModel = $this->objectManager->get(\StripeIntegration\Payments\Model\PaymentIntent::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testUpdateCart()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("DeclinedCard");

        $order = $this->quote->placeOrder();

        $this->expectExceptionMessage("Your card was declined.");
        $this->tests->confirm($order);

        // We change the items in the cart and the shipping address and expect that
        // the cached Payment Intent will also be updated when we retry placing the order
        $this->quote->addProduct('simple-product', 2)
            ->setShippingAddress("NewYork")
            ->setShippingMethod("FlatRate")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $this->tests->confirm($order);

        $paymentIntentId = $order->getPayment()->getLastTransId();
        $paymentIntent = $this->stripeConfig->getStripeClient()->paymentIntents->retrieve($paymentIntentId);

        $grandTotal = $order->getGrandTotal() * 100;
        $orderIncrementId = $order->getIncrementId();

        $this->compare->object($paymentIntent, [
            "amount" => $grandTotal,
            "currency" => "usd",
            "amount_received" => $grandTotal,
            "description" => "Order #$orderIncrementId by Joyce Strother",
            "charges" => [
                "data" => [
                    0 => [
                        "amount" => $grandTotal,
                        "amount_captured" => $grandTotal,
                        "amount_refunded" => 0,
                        "metadata" => [
                            "Order #" => $orderIncrementId
                        ]
                    ]
                ]
            ],
            "metadata" => [
                "Order #" => $orderIncrementId
            ],
            "shipping" => [
                "address" => [
                    "city" => "New York",
                    "country" => "US",
                    "line1" => "1255 Duncan Avenue",
                    "postal_code" => "10013",
                    "state" => "New York",
                ],
                "name" => "Flint Jerry",
                "phone" => "917-535-4022"
            ],
            "status" => "succeeded"
        ]);
    }
}
