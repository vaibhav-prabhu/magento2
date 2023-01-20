<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\RedirectFlow\AuthorizeCapture\Normal;

class AvailabilityTest extends \PHPUnit\Framework\TestCase
{
    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     * @dataProvider EUMethodProvider
     */
    public function testEUMethodAvailability($cartType, $billingAddress, $shippingAddress, $supportedMethods)
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart($cartType)
            ->setShippingMethod("FlatRate")
            ->setBillingAddress($billingAddress)
            ->setShippingAddress($shippingAddress)
            ->setPaymentMethod("StripeCheckout");

        $methods = $this->quote->getAvailablePaymentMethods();

        foreach ($supportedMethods as $method)
        {
            $this->assertContains($method, $methods, "$method is not available");
        }

        $this->assertCount(count($supportedMethods), $methods);
    }

    public function EUMethodProvider()
    {
        return [
            [
                "cartType" => "Normal",
                "billingAddress" => "Berlin",
                "shippingAddress" => "Berlin",
                "supportedMethods" => [ "card", "bancontact", "eps", "giropay", "ideal", "p24", "sofort", "sepa_debit", "alipay", /* sepa_credit_transfers, paypal */ ]
            ]
        ];
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow GBP,USD
     * @magentoConfigFixture current_store currency/options/default GBP
     *
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysUK.php
     * @dataProvider UKMethodProvider
     */
    public function testUKMethodAvailability($cartType, $billingAddress, $shippingAddress, $supportedMethods)
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart($cartType)
            ->setShippingMethod("FlatRate")
            ->setBillingAddress($billingAddress)
            ->setShippingAddress($shippingAddress)
            ->setPaymentMethod("StripeCheckout");

        $methods = $this->quote->getAvailablePaymentMethods();

        foreach ($supportedMethods as $method)
        {
            $this->assertContains($method, $methods, "$method is not available");
        }

        $this->assertCount(count($supportedMethods), $methods);
    }
    public function UKMethodProvider()
    {
        return [
            [
                "cartType" => "Normal",
                "billingAddress" => "London",
                "shippingAddress" => "London",
                "supportedMethods" => [ "card", "afterpay_clearpay", "bacs_debit", "alipay", "klarna", "wechat_pay" ]
            ]
        ];
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow MYR,USD
     * @magentoConfigFixture current_store currency/options/default MYR
     *
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysMY.php
     * @dataProvider MYMethodProvider
     */
    public function testMYMethodAvailability($cartType, $billingAddress, $shippingAddress, $supportedMethods)
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart($cartType)
            ->setShippingMethod("FlatRate")
            ->setBillingAddress($billingAddress)
            ->setShippingAddress($shippingAddress)
            ->setPaymentMethod("StripeCheckout");

        $methods = $this->quote->getAvailablePaymentMethods();

        foreach ($supportedMethods as $method)
        {
            $this->assertContains($method, $methods, "$method is not available");
        }

        $this->assertCount(count($supportedMethods), $methods);
    }
    public function MYMethodProvider()
    {
        return [
            [
                "cartType" => "Normal",
                "billingAddress" => "Malaysia",
                "shippingAddress" => "Malaysia",
                "supportedMethods" => [ "card", "fpx", "grabpay" ]
            ]
        ];
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow MXN,USD
     * @magentoConfigFixture current_store currency/options/default MXN
     *
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysMX.php
     * @dataProvider MXMethodProvider
     */
    public function testMXMethodAvailability($cartType, $billingAddress, $shippingAddress, $supportedMethods)
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart($cartType)
            ->setShippingMethod("FlatRate")
            ->setBillingAddress($billingAddress)
            ->setShippingAddress($shippingAddress)
            ->setPaymentMethod("StripeCheckout");

        $methods = $this->quote->getAvailablePaymentMethods();

        foreach ($supportedMethods as $method)
        {
            $this->assertContains($method, $methods, "$method is not available");
        }

        $this->assertCount(count($supportedMethods), $methods);
    }
    public function MXMethodProvider()
    {
        return [
            [
                "cartType" => "Normal",
                "billingAddress" => "Mexico",
                "shippingAddress" => "Mexico",
                "supportedMethods" => [ "card", "oxxo" ]
            ]
        ];
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow BRL,USD
     * @magentoConfigFixture current_store currency/options/default BRL
     *
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysBR.php
     * @dataProvider BRMethodProvider
     */
    public function testBRMethodAvailability($cartType, $billingAddress, $shippingAddress, $supportedMethods)
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart($cartType)
            ->setShippingMethod("FlatRate")
            ->setBillingAddress($billingAddress)
            ->setShippingAddress($shippingAddress)
            ->setPaymentMethod("StripeCheckout");

        $methods = $this->quote->getAvailablePaymentMethods();

        foreach ($supportedMethods as $method)
        {
            $this->assertContains($method, $methods, "$method is not available");
        }

        $this->assertCount(count($supportedMethods), $methods);
    }
    public function BRMethodProvider()
    {
        return [
            [
                "cartType" => "Normal",
                "billingAddress" => "Brazil",
                "shippingAddress" => "Brazil",
                "supportedMethods" => [ "card", "boleto" ]
            ]
        ];
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow CAD,USD
     * @magentoConfigFixture current_store currency/options/default CAD
     *
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysCA.php
     * @dataProvider CAMethodProvider
     */
    public function testCAMethodAvailability($cartType, $billingAddress, $shippingAddress, $supportedMethods)
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart($cartType)
            ->setShippingMethod("FlatRate")
            ->setBillingAddress($billingAddress)
            ->setShippingAddress($shippingAddress)
            ->setPaymentMethod("StripeCheckout");

        $methods = $this->quote->getAvailablePaymentMethods();

        foreach ($supportedMethods as $method)
        {
            $this->assertContains($method, $methods, "$method is not available");
        }

        $this->assertCount(count($supportedMethods), $methods);
    }
    public function CAMethodProvider()
    {
        return [
            [
                "cartType" => "Normal",
                "billingAddress" => "Canada",
                "shippingAddress" => "Canada",
                "supportedMethods" => [ "card", "acss_debit" ]
            ]
        ];
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow AUD,USD
     * @magentoConfigFixture current_store currency/options/default AUD
     *
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysAU.php
     * @dataProvider AUMethodProvider
     */
    public function testAUMethodAvailability($cartType, $billingAddress, $shippingAddress, $supportedMethods)
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart($cartType)
            ->setShippingMethod("FlatRate")
            ->setBillingAddress($billingAddress)
            ->setShippingAddress($shippingAddress)
            ->setPaymentMethod("StripeCheckout");

        $methods = $this->quote->getAvailablePaymentMethods();

        foreach ($supportedMethods as $method)
        {
            $this->assertContains($method, $methods, "$method is not available");
        }

        $this->assertCount(count($supportedMethods), $methods);
    }
    public function AUMethodProvider()
    {
        return [
            [
                "cartType" => "Normal",
                "billingAddress" => "Australia",
                "shippingAddress" => "Australia",
                "supportedMethods" => [ "card", "au_becs_debit" ]
            ]
        ];
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow USD,USD
     * @magentoConfigFixture current_store currency/options/default USD
     *
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysUS.php
     * @dataProvider USMethodProvider
     */
    public function testUSMethodAvailability($cartType, $billingAddress, $shippingAddress, $supportedMethods)
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart($cartType)
            ->setShippingMethod("FlatRate")
            ->setBillingAddress($billingAddress)
            ->setShippingAddress($shippingAddress)
            ->setPaymentMethod("StripeCheckout");

        $methods = $this->quote->getAvailablePaymentMethods();

        foreach ($supportedMethods as $method)
        {
            $this->assertContains($method, $methods, "$method is not available");
        }

        $this->assertCount(count($supportedMethods), $methods);
    }
    public function USMethodProvider()
    {
        return [
            [
                "cartType" => "Normal",
                "billingAddress" => "California",
                "shippingAddress" => "California",
                "supportedMethods" => [ "card" ]
            ]
        ];
    }
}
