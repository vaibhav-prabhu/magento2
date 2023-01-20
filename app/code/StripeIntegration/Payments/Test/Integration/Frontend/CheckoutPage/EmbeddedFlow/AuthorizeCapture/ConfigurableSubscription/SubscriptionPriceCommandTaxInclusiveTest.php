<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\ConfigurableSubscription;

class SubscriptionPriceCommandTaxInclusiveTest extends \PHPUnit\Framework\TestCase
{
    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();

        $this->stockRegistry = $this->objectManager->get(\Magento\CatalogInventory\Model\StockRegistry::class);
        $this->productRepository = $this->objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($this);
        $this->subscriptionPriceCommand = $this->objectManager->get(\StripeIntegration\Payments\Setup\Migrate\SubscriptionPriceCommand::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     *
     * @magentoConfigFixture current_store customer/create_account/default_group 1
     * @magentoConfigFixture current_store customer/create_account/auto_group_assign 1
     * @magentoConfigFixture current_store tax/classes/shipping_tax_class 2
     * @magentoConfigFixture current_store tax/calculation/price_includes_tax 1
     * @magentoConfigFixture current_store tax/calculation/shipping_includes_tax 1
     * @magentoConfigFixture current_store tax/calculation/discount_tax 1
     */
    public function testTaxInclusiveSubscriptionMigration()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("ConfigurableSubscription")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $this->assertEquals(15, $order->getGrandTotal());
        $ordersCount = $this->tests->getOrdersCount();
        $paymentIntent = $this->tests->confirmSubscription($order);

        // Refresh the order
        $order = $this->tests->refreshOrder($order);

        // Stripe checks
        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->assertCount(1, $customer->subscriptions->data);
        $subscription = $customer->subscriptions->data[0];
        $magentoProduct = $this->tests->helper()->loadProductBySku("simple-monthly-subscription-product");
        $this->compare->object($subscription, [
            "items" => [
                "data" => [
                    0 => [
                        "plan" => [
                            "amount" => $order->getGrandTotal() * 100,
                            "currency" => "usd",
                            "interval" => "month",
                            "interval_count" => 1
                        ],
                        "price" => [
                            "recurring" => [
                                "interval" => "month",
                                "interval_count" => 1
                            ],
                            "unit_amount" => $order->getGrandTotal() * 100
                        ],
                        "quantity" => 1
                    ]
                ]
            ],
            "metadata" => [
                "Type" => "SubscriptionsTotal",
                "SubscriptionProductIDs" => $magentoProduct->getId(),
                "Order #" => $order->getIncrementId()
            ],
            "status" => "active"
        ]);
        $invoice = $this->tests->stripe()->invoices->retrieve($customer->subscriptions->data[0]->latest_invoice);
        $this->assertCount(1, $invoice->lines->data);
        $this->compare->object($invoice, [
            "amount_due" => $order->getGrandTotal() * 100,
            "amount_paid" => $order->getGrandTotal() * 100,
            "amount_remaining" => 0,
            "total" => $order->getGrandTotal() * 100
        ]);

        // Reset
        $this->helper->clearCache();

        // Change the subscription price
        $this->assertNotEmpty($customer->subscriptions->data[0]->metadata->{"SubscriptionProductIDs"});
        $productId = $customer->subscriptions->data[0]->metadata->{"SubscriptionProductIDs"};
        $product = $this->helper->loadProductById($productId);
        $productId = $product->getEntityId();
        $product->setPrice(15);
        $product = $this->tests->saveProduct($product);

        // Wait for Stripe objects to register

        // Migrate the existing subscription to the new price
        $inputFactory = $this->objectManager->get(\Symfony\Component\Console\Input\ArgvInputFactory::class);
        $input = $inputFactory->create([
            "argv" => [
                null,
                $productId,
                $productId,
                $order->getId(),
                $order->getId()
            ]
        ]);
        $output = $this->objectManager->get(\Symfony\Component\Console\Output\ConsoleOutput::class);

        sleep(2); // Wait for Stripe to update the subscription with a default_payment_method
        $exitCode = $this->subscriptionPriceCommand->run($input, $output);
        $this->assertEquals(0, $exitCode);

        // Order checks
        $newOrdersCount = $this->tests->getOrdersCount();

        $this->assertEquals($ordersCount + 1, $newOrdersCount);
        $newOrder = $this->tests->getLastOrder();

        $this->compare->object($newOrder->getData(), [
            // "state" => "closed",
            // "status" => "closed",
            "base_grand_total" => 20.0000,
            "base_shipping_amount" => 4.6200,
            // "base_shipping_invoiced" => 4.6200,
            // "base_shipping_refunded" => 4.6200,
            "base_shipping_tax_amount" => 0.3800,
            // "base_shipping_tax_refunded" => 0.3800,
            "base_subtotal" => 13.8600,
            // "base_subtotal_invoiced" => 13.8600,
            // "base_subtotal_refunded" => 13.8600,
            "base_tax_amount" => 1.5200,
            // "base_tax_invoiced" => 1.5200,
            // "base_tax_refunded" => 1.5200,
            // "base_total_invoiced" => 20.0000,
            // "base_total_offline_refunded" => 20.0000,
            // "base_total_paid" => 20.0000,
            // "base_total_refunded" => 20.0000,
            "grand_total" => 20.0000,
            "shipping_amount" => 4.6200,
            // "shipping_invoiced" => 4.6200,
            // "shipping_refunded" => 4.6200,
            "shipping_tax_amount" => 0.3800,
            // "shipping_tax_refunded" => 0.3800,
            "subtotal" => 13.8600,
            // "subtotal_invoiced" => 13.8600,
            // "subtotal_refunded" => 13.8600,
            "tax_amount" => 1.5200,
            // "tax_invoiced" => 1.5200,
            // "tax_refunded" => 1.5200,
            // "total_invoiced" => 20.0000,
            // "total_offline_refunded" => 20.0000,
            // "total_paid" => 20.0000,
            "total_qty_ordered" => 1.0000,
            // "total_refunded" => 20.0000,
            "email_sent" => 1,
            "base_subtotal_incl_tax" => 15.0000,
            "subtotal_incl_tax" => 15.0000,
            // "total_due" => 0.0000,
            "shipping_incl_tax" => 5.0000,
            "base_shipping_incl_tax" => 5.0000
        ]);

        // From version 3.0 onwards
        $this->compare->object($newOrder->getData(), [
            "state" => "canceled",
            "status" => "canceled",
            "base_total_paid" => 0,
            "total_invoiced" => 0,
            "total_paid" => 0,
            "total_due" => 20,
        ]);

        // Stripe checks
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->assertCount(1, $customer->subscriptions->data);

        $subscription = $customer->subscriptions->data[0];

        // Stripe checks
        $this->assertNotEmpty($subscription->latest_invoice);
        $invoice = $this->tests->stripe()->invoices->retrieve($subscription->latest_invoice);
        $this->compare->object($subscription, [
            "items" => [
                "data" => [
                    0 => [
                        "plan" => [
                            "amount" => "2000",
                            "currency" => "usd",
                            "interval" => "month",
                            "interval_count" => 1
                        ],
                        "price" => [
                            "recurring" => [
                                "interval" => "month",
                                "interval_count" => 1
                            ],
                            "unit_amount" => "2000"
                        ],
                        "quantity" => 1
                    ]
                ]
            ],
            "metadata" => [
                "Type" => "SubscriptionsTotal",
                "SubscriptionProductIDs" => $magentoProduct->getId(),
                "Order #" => $newOrder->getIncrementId()
            ],
            "status" => "trialing"
        ]);
        // All should be zero because it is a trial subscription
        $this->compare->object($invoice, [
            "amount_due" => 0,
            "amount_paid" => 0,
            "amount_remaining" => 0,
            "tax" => 0,
            "total" => 0
        ]);

        $upcomingInvoice = $this->tests->stripe()->invoices->upcoming(['customer' => $customer->id]);
        $this->compare->object($upcomingInvoice, [
            "total" => 2000
        ]);
    }
}
