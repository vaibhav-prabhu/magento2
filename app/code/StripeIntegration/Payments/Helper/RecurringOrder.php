<?php

namespace StripeIntegration\Payments\Helper;

use StripeIntegration\Payments\Helper\Logger;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use StripeIntegration\Payments\Exception\SCANeededException;
use Magento\Framework\Exception\LocalizedException;
use StripeIntegration\Payments\Exception\WebhookException;

class RecurringOrder
{
    public $invoice = null;
    public $quoteManagement = null;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $paymentsHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Store\Model\Store $storeManager,
        \Magento\Quote\Model\QuoteManagement $quoteManagement,
        \Magento\Quote\Api\CartRepositoryInterface $cartRepositoryInterface,
        \Magento\Quote\Api\CartManagementInterface $cartManagementInterface,
        \Magento\Customer\Api\Data\CustomerInterfaceFactory $customerFactory,
        \Magento\Sales\Model\AdminOrder\Create $adminOrderCreateModel,
        \Magento\Quote\Api\ShipmentEstimationInterface $shipmentEstimation,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptions,
        \Magento\Quote\Model\Quote\Address\RateFactory $shippingRateFactory
    ) {
        $this->paymentsHelper = $paymentsHelper;
        $this->config = $config;
        $this->quoteFactory = $quoteFactory;
        $this->storeManager = $storeManager;
        $this->quoteManagement = $quoteManagement;
        $this->cartRepositoryInterface = $cartRepositoryInterface;
        $this->cartManagementInterface = $cartManagementInterface;
        $this->customerFactory = $customerFactory;
        $this->adminOrderCreateModel = $adminOrderCreateModel;
        $this->shipmentEstimation = $shipmentEstimation;
        $this->webhooksHelper = $webhooksHelper;
        $this->subscriptions = $subscriptions;
        $this->shippingRateFactory = $shippingRateFactory;
    }

    public function createFromSubscriptionItems($invoiceId)
    {
        $this->invoice = $invoice = $this->config->getStripeClient()->invoices->retrieve($invoiceId, [
            'expand' => [
                'lines.data.price.product',
                'subscription'
            ]
        ]);

        $orderIncrementId = $invoice->subscription->metadata["Order #"];
        if (empty($orderIncrementId))
            throw new WebhookException("Error: This subscription does not match a Magento Order #", 202);

        $originalOrder = $this->paymentsHelper->loadOrderByIncrementId($orderIncrementId);
        if (!$originalOrder->getId())
            throw new WebhookException("Error: Could not load original order #$orderIncrementId", 202);

        $invoiceDetails = $this->getInvoiceDetails($invoice, $originalOrder);

        $newOrder = $this->reOrder($originalOrder, $invoiceDetails);

        return $newOrder;
    }

    public function createFromInvoiceId($invoiceId)
    {
        $this->invoice = $invoice = \Stripe\Invoice::retrieve(['id' => $invoiceId, 'expand' => ['subscription']]);

        if (empty($invoice->subscription->metadata["Order #"]))
            throw new WebhookException("The subscription on invoice $invoiceId is not associated with a Magento order", 202);

        $orderIncrementId = $invoice->subscription->metadata["Order #"];

        if (empty($invoice->subscription->metadata["Product ID"]))
            return $this->createFromSubscriptionItems($invoiceId);

        $productId = $invoice->subscription->metadata["Product ID"];
        $originalOrder = $this->paymentsHelper->loadOrderByIncrementId($orderIncrementId);

        if (!$originalOrder->getId())
            throw new WebhookException("Error: Could not load original order #$orderIncrementId", 202);

        $invoiceDetails = $this->getInvoiceDetails($invoice, $originalOrder);

        $newOrder = $this->reOrder($originalOrder, $invoiceDetails);

        return $newOrder;
    }

    public function getInvoiceDetails($invoice, $order)
    {
        if (empty($invoice))
            throw new WebhookException("Error: Invalid subscription invoice.", 202);

        $subscription = $this->getSubscriptionFrom($invoice);
        $subscriptionAmount = $this->convertToMagentoAmount($subscription->amount / $subscription->quantity, $invoice->currency);
        $baseSubscriptionAmount = round($subscriptionAmount / $order->getBaseToOrderRate(), 2);

        $details = [
            "invoice_amount" => $this->convertToMagentoAmount($invoice->amount_paid, $invoice->currency),
            "stripe_invoice_amount" => $invoice->amount_paid,
            "base_invoice_amount" => round($this->convertToMagentoAmount($invoice->amount_paid, $invoice->currency) / $order->getBaseToOrderRate(), 2),
            "invoice_currency" => $invoice->currency,
            "invoice_tax_percent" => $invoice->tax_percent,
            "invoice_tax_amount" => $this->convertToMagentoAmount($invoice->tax, $invoice->currency),
            "subscription_amount" => $subscriptionAmount,
            "base_subscription_amount" => $baseSubscriptionAmount,
            "payment_intent" => $invoice->payment_intent,
            "shipping_amount" => 0,
            "base_shipping_amount" => 0,
            "shipping_currency" => null,
            "shipping_tax_percent" => 0,
            "shipping_tax_amount" => 0,
            "initial_fee_amount" => 0,
            "base_initial_fee_amount" => 0,
            "initial_fee_currency" => null,
            "initial_fee_tax_percent" => 0,
            "initial_fee_tax_amount" => 0,
            "discount_amount" => $this->getDiscountAmountFrom($invoice),
            "discount_coupon" => $order->getCouponCode(),
            "products" => [],
            "shipping_address" => [],
            "charge_id" => $invoice->charge,
            "are_subscriptions_billed_together" => false
        ];

        foreach ($invoice->lines->data as $invoiceLineItem)
        {
            $type = null;
            if (!empty($invoiceLineItem->price->product->metadata->{"Type"}))
                $type = $invoiceLineItem->price->product->metadata->{"Type"};

            if ($type == "Product")
            {
                $product = [];
                $product["id"] = $invoiceLineItem->price->product->metadata->{"Product ID"};
                if (!empty($invoiceLineItem->price->unit_amount))
                    $product["amount"] = $this->convertToMagentoAmount($invoiceLineItem->price->unit_amount, $invoiceLineItem->currency);
                else
                    $product["amount"] = $this->convertToMagentoAmount($invoiceLineItem->amount, $invoiceLineItem->currency);

                $product["qty"] = $invoiceLineItem->quantity;
                $product["currency"] = $invoiceLineItem->currency;
                $product["tax_percent"] = 0;
                $product["tax_amount"] = 0;

                if (isset($invoiceLineItem->tax_rates[0]->percentage))
                    $product["tax_percent"] = $invoiceLineItem->tax_rates[0]->percentage;

                if (isset($invoiceLineItem->tax_amounts[0]->amount))
                    $product["tax_amount"] = $this->convertToMagentoAmount($invoiceLineItem->tax_amounts[0]->amount, $invoiceLineItem->currency);

                $details["products"][$product["id"]] = $product;

                if (!empty($invoiceLineItem->metadata["Shipping Street"]))
                {
                    $details["shipping_address"] = [
                        'firstname' => $invoiceLineItem->metadata["Shipping First Name"],
                        'lastname' => $invoiceLineItem->metadata["Shipping Last Name"],
                        'company' => $invoiceLineItem->metadata["Shipping Company"],
                        'street' => $invoiceLineItem->metadata["Shipping Street"],
                        'city' => $invoiceLineItem->metadata["Shipping City"],
                        'postcode' => $invoiceLineItem->metadata["Shipping Postcode"],
                        'telephone' => $invoiceLineItem->metadata["Shipping Telephone"],
                    ];
                }

            }
            else if (!$type && isset($invoiceLineItem->metadata["Product ID"]))
            {
                $product = [];
                $product["id"] = $invoiceLineItem->metadata["Product ID"];
                if (!empty($invoiceLineItem->price->unit_amount))
                    $product["amount"] = $this->convertToMagentoAmount($invoiceLineItem->price->unit_amount, $invoiceLineItem->currency);
                else
                    $product["amount"] = $this->convertToMagentoAmount($invoiceLineItem->amount, $invoiceLineItem->currency);
                $product["qty"] = $invoiceLineItem->quantity;
                $product["currency"] = $invoiceLineItem->currency;
                $product["tax_percent"] = 0;
                $product["tax_amount"] = 0;

                if (isset($invoiceLineItem->tax_rates[0]->percentage))
                    $product["tax_percent"] = $invoiceLineItem->tax_rates[0]->percentage;

                if (isset($invoiceLineItem->tax_amounts[0]->amount))
                    $product["tax_amount"] = $this->convertToMagentoAmount($invoiceLineItem->tax_amounts[0]->amount, $invoiceLineItem->currency);

                $details["products"][$product["id"]] = $product;

                if (!empty($invoiceLineItem->metadata["Shipping Street"]))
                {
                    $details["shipping_address"] = [
                        'firstname' => $invoiceLineItem->metadata["Shipping First Name"],
                        'lastname' => $invoiceLineItem->metadata["Shipping Last Name"],
                        'company' => $invoiceLineItem->metadata["Shipping Company"],
                        'street' => $invoiceLineItem->metadata["Shipping Street"],
                        'city' => $invoiceLineItem->metadata["Shipping City"],
                        'postcode' => $invoiceLineItem->metadata["Shipping Postcode"],
                        'telephone' => $invoiceLineItem->metadata["Shipping Telephone"],
                    ];
                }
            }
            else if (!$type && isset($invoiceLineItem->metadata["SubscriptionProductIDs"]))
            {
                // Subscription created via PaymentElement in v3+
                $subscriptionProductIDs = explode(",", $invoiceLineItem->metadata->{"SubscriptionProductIDs"});
                $details["are_subscriptions_billed_together"] = true;

                $orderItems = $order->getAllItems();
                foreach ($orderItems as $orderItem)
                {
                    if (in_array($orderItem->getProductId(), $subscriptionProductIDs))
                    {
                        $product = $this->paymentsHelper->loadProductById($orderItem->getProductId());
                        $profile = $this->subscriptions->getSubscriptionDetails($product, $order, $orderItem);
                        $details["products"][$orderItem->getProductId()] = [
                            "id" => $orderItem->getProductId(),
                            "amount" => $profile['amount_magento'],
                            "base_amount" => $this->paymentsHelper->convertOrderAmountToBaseAmount($profile['amount_magento'], $profile['currency'], $order),
                            "qty" => $profile['qty'],
                            "currency" => $profile['currency'],
                            "tax_percent" => $profile['tax_percent'],
                            "tax_amount" => $profile['tax_amount_item'] + $profile['tax_amount_shipping']
                        ];
                    }
                }
            }
            // Can also be "Shipping cost" in older versions of the module
            else if ($type == "Shipping" || strpos($invoiceLineItem->description, "Shipping") === 0)
            {
                $details["shipping_amount"] = $this->convertToMagentoAmount($invoiceLineItem->amount, $invoiceLineItem->currency);
                $details["shipping_currency"] = $invoiceLineItem->currency;

                if (isset($invoiceLineItem->tax_rates[0]->percentage))
                    $details["shipping_tax_percent"] = $invoiceLineItem->tax_rates[0]->percentage;

                if (isset($invoiceLineItem->tax_amounts[0]->amount))
                    $details["shipping_tax_amount"] = $this->convertToMagentoAmount($invoiceLineItem->tax_amounts[0]->amount, $invoiceLineItem->currency);
            }
            else if ($type == "Initial fee" || stripos($invoiceLineItem->description, "Initial fee") === 0)
            {
                $details["initial_fee_amount"] = $this->convertToMagentoAmount($invoiceLineItem->amount, $invoiceLineItem->currency);
                $details["initial_fee_currency"] = $invoiceLineItem->currency;

                if (isset($invoiceLineItem->tax_rates[0]->percentage))
                    $details["initial_fee_tax_percent"] = $invoiceLineItem->tax_rates[0]->percentage;

                if (isset($invoiceLineItem->tax_amounts[0]->amount))
                    $details["initial_fee_tax_amount"] = $this->convertToMagentoAmount($invoiceLineItem->tax_amounts[0]->amount, $invoiceLineItem->currency);
            }
            else if ($type == "SubscriptionsTotal")
            {
                $subscriptionProductIDs = explode(",", $invoiceLineItem->price->product->metadata->{"SubscriptionProductIDs"});
                $details["are_subscriptions_billed_together"] = true;

                $orderItems = $order->getAllItems();
                foreach ($orderItems as $orderItem)
                {
                    if (in_array($orderItem->getProductId(), $subscriptionProductIDs))
                    {
                        $product = $this->paymentsHelper->loadProductById($orderItem->getProductId());
                        $profile = $this->subscriptions->getSubscriptionDetails($product, $order, $orderItem);
                        $details["products"][$orderItem->getProductId()] = [
                            "id" => $orderItem->getProductId(),
                            "amount" => $profile['amount_magento'],
                            "base_amount" => $this->paymentsHelper->convertOrderAmountToBaseAmount($profile['amount_magento'], $profile['currency'], $order),
                            "qty" => $profile['qty'],
                            "currency" => $profile['currency'],
                            "tax_percent" => $profile['tax_percent'],
                            "tax_amount" => $profile['tax_amount_item'] + $profile['tax_amount_shipping']
                        ];
                    }
                }
            }
            else
            {
                // As of v2.7.1, it is possible for an invoice to include an "Amount due" line item when a trial subscription activates
                // $this->webhooksHelper->log("Invoice {$invoice->id} includes an item which cannot be recognized as a subscription: " . $invoiceLineItem->description);
            }
        }

        if (empty($details["products"]))
            throw new WebhookException("This invoice does not have any product IDs associated with it", 202);

        if (empty($details["invoice_amount"]) && $details["invoice_amount"] !== 0) // Trial subcription invoices have an amount of 0
            throw new WebhookException("Could not determine the subscription amount from the invoice data", 202);

        $details["base_invoice_amount"] = round($details["invoice_amount"] * $order->getBaseToOrderRate(), 2);
        $details["base_shipping_amount"] = round($details["shipping_amount"] * $order->getBaseToOrderRate(), 2);
        $details["base_initial_fee_amount"] = round($details["initial_fee_amount"] * $order->getBaseToOrderRate(), 2);

        foreach ($details["products"] as &$product)
        {
            $product["base_amount"] = round($product["amount"] * $order->getBaseToOrderRate(), 2);
            $product["base_tax_amount"] = round($product["tax_amount"] * $order->getBaseToOrderRate(), 2);
        }

        return $details;
    }

    public function getDiscountAmountFrom($invoice)
    {
        if (empty($invoice->data->object->discount->coupon->amount_off))
            return 0;

        return $this->convertToMagentoAmount($invoice->data->object->discount->coupon->amount_off, $invoice->currency);
    }

    public function convertToMagentoAmount($amount, $currency)
    {
        $currency = strtolower($currency);
        $cents = 100;
        if ($this->paymentsHelper->isZeroDecimal($currency))
            $cents = 1;
        $amount = ($amount / $cents);
        return $amount;
    }

    public function reOrder($originalOrder, $invoiceDetails)
    {
        $quote = $this->createQuoteFrom($originalOrder);
        $this->setQuoteCustomerFrom($originalOrder, $quote);
        $this->setQuoteAddressesFrom($originalOrder, $quote);
        $this->setQuoteItemsFrom($originalOrder, $invoiceDetails, $quote);
        $this->setQuoteShippingMethodFrom($originalOrder, $quote);
        $this->setQuoteDiscountFrom($originalOrder, $quote);
        $this->setQuotePaymentMethodFrom($originalOrder, $quote);

        // Collect Totals & Save Quote
        $quote->setTotalsCollectedFlag(false)->collectTotals()->save();

        // Compensate for tax rounding algorithm differences between Stripe and Magento
        $this->paymentsHelper->setQuoteTaxFrom($invoiceDetails['stripe_invoice_amount'], $invoiceDetails['invoice_currency'], $quote);
        $quote->save();

        // Create Order From Quote
        $order = $this->quoteManagement->submit($quote);
        $this->addOrderCommentsTo($order, $originalOrder);
        $this->setTransactionDetailsFor($order, $invoiceDetails);
        $this->updatePaymentDetails($order, $invoiceDetails);

        return $order;
    }

    public function updatePaymentDetails($order, $invoiceDetails)
    {
        if (empty($invoiceDetails['charge_id']) || empty($invoiceDetails['payment_intent']))
            return;

        $stripe = $this->config->getStripeClient();
        $params = [
            'description' => "Recurring " . lcfirst($this->paymentsHelper->getOrderDescription($order)),
            'metadata' => [
                'Order #' => $order->getIncrementId()
            ]
        ];
        $stripe->charges->update($invoiceDetails['charge_id'], $params);
        $stripe->paymentIntents->update($invoiceDetails['payment_intent'], $params);

    }

    public function addOrderCommentsTo($order, $originalOrder)
    {
        $subscriptionId = $this->invoice->subscription->id;
        $orderIncrementId = $originalOrder->getIncrementId();
        $comment = "Recurring order generated from subscription with ID $subscriptionId. ";
        $comment .= "Customer originally subscribed with order #$orderIncrementId. ";
        $order->setEmailSent(0);
        $order->addStatusToHistory(false, $comment, false)->save();
    }

    public function setTransactionDetailsFor($order, $invoiceDetails)
    {
        $transactionId = $invoiceDetails["payment_intent"];

        $order->getPayment()
            ->setLastTransId($transactionId)
            ->setIsTransactionClosed(0);

        $this->paymentsHelper->addTransaction($order, $transactionId);
        $state = \Magento\Sales\Model\Order::STATE_PROCESSING;
        $status = $order->getConfig()->getStateDefaultStatus($state);
        $order->setState($state)->setStatus($status);
        $this->paymentsHelper->saveOrder($order);

        if ($order->canInvoice())
        {
            $this->paymentsHelper->invoiceSubscriptionOrder($order, $transactionId, \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
        }
        else
        {
            foreach($order->getInvoiceCollection() as $invoice)
                $invoice->setTransactionId($transactionId)->save();
        }
    }

    public function setQuoteDiscountFrom($originalOrder, &$quote)
    {
        if (!empty($originalOrder->getCouponCode()))
            $quote->setCouponCode($originalOrder->getCouponCode());
    }

    public function setQuotePaymentMethodFrom($originalOrder, &$quote, $data = [])
    {
        $quote->setPaymentMethod($originalOrder->getPayment()->getMethod());
        $quote->setInventoryProcessed(false);
        $quote->save(); // Needed before setting payment data
        $data = array_merge($data, ['method' => 'stripe_payments']); // We can only migrate subscriptions using the stripe_payments method

        if (empty($data['additional_data']))
            $data['additional_data'] = [];

        $data['additional_data']['is_recurring_subscription'] = true;

        $quote->setIsRecurringOrder(true);
        $quote->getPayment()->importData($data);
    }

    public function setQuoteShippingMethodFrom($originalOrder, &$quote)
    {
        if (!$originalOrder->getIsVirtual() && !$quote->getIsVirtual())
        {
            $availableMethods = $this->getAvaliableShippingMethodsFromQuote($quote);

            if (!in_array($originalOrder->getShippingMethod(), $availableMethods))
            {
                if (count($availableMethods) > 0)
                {
                    $msg = __("A Stripe subscription has been paid, but the shipping method '%1' from order #%2 is no longer available. We will use new shipping method '%3' to create a recurring subscription order.", $originalOrder->getShippingMethod(), $originalOrder->getIncrementId(), $availableMethods[0]);
                    $this->paymentsHelper->sendPaymentFailedEmail($quote, $msg);
                    $this->setQuoteShippingMethodByCode($quote, $availableMethods[0]);
                }
                else
                {
                    $msg = __("Could not create recurring subscription order. The shipping method '%1' from order #%2 is no longer available, and there are no alternative shipping methods to use.", $originalOrder->getShippingMethod(), $originalOrder->getIncrementId());
                    $this->paymentsHelper->sendPaymentFailedEmail($quote, $msg);
                    throw new WebhookException($msg);
                }
            }
            else
            {
                $this->setQuoteShippingMethodByCode($quote, $originalOrder->getShippingMethod());
            }
        }
    }

    public function setQuoteShippingMethodByCode($quote, $code)
    {
        $quote->getShippingAddress()
            ->setCollectShippingRates(true)
            ->collectShippingRates()
            ->setShippingMethod($code);

        $quote->setTotalsCollectedFlag(false)->collectTotals();
    }

    public function getAvaliableShippingMethodsFromQuote($quote)
    {
        $rates = [];
        $this->paymentsHelper->saveQuote($quote);
        $quoteId = $quote->getId();
        $methods = $this->shipmentEstimation->estimateByExtendedAddress($quote->getId(), $quote->getShippingAddress());
        foreach ($methods as $method)
        {
            $rate = $method->getCarrierCode() . '_' . $method->getMethodCode();
            $rates[] = $rate;
        }
        return $rates;
    }

    public function setQuoteItemsFrom($originalOrder, $invoiceDetails, &$quote)
    {
        foreach ($invoiceDetails['products'] as $productId => $product)
        {
            $productModel = $this->paymentsHelper->loadProductById($productId);
            $quoteItem = $quote->addProduct($productModel, $product['qty']);

            if (!empty($product['amount']) && $product['amount'] != $productModel->getPrice())
            {
                $quoteItem->setCustomPrice($product['amount']);
                $quoteItem->setOriginalCustomPrice($product['amount']);

                if (!empty($product['base_amount']))
                {
                    $quoteItem->setBaseCustomPrice($product['base_amount']);
                    $quoteItem->setBaseOriginalCustomPrice($product['base_amount']);
                }
            }
        }
    }

    public function setQuoteAddressesFrom($originalOrder, &$quote)
    {
        if ($originalOrder->getIsVirtual())
        {
            $data = $this->filterAddressData($originalOrder->getBillingAddress()->getData());
            $quote->getBillingAddress()->addData($data);
            $quote->setIsVirtual(true);
        }
        else
        {
            $data = $this->filterAddressData($originalOrder->getBillingAddress()->getData());
            $quote->getBillingAddress()->addData($data);

            $data = $this->filterAddressData($originalOrder->getShippingAddress()->getData());
            $quote->getShippingAddress()->addData($data);
        }
    }

    public function filterAddressData($data)
    {
        $allowed = ['prefix', 'firstname', 'middlename', 'lastname', 'email', 'suffix', 'company', 'street', 'city', 'country_id', 'region', 'region_id', 'postcode', 'telephone', 'fax', 'vat_id'];
        $remove = [];

        foreach ($data as $key => $value)
            if (!in_array($key, $allowed))
                $remove[] = $key;

        foreach ($remove as $key)
            unset($data[$key]);

        return $data;
    }

    public function createQuoteFrom($originalOrder)
    {
        $store = $this->storeManager->load($originalOrder->getStoreId());
        $store->setCurrentCurrencyCode($originalOrder->getOrderCurrencyCode());

        $quote = $this->quoteFactory->create();
        $quote->setStore($store);
        $quote->setStoreId($store->getId());
        $quote->setQuoteCurrencyCode($originalOrder->getOrderCurrencyCode());
        $quote->setCustomerEmail($originalOrder->getCustomerEmail());
        $quote->setIsRecurringOrder(true);

        return $quote;
    }

    public function setQuoteCustomerFrom($originalOrder, &$quote)
    {

        if ($originalOrder->getCustomerIsGuest())
        {
            $quote->setCustomerIsGuest(true);
        }
        else
        {
            $customer = $this->paymentsHelper->loadCustomerById($originalOrder->getCustomerId());
            $quote->assignCustomer($customer);
        }
    }

    public function getAddressDataFrom($address)
    {
        $data = array(
            'prefix' => $address->getPrefix(),
            'firstname' => $address->getFirstname(),
            'middlename' => $address->getMiddlename(),
            'lastname' => $address->getLastname(),
            'email' => $address->getEmail(),
            'suffix' => $address->getSuffix(),
            'company' => $address->getCompany(),
            'street' => $address->getStreet(),
            'city' => $address->getCity(),
            'country_id' => $address->getCountryId(),
            'region' => $address->getRegion(),
            'postcode' => $address->getPostcode(),
            'telephone' => $address->getTelephone(),
            'fax' => $address->getFax(),
            'vat_id' => $address->getVatId()
        );

        return $data;
    }

    public function isShippingLineItem($lineItem)
    {
        return isset($lineItem->price->product->metadata->{"Type"}) && $lineItem->price->product->metadata->{"Type"} == "Shipping";
    }

    public function getSubscriptionFrom($invoice)
    {
        foreach ($invoice->lines->data as $lineItem)
            if ($lineItem->type == "subscription" && !$this->isShippingLineItem($lineItem))
                return $lineItem;

        return null;
    }
}
