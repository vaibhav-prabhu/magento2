<?php

namespace StripeIntegration\Payments\Helper;

use StripeIntegration\Payments\Helper\Logger;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use StripeIntegration\Payments\Exception\SCANeededException;
use StripeIntegration\Payments\Exception\CacheInvalidationException;
use StripeIntegration\Payments\Exception\InvalidSubscriptionProduct;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\CouldNotSaveException;


class Subscriptions
{
    public $couponCodes = [];
    public $subscriptions = [];
    public $invoices = [];
    public $paymentIntents = [];
    public $trialingSubscriptionsAmounts = null;
    public $shippingTaxPercent = null;

    public function __construct(
        \StripeIntegration\Payments\Helper\Rollback $rollback,
        \StripeIntegration\Payments\Helper\Generic $paymentsHelper,
        \StripeIntegration\Payments\Helper\Compare $compare,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\SubscriptionProductFactory $subscriptionProductFactory,
        \StripeIntegration\Payments\Model\ResourceModel\Coupon\Collection $couponCollection,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Tax\Model\Sales\Order\TaxManagement $taxManagement,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \StripeIntegration\Payments\Model\SubscriptionFactory $subscriptionFactory,
        \Magento\SalesRule\Model\CouponFactory $couponFactory,
        \StripeIntegration\Payments\Model\CouponFactory $stripeCouponFactory,
        \StripeIntegration\Payments\Helper\TaxHelper $taxHelper
    ) {
        $this->rollback = $rollback;
        $this->paymentsHelper = $paymentsHelper;
        $this->compare = $compare;
        $this->addressHelper = $addressHelper;
        $this->config = $config;
        $this->subscriptionProductFactory = $subscriptionProductFactory;
        $this->couponCollection = $couponCollection;
        $this->priceCurrency = $priceCurrency;
        $this->eventManager = $eventManager;
        $this->customer = $paymentsHelper->getCustomerModel();
        $this->cache = $cache;
        $this->taxManagement = $taxManagement;
        $this->invoiceService = $invoiceService;
        $this->quoteRepository = $quoteRepository;
        $this->subscriptionFactory = $subscriptionFactory;
        $this->couponFactory = $couponFactory;
        $this->stripeCouponFactory = $stripeCouponFactory;
        $this->taxHelper = $taxHelper;
    }

    public function getSubscriptionExpandParams()
    {
        return ['latest_invoice.payment_intent', 'pending_setup_intent'];
    }

    public function getSubscriptionParamsFromQuote($quote, $paymentIntentParams, $order = null)
    {
        if (!$this->paymentsHelper->isSubscriptionsEnabled())
            return null;

        $subscriptions = $this->getSubscriptionsFromQuote($quote);
        $subscriptionItems = $this->getSubscriptionItemsFromQuote($quote, $subscriptions, $order);

        if (empty($subscriptionItems))
            return null;

        $stripeCustomer = $this->customer->createStripeCustomerIfNotExists();
        $this->customer->save();

        if (!$stripeCustomer)
            throw new \Exception("Could not create customer in Stripe.");

        $metadata = $subscriptionItems[0]['metadata']; // There is only one item for the entire order

        $params = [
            'customer' => $stripeCustomer->id,
            'items' => $subscriptionItems,
            'payment_behavior' => 'default_incomplete',
            'expand' => $this->getSubscriptionExpandParams(),
            'metadata' => $metadata
        ];

        $couponId = $this->getCouponId($subscriptions);
        if ($couponId)
            $params['coupon'] = $couponId;

        if ($paymentIntentParams['amount'] > 0)
        {
            $stripeDiscountAdjustment = $this->getStripeDiscountAdjustment($subscriptions);
            $normalPrice = $this->createPriceForOneTimePayment($quote, $paymentIntentParams, $stripeDiscountAdjustment);
            $params['add_invoice_items'] = [[
                "price" => $normalPrice->id,
                "quantity" => 1
            ]];
        }

        foreach ($quote->getAllItems() as $quoteItem)
        {
            try
            {
                $product = $this->subscriptionProductFactory->create()->initFromQuoteItem($quoteItem);
                if ($product->hasTrialPeriod())
                {
                    $params["trial_end"] = $product->getTrialEnd();
                    break;
                }
            }
            catch (InvalidSubscriptionProduct $e)
            {
                // Ignore non subscription products
            }

        }

        // Overwrite trial end if we are migrating the subscription from the CLI
        foreach ($subscriptions as $subscription)
        {
            if ($subscription['profile']['trial_end'])
                $params['trial_end'] = $subscription['profile']['trial_end'];
        }

        return $params;
    }

    public function filterToUpdateableParams($params)
    {
        $updateParams = [];

        if (empty($params))
            return $updateParams;

        $updateable = ['metadata', 'trial_end', 'coupon', 'expand'];

        foreach ($params as $key => $value)
        {
            if (in_array($key, $updateable))
                $updateParams[$key] = $value;
        }

        return $updateParams;
    }

    public function invalidateSubscription($subscription, $params)
    {
        $subscriptionItems = [];

        foreach ($params["items"] as $item)
        {
            $subscriptionItems[] = [
                "metadata" => [
                    "Type" => $item["metadata"]["Type"],
                    "SubscriptionProductIDs" => $item["metadata"]["SubscriptionProductIDs"]
                ],
                "price" => [
                    "id" => $item["price"]
                ],
                "quantity" => $item["quantity"]
            ];
        }

        $expectedValues = [
            "customer" => $params["customer"],
            "items" => [
                "data" => $subscriptionItems
            ]
        ];

        if (!empty($params['add_invoice_items']))
        {
            $oneTimeAmount = "unset";
            foreach ($params['add_invoice_items'] as $item)
            {
                $oneTimeAmount = [
                    "price" => [
                        "id" => $item["price"]
                    ],
                    "quantity" => $item["quantity"]
                ];
            }

            if (empty($subscription->latest_invoice->lines->data))
                throw new CacheInvalidationException("Non-updateable subscription details have changed: Regular items were added to the cart.");

            $hasRegularItems = false;
            foreach ($subscription->latest_invoice->lines->data as $invoiceLineItem)
            {
                if (!empty($invoiceLineItem->price->recurring->interval))
                    continue; // This is a subscription item

                $hasRegularItems = true;

                if ($this->compare->isDifferent($invoiceLineItem, $oneTimeAmount))
                    throw new CacheInvalidationException("Non-updateable subscription details have changed: One time payment amount has changed.");
            }

            if (!$hasRegularItems && $oneTimeAmount !== "unset")
                throw new CacheInvalidationException("Non-updateable subscription details have changed: Regular items were added to the cart.");
        }
        else
        {
            if (!empty($subscription->latest_invoice->lines->data))
            {
                foreach ($subscription->latest_invoice->lines->data as $invoiceLineItem)
                {
                    if (empty($invoiceLineItem->price->recurring->interval))
                        throw new CacheInvalidationException("Non-updateable subscription details have changed: Regular items were removed from the cart.");
                }
            }
        }

        if ($this->compare->isDifferent($subscription, $expectedValues))
            throw new CacheInvalidationException("Non-updateable subscription details have changed: " . $this->compare->lastReason);
    }

    public function updateSubscriptionFromQuote($quote, $subscriptionId, $paymentIntentParams)
    {
        $params = $this->getSubscriptionParamsFromQuote($quote, $paymentIntentParams);

        if (empty($params))
            return null;

        if (!$subscriptionId)
            return $this->config->getStripeClient()->subscriptions->create($params);

        try
        {
            $subscription = $this->config->getStripeClient()->subscriptions->retrieve($subscriptionId, [
                'expand' => $this->getSubscriptionExpandParams()
            ]);
        }
        catch (\Exception $e)
        {
            $this->paymentsHelper->logError("Could not retrieve subscription $subscriptionId: " . $e->getMessage(), $e->getTraceAsString());

            if (empty($params))
                return null;

            return $this->config->getStripeClient()->subscriptions->create($params);
        }

        try
        {
            $this->invalidateSubscription($subscription, $params);
        }
        catch (CacheInvalidationException $e)
        {
            try
            {
                $this->config->getStripeClient()->subscriptions->cancel($subscriptionId);
            }
            catch (\Exception $e)
            {

            }

            if (empty($params))
                return null;

            return $this->config->getStripeClient()->subscriptions->create($params);
        }

        $updateParams = $this->filterToUpdateableParams($params);

        if (empty($updateParams))
            return $subscription;

        if ($this->compare->isDifferent($subscription, $updateParams))
            $subscription = $this->config->getStripeClient()->subscriptions->update($subscriptionId, $updateParams);

        return $subscription;
    }

    public function updateSubscriptionFromOrder($order, $subscriptionId, $paymentIntentParams)
    {
        $quote = $this->paymentsHelper->loadQuoteById($order->getQuoteId());

        if (empty($quote) || !$quote->getId())
            throw new \Exception("The quote for this order could not be loaded");

        $params = $this->getSubscriptionParamsFromQuote($quote, $paymentIntentParams, $order);

        if (empty($params))
            return null;

        if (!$subscriptionId)
        {
            $subscription = $this->config->getStripeClient()->subscriptions->create($params);
            $this->updateSubscriptionEntry($subscription, $order);
            return $subscription;
        }

        $subscription = $this->config->getStripeClient()->subscriptions->retrieve($subscriptionId, [
            'expand' => $this->getSubscriptionExpandParams()
        ]);

        try
        {
            if (!$order->getPayment()->getAdditionalInformation('is_migrated_subscription'))
                $this->invalidateSubscription($subscription, $params);
        }
        catch (CacheInvalidationException $e)
        {
            throw new LocalizedException(__("The cart details have changed. Please refresh the page and try again."));
        }

        $updateParams = $this->filterToUpdateableParams($params);

        if (empty($updateParams))
        {
            $this->updateSubscriptionEntry($subscription, $order);
            return $subscription;
        }

        if ($this->compare->isDifferent($subscription, $updateParams))
            $subscription = $this->config->getStripeClient()->subscriptions->update($subscriptionId, $updateParams);

        if (!empty($subscription->latest_invoice->payment_intent->id))
        {
            $params = [];
            $params["description"] = $this->paymentsHelper->getOrderDescription($order);
            $params["metadata"] = $this->config->getMetadata($order);
            $shipping = $this->addressHelper->getShippingAddressFromOrder($order);
            if ($shipping)
                $params['shipping'] = $shipping;

            $paymentIntent = $this->config->getStripeClient()->paymentIntents->update($subscription->latest_invoice->payment_intent->id, $params);
            $subscription->latest_invoice->payment_intent = $paymentIntent;
        }

        $this->updateSubscriptionEntry($subscription, $order);

        return $subscription;
    }

    public function getSubscriptionItemsFromQuote($quote, $subscriptions, $order = null)
    {
        if (empty($subscriptions))
            return null;

        if (!$this->renewTogether($subscriptions))
            throw new LocalizedException(__("Subscriptions that do not renew together must be bought separately."));

        $recurringPrice = $this->createSubscriptionPriceForSubscriptions($quote, $subscriptions);

        $items = [];
        $metadata = $this->collectMetadataForSubscriptions($quote, $subscriptions, $order);

        $items[] = [
            "metadata" => $metadata,
            "price" => $recurringPrice->id,
            "quantity" => 1
        ];

        return $items;
    }

    /**
     * Description
     * @param \Magento\Sales\Model\Order $order
     * @return array<\Magento\Catalog\Model\Product,\Magento\Sales\Model\Quote\Item,array profile>
     */
    public function getSubscriptionsFromQuote($quote)
    {
        if (!$this->paymentsHelper->isSubscriptionsEnabled())
            return [];

        $items = $quote->getAllItems();
        $subscriptions = [];

        foreach ($items as $item)
        {
            $product = $this->paymentsHelper->getSubscriptionProductFromQuoteItem($item);
            if (!$product)
                continue;

            $subscriptions[] = [
                'product' => $product,
                'quote_item' => $item,
                'profile' => $this->getSubscriptionDetails($product, $quote, $item)
            ];
        }

        return $subscriptions;
    }

    /**
     * Description
     * @param \Magento\Sales\Model\Order $order
     * @return array<\Magento\Catalog\Model\Product,\Magento\Sales\Model\Order\Item,array profile>
     */
    public function getSubscriptionsFromOrder($order)
    {
        if (!$this->paymentsHelper->isSubscriptionsEnabled())
            return [];

        $items = $order->getAllItems();
        $subscriptions = [];

        foreach ($items as $item)
        {
            $product = $this->paymentsHelper->getSubscriptionProductFromOrderItem($item);
            if (!$product)
                continue;

            $subscriptions[$item->getQuoteItemId()] = [
                'product' => $product,
                'order_item' => $item,
                'profile' => $this->getSubscriptionDetails($product, $order, $item)
            ];
        }

        return $subscriptions;
    }

    public function getSubscriptionIntervalKeyFromProduct($product)
    {
        if (!$this->paymentsHelper->isSubscriptionsEnabled())
            return null;

        if (!$product || !$product->getId())
            return null;

        if (!$product->getStripeSubEnabled())
            return null;

        $key = '';
        $trialDays = $this->getTrialDays($product);
        if ($trialDays > 0)
            $key .= "trial_" . $trialDays . "_";

        $interval = $product->getStripeSubInterval();
        $intervalCount = $product->getStripeSubIntervalCount();

        if ($interval && $intervalCount && $intervalCount > 0)
            $key .= $interval . "_" . $intervalCount;

        return $key;
    }

    public function getQuote()
    {
        $quote = $this->paymentsHelper->getQuote();
        $createdAt = $quote->getCreatedAt();
        if (empty($createdAt)) // case of admin orders
        {
            $quoteId = $quote->getQuoteId();
            $quote = $this->paymentsHelper->loadQuoteById($quoteId);
        }
        return $quote;
    }

    public function getShippingTax($paramName = "percent", $quote = null)
    {
        if (!empty($this->shippingTaxPercent))
            return $this->shippingTaxPercent;

        if (empty($quote))
            $quote = $this->getQuote();

        if ($quote->getIsVirtual())
            return 0;

        $address = $quote->getShippingAddress();
        $address->collectShippingRates();

        $taxes = $address->getItemsAppliedTaxes();

        if (!is_array($taxes) || !is_array($taxes['shipping']))
            return 0;

        foreach ($taxes['shipping'] as $tax)
        {
            if ($tax['item_type'] == "shipping")
                return $tax[$paramName];
        }

        return 0;
    }

    public function isOrder($order)
    {
        if (!empty($order->getOrderCurrencyCode()))
            return true;

        return false;
    }

    public function getSubscriptionDetails($product, $order, $item)
    {
        // Get billing interval and billing period
        $interval = $product->getStripeSubInterval();
        $intervalCount = $product->getStripeSubIntervalCount();

        if (!$interval)
            throw new \Exception("An interval period has not been specified for the subscription");

        if (!$intervalCount)
            $intervalCount = 1;

        $name = $item->getName();
        $qty = max(/* quote */ $item->getQty(), /* order */ $item->getQtyOrdered());
        $originalItem = $item;
        $item = $this->paymentsHelper->getSubscriptionQuoteItemWithTotalsFrom($item, $order);

        // For subscription migrations via the CLI, we set the trial period manually
        if ($order->getPayment() && $order->getPayment()->getAdditionalInformation("subscription_start"))
        {
            $trialEnd = $order->getPayment()->getAdditionalInformation("subscription_start");
            if (!is_numeric($trialEnd) || $trialEnd < 0)
                $trialEnd = null;
        }
        else
            $trialEnd = null;

        // Get the subscription currency and amount
        $initialFee = $product->getStripeSubInitialFee();

        if (!is_numeric($initialFee))
            $initialFee = 0;

        if ($this->config->priceIncludesTax())
            $amount = $item->getPriceInclTax();
        else
            $amount = $item->getPrice();

        $discount = $item->getDiscountAmount();
        $tax = $item->getTaxAmount();

        if ($this->isOrder($order))
        {
            $currency = $order->getOrderCurrencyCode();
            $rate = $order->getBaseToOrderRate();
        }
        else
        {
            $currency = $order->getQuoteCurrencyCode();
            $rate = $order->getBaseToQuoteRate();
        }

        $baseDiscount = $item->getBaseDiscountAmount();
        $baseTax = $item->getBaseTaxAmount();
        $baseCurrency = $order->getBaseCurrencyCode();
        $baseShippingTaxAmount = 0;
        $baseShipping = 0;

        // This seems to be a Magento multi-currency bug, tested in v2.3.2
        if (is_numeric($rate) && $rate > 0 && $rate != 1 && $item->getPrice() == $item->getBasePrice())
            $amount = round($amount * $rate, 2); // We fix it by doing the calculation ourselves

        if (is_numeric($rate) && $rate > 0)
            $initialFee = round($initialFee * $rate, 2);

        if ($this->isOrder($order))
        {
            $quote = $this->paymentsHelper->getQuoteFromOrder($order);
            $quoteItem = null;
            foreach ($quote->getAllItems() as $qItem)
            {
                if ($qItem->getSku() == $item->getSku())
                {
                    $quoteItem = $qItem;

                    if ($quoteItem->getParentItemId() && $originalItem->getParentItem()->getProductType() == "configurable")
                    {
                        $qty = $item->getQtyOrdered() * $quoteItem->getQty();
                        $quoteItem->setQtyCalculated($qty);
                    }
                }
            }

            if ($item->getShippingAmount())
                $shipping = $item->getShippingAmount();
            else if ($item->getBaseShippingAmount())
                $shipping = $this->paymentsHelper->convertBaseAmountToStoreAmount($item->getBaseShippingAmount());
            else
            {
                $baseShipping = $this->taxHelper->getBaseShippingAmountForQuoteItem($quoteItem, $quote);
                $shipping = $this->paymentsHelper->convertBaseAmountToStoreAmount($baseShipping);
            }

            $orderShippingAmount = $order->getShippingAmount();
            $orderShippingTaxAmount = $order->getShippingTaxAmount();

            $shippingTaxPercent = $this->getShippingTax("percent");
            if ($orderShippingAmount == $shipping)
            {
                $shippingTaxAmount = $orderShippingTaxAmount;
            }
            else
            {
                $shippingTaxAmount = 0;

                if ($shippingTaxPercent && is_numeric($shippingTaxPercent) && $shippingTaxPercent > 0)
                {
                    if ($this->config->shippingIncludesTax())
                        $shippingTaxAmount = $this->taxHelper->taxInclusiveTaxCalculator($shipping, $shippingTaxPercent);
                    else
                        $shippingTaxAmount = $this->taxHelper->taxExclusiveTaxCalculator($shipping, $shippingTaxPercent);
                }
            }
        }
        else
        {
            $quote = $order;
            $quoteItem = $item;

            // Case for configurable and bundled subscriptions
            if ($quoteItem->getProductType() != $originalItem->getProductType())
            {
                $qty = $quoteItem->getQty();
                $name = $quoteItem->getName();
            }

            $baseShipping = $this->taxHelper->getBaseShippingAmountForQuoteItem($quoteItem, $quote);
            $shippingTaxRate = $this->taxHelper->getShippingTaxRateFromQuote($quote);
            $shipping = $this->paymentsHelper->convertBaseAmountToStoreAmount($baseShipping);

            $shippingTaxAmount = 0;
            $shippingTaxPercent = 0;

            if ($shipping > 0 && $shippingTaxRate)
            {
                $shippingTaxPercent = $shippingTaxRate["percent"];
                $shippingTaxAmount = $shippingTaxRate["amount"];
                $baseShippingTaxAmount = $shippingTaxRate["base_amount"];
            }
        }

        if (!is_numeric($amount))
            $amount = 0;

        if ($order->getPayment()->getAdditionalInformation("remove_initial_fee"))
            $initialFee = 0;

        if ($this->config->priceIncludesTax())
            $initialFeeTaxAmount = $this->taxHelper->taxInclusiveTaxCalculator($initialFee * $qty, $item->getTaxPercent());
        else
            $initialFeeTaxAmount = $this->taxHelper->taxExclusiveTaxCalculator($initialFee * $qty, $item->getTaxPercent());

        $params = [
            'name' => $name,
            'qty' => $qty,
            'interval' => $interval,
            'interval_count' => $intervalCount,
            'amount_magento' => $amount,
            'amount_stripe' => $this->paymentsHelper->convertMagentoAmountToStripeAmount($amount, $currency),
            'initial_fee_stripe' => 0,
            'initial_fee_magento' => 0,
            'discount_amount_magento' => $discount,
            'base_discount_amount_magento' => $baseDiscount,
            'discount_amount_stripe' => $this->paymentsHelper->convertMagentoAmountToStripeAmount($discount, $currency),
            'shipping_magento' => round($shipping, 2),
            'base_shipping_magento' => round($baseShipping, 2),
            'shipping_stripe' => $this->paymentsHelper->convertMagentoAmountToStripeAmount($shipping, $currency),
            'currency' => strtolower($currency),
            'base_currency' => strtolower($baseCurrency),
            'tax_percent' => $item->getTaxPercent(),
            'tax_percent_shipping' => $shippingTaxPercent,
            'tax_amount_item' => $tax, // already takes $qty into account
            'base_tax_amount_item' => $baseTax, // already takes $qty into account
            'tax_amount_item_stripe' => $this->paymentsHelper->convertMagentoAmountToStripeAmount($tax, $currency), // already takes $qty into account
            'tax_amount_shipping' => $shippingTaxAmount,
            'base_tax_amount_shipping' => $baseShippingTaxAmount,
            'tax_amount_shipping_stripe' => $this->paymentsHelper->convertMagentoAmountToStripeAmount($shippingTaxAmount, $currency),
            'tax_amount_initial_fee' => $initialFeeTaxAmount,
            'tax_amount_initial_fee_stripe' => $this->paymentsHelper->convertMagentoAmountToStripeAmount($initialFeeTaxAmount, $currency),
            'trial_end' => $trialEnd,
            'trial_days' => 0,
            'expiring_coupon' => null,
            'product_id' => $product->getId()
        ];

        if (!$trialEnd)
        {
            // The following should not be used with subscriptions which are migrated via the CLI tool.
            $params['trial_days'] = $this->getTrialDays($product);
            $params['expiring_coupon'] = $this->getExpiringCoupon($discount, $order); // @todo - we should check if the coupon is still active and recreate a custom one with less duration_in_months
            $params['initial_fee_stripe'] = $this->paymentsHelper->convertMagentoAmountToStripeAmount($initialFee, $currency);
            $params['initial_fee_magento'] = $initialFee;
        }

        return $params;
    }

    public function getTrialDays($product)
    {
        $trialDays = $product->getStripeSubTrial();
        if (!empty($trialDays) && is_numeric($trialDays) && $trialDays > 0)
            return $trialDays;

        return 0;
    }

    public function getExpiringCoupon($discountAmount, $order)
    {
        if ($discountAmount <= 0)
            return null;

        $appliedRuleIds = $order->getAppliedRuleIds();
        if (empty($appliedRuleIds))
            return null;

        $appliedRuleIds = explode(",", $appliedRuleIds);
        if (empty($appliedRuleIds))
            return null;

        $foundCoupons = [];
        foreach ($appliedRuleIds as $ruleId)
        {
            $coupon = $this->couponCollection->getByRuleId($ruleId);
            if ($coupon)
                $foundCoupons[] = $coupon;
        }

        if (empty($foundCoupons))
            return null;

        if (count($foundCoupons) > 1)
        {
            $this->paymentsHelper->logError("Could not apply discount coupon: Multiple cart price rules were applied on the cart. Only one can be applied on subscription carts.");
            return null;
        }

        return $foundCoupons[0]->getData();
    }

    public function getCouponId($subscriptions)
    {
        if (empty($subscriptions))
            return null;

        $amount = 0;
        $currency = null;
        $coupon = null;

        foreach ($subscriptions as $subscription)
        {
            $profile = $subscription['profile'];
            $coupon = $profile['expiring_coupon'];

            if (empty($coupon['coupon_duration']))
                return null;

            $amount += $profile['discount_amount_stripe'];
            $currency = $profile['currency'];
        }

        if (!$coupon)
            return null;

        $couponId = ((string)$amount) . strtoupper($currency);

        switch ($coupon['coupon_duration'])
        {
            case 'repeating':
                $couponId .= "-months-" . $coupon['coupon_months'];
                break;
            case 'once':
                $couponId .= "-once";
                break;
        }

        $key = "stripe_coupon_code_" . $couponId;
        if ($this->cache->load($key))
            return $couponId;

        try
        {
            $stripeCoupon = \Stripe\Coupon::retrieve($couponId);
        }
        catch (\Exception $e)
        {
            $stripeCoupon = null;
        }

        if (!$stripeCoupon)
        {
            try
            {
                $params = [
                    'id' => $couponId,
                    'amount_off' => $amount,
                    'currency' => $currency,
                    'name' => "Discount",
                    'duration' => $coupon['coupon_duration']
                ];

                if ($coupon['coupon_duration'] == "repeating" && !empty($coupon['coupon_months']))
                    $params['duration_in_months'] = $coupon['coupon_months'];

                $coupon = \Stripe\Coupon::create($params);
            }
            catch (\Exception $e)
            {
                $this->paymentsHelper->logError($e->getMessage(), $e->getTraceAsString());
                return null;
            }
        }

        $this->cache->save(true, $key, $tags = ["stripe_coupons"], $lifetime = 24 * 60 * 60);

        return $couponId;
    }

    public function getSubscriptionTotalFromProfile($profile)
    {
        $subscriptionTotal =
            ($profile['qty'] * $profile['amount_magento']) +
            $profile['shipping_magento'] -
            $profile['discount_amount_magento'];

        if (!$this->config->shippingIncludesTax())
            $subscriptionTotal += $profile['tax_amount_shipping']; // Includes qty calculation

        if (!$this->config->priceIncludesTax())
            $subscriptionTotal += $profile['tax_amount_item']; // Includes qty calculation

        return round($subscriptionTotal, 2);
    }

    // We increase the subscription price by the amount of the discount, so that we can apply
    // a discount coupon on the amount and go back to the original amount AFTER the discount is applied
    public function getSubscriptionTotalWithDiscountAdjustmentFromProfile($profile)
    {
        $total = $this->getSubscriptionTotalFromProfile($profile);

        if (!empty($profile['expiring_coupon']))
            $total += $profile['discount_amount_magento'];

        return $total;
    }

    public function getStripeDiscountAdjustment($subscriptions)
    {
        $adjustment = 0;

        foreach ($subscriptions as $subscription)
        {
            $profile = $subscription['profile'];

            // This calculation only applies to MixedTrial carts
            if (!$profile['trial_days'])
                return 0;

            if (!empty($profile['expiring_coupon']))
                $adjustment += $profile['discount_amount_stripe'];
        }

        return $adjustment;
    }

    public function updateSubscriptionEntry($subscription, $order)
    {
        $entry = $this->subscriptionFactory->create();
        $entry->load($subscription->id, 'subscription_id');
        $entry->initFrom($subscription, $order);
        $entry->save();
        return $entry;
    }

    public function findSubscriptionItem($sub)
    {
        if (empty($sub->items->data))
            return null;

        foreach ($sub->items->data as $item)
        {
            if (!empty($item->price->product->metadata->{"Type"}) && $item->price->product->metadata->{"Type"} == "Product" && $item->price->type == "recurring")
                return $item;
        }

        return null;
    }

    public function isStripeCheckoutSubscription($sub)
    {
        if (empty($sub->metadata->{"Order #"}))
            return false;

        $order = $this->paymentsHelper->loadOrderByIncrementId($sub->metadata->{"Order #"});

        if (!$order || !$order->getId())
            return false;

        return $this->paymentsHelper->isStripeCheckoutMethod($order->getPayment()->getMethod());
    }

    public function formatSubscriptionName($sub)
    {
        $name = "";

        if (empty($sub))
            return "Unknown subscription (err: 1)";

        // Subscription Items
        if ($this->isStripeCheckoutSubscription($sub))
        {
            $item =  $this->findSubscriptionItem($sub);

            if (!$item)
                return "Unknown subscription (err: 2)";

            if (!empty($item->price->product->name))
                $name = $item->price->product->name;
            else
                return "Unknown subscription (err: 3)";

            $currency = $item->price->currency;
            $amount = $item->price->unit_amount;
            $quantity = $item->quantity;
        }
        // Invoice Items
        else
        {
            if (!empty($sub->plan->name))
                $name = $sub->plan->name;

            if (empty($name) && isset($sub->plan->product) && is_numeric($sub->plan->product))
            {
                $product = $this->paymentsHelper->loadProductById($sub->plan->product);
                if ($product && $product->getName())
                    $name = $product->getName();
            }
            else
                return "Unknown subscription (err: 4)";

            $currency = $sub->plan->currency;
            $amount = $sub->plan->amount;
            $quantity = $sub->quantity;
        }

        $precision = PriceCurrencyInterface::DEFAULT_PRECISION;
        $cents = 100;
        $qty = '';

        if ($this->paymentsHelper->isZeroDecimal($currency))
        {
            $cents = 1;
            $precision = 0;
        }

        $amount = $amount / $cents;

        if ($quantity > 1)
        {
            $qty = " x " . $quantity;
        }

        $this->priceCurrency->getCurrency()->setCurrencyCode(strtoupper($currency));
        $cost = $this->priceCurrency->format($amount, false, $precision);

        return "$name ($cost$qty)";
    }

    public function getSubscriptionsName($subscriptions)
    {
        $productNames = [];

        foreach ($subscriptions as $subscription)
        {
            $profile = $subscription['profile'];

            if ($profile['qty'] > 1)
                $productNames[] = $profile['qty'] . " x " . $profile['name'];
            else
                $productNames[] = $profile['name'];
        }

        $productName = implode(", ", $productNames);

        $productName = substr($productName, 0, 250);

        return $productName;
    }

    public function createSubscriptionPriceForSubscriptions(\Magento\Quote\Api\Data\CartInterface $quote, $subscriptions)
    {
        if (empty($quote->getId()))
            $quote = $this->paymentsHelper->saveQuote($quote);

        if (empty($quote->getId()))
            throw new \Exception("Cannot create subscription price from a quote with no ID.");

        if (empty($subscriptions))
            throw new \Exception("No subscriptions specified");

        $productNames = [];
        $totalAmount = 0;
        $interval = "month";
        $intervalCount = 1;
        $profile = [];
        $currency = "usd";

        foreach ($subscriptions as $subscription)
        {
            $profile = $subscription['profile'];
            $totalAmount += $this->getSubscriptionTotalWithDiscountAdjustmentFromProfile($profile);

            // These will be the same for all subscriptions, we just read the first one.
            $interval = $profile['interval'];
            $intervalCount = $profile['interval_count'];
            $currency = $profile['currency'];
        }

        $totalAmount = $this->paymentsHelper->convertMagentoAmountToStripeAmount($totalAmount, $currency);

        $productName = $this->getSubscriptionsName($subscriptions);

        if ($this->paymentsHelper->isMultiShipping())
            throw new \Exception("Price ID for multi-shipping subscriptions is not implemented", 1);

        $priceId = $quote->getId();

        $productData = [
            "name" => $productName
        ];

        $priceData = ([
            'unit_amount' => $totalAmount,
            'currency' => $currency,
            'recurring' => [
                'interval' => $interval,
                'interval_count' => $intervalCount
            ],
            'product_data' => $productData,
        ]);

        $key = "price_data_quote_" . $quote->getId();

        try
        {
            $oldData = $this->cache->load($key);
            if (empty($oldData))
                throw new \Exception("Not found");

            $oldData = json_decode($oldData, true);
            if (empty($oldData["price_id"]) || empty($oldData["price_data"]))
                throw new \Exception("Invalid data");

            if ($this->compare->isDifferent($oldData["price_data"], $priceData))
                throw new \Exception("Price has changed");

            return $this->config->getStripeClient()->prices->retrieve($oldData["price_id"]);
        }
        catch (\Exception $e)
        {

        }

        $stripePrice = $this->config->getStripeClient()->prices->create($priceData);

        $data = [
            "price_id" => $stripePrice->id,
            "price_data" => $priceData
        ];
        $this->cache->save(json_encode($data), $key, $tags = ["unconfirmed_subscriptions"], $lifetime = 2 * 60 * 60);

        return $stripePrice;
    }


    public function createPriceForOneTimePayment($quote, $paymentIntentParams, $stripeDiscountAdjustment = 0)
    {
        if (empty($quote->getId()))
            $quote = $this->paymentsHelper->saveQuote($quote);

        if (empty($quote->getId()))
            throw new \Exception("Cannot create price from a quote with no ID.");

        $productData = [
            "name" => __("One time payment")
        ];

        $currency = $paymentIntentParams['currency'];
        $totalAmount = $paymentIntentParams['amount'] + $stripeDiscountAdjustment;

        $priceData = ([
            'unit_amount' => $totalAmount,
            'currency' => $currency,
            'product_data' => $productData,
        ]);

        $key = "price_data_quote_once_" . $quote->getId();

        try
        {
            $oldData = $this->cache->load($key);
            if (empty($oldData))
                throw new \Exception("Not found");

            $oldData = json_decode($oldData, true);
            if (empty($oldData["price_id"]) || empty($oldData["price_data"]))
                throw new \Exception("Invalid data");

            if ($this->compare->isDifferent($oldData["price_data"], $priceData))
                throw new \Exception("Price has changed");

            return $this->config->getStripeClient()->prices->retrieve($oldData["price_id"]);
        }
        catch (\Exception $e)
        {

        }

        $stripePrice = $this->config->getStripeClient()->prices->create($priceData);

        $data = [
            "price_id" => $stripePrice->id,
            "price_data" => $priceData
        ];
        $this->cache->save(json_encode($data), $key, $tags = ["unconfirmed_subscriptions"], $lifetime = 2 * 60 * 60);

        return $stripePrice;
    }

    public function collectMetadataForSubscriptions($quote, $subscriptions, $order = null)
    {
        $subscriptionProductIds = [];

        foreach ($subscriptions as $subscription)
        {
            $product = $subscription['product'];
            $profile = $subscription['profile'];
            $subscriptionProductIds[] = $profile['product_id'];
        }

        if (empty($subscriptionProductIds))
            throw new \Exception("Could not find any subscription product IDs in cart subscriptions.");

        $metadata = [
            "Type" => "SubscriptionsTotal",
            "SubscriptionProductIDs" => implode(",", $subscriptionProductIds)
        ];

        if ($order)
            $metadata["Order #"] = $order->getIncrementId();
        else if ($quote->getReservedOrderId())
            $metadata["Order #"] = $quote->getReservedOrderId();

        return $metadata;
    }

    public function getTrialingSubscriptionsAmounts($quote = null)
    {
        if ($this->trialingSubscriptionsAmounts)
            return $this->trialingSubscriptionsAmounts;

        if (!$quote)
            $quote = $this->paymentsHelper->getQuote();

        $trialingSubscriptionsAmounts = [
            "subscriptions_total" => 0,
            "base_subscriptions_total" => 0,
            "shipping_total" => 0,
            "base_shipping_total" => 0,
            "discount_total" => 0,
            "base_discount_total" => 0,
            "tax_total" => 0,
            "base_tax_total" => 0
        ];

        if (!$quote)
            return $trialingSubscriptionsAmounts;

        $this->trialingSubscriptionsAmounts = $trialingSubscriptionsAmounts;

        $items = $quote->getAllItems();
        foreach ($items as $item)
        {
            $product = $this->paymentsHelper->getSubscriptionProductFromOrderItem($item);
            if (!$product)
                continue;

            if (!$product->getStripeSubEnabled())
                continue;

            $trial = $product->getStripeSubTrial();
            if (is_numeric($trial) && $trial > 0)
            {
                $item = $this->paymentsHelper->getSubscriptionQuoteItemWithTotalsFrom($item, $quote);

                $profile = $this->getSubscriptionDetails($product, $quote, $item);

                $shipping = $profile["shipping_magento"];
                $baseShipping = $profile["base_shipping_magento"];
                if ($this->config->shippingIncludesTax())
                {
                    // $shipping -= $profile["tax_amount_shipping"];
                    // $baseShipping -= $baseProfile["tax_amount_shipping"];
                }

                $subtotal = $item->getRowTotal();
                $baseSubtotal = $item->getBaseRowTotal();
                if ($this->config->priceIncludesTax())
                {
                    $subtotal = $item->getRowTotalInclTax();
                    $baseSubtotal = $item->getBaseRowTotalInclTax();
                }

                $baseDiscountTotal = $profile["base_discount_amount_magento"];
                $baseTaxAmountItem = $profile["base_tax_amount_item"];
                $baseTaxAmountShipping = $profile["base_tax_amount_shipping"];

                $this->trialingSubscriptionsAmounts["subscriptions_total"] += $subtotal;
                $this->trialingSubscriptionsAmounts["base_subscriptions_total"] += $baseSubtotal;
                $this->trialingSubscriptionsAmounts["shipping_total"] += $shipping;
                $this->trialingSubscriptionsAmounts["base_shipping_total"] += $baseShipping;
                $this->trialingSubscriptionsAmounts["discount_total"] += $profile["discount_amount_magento"];
                $this->trialingSubscriptionsAmounts["base_discount_total"] += $baseDiscountTotal;
                $this->trialingSubscriptionsAmounts["tax_total"] += $profile["tax_amount_item"] + $profile["tax_amount_shipping"];
                $this->trialingSubscriptionsAmounts["base_tax_total"] += $baseTaxAmountItem + $baseTaxAmountShipping;
            }
        }

        return $this->trialingSubscriptionsAmounts;
    }

    public function formatInterval($stripeAmount, $currency, $intervalCount, $intervalUnit)
    {
        $amount = $this->paymentsHelper->formatStripePrice($stripeAmount, $currency);

        if ($intervalCount > 1)
            return __("%1 every %2 %3", $amount, $intervalCount, $intervalUnit . "s");
        else
            return __("%1 every %2", $amount, $intervalUnit);
    }

    public function renewTogether($subscriptions)
    {
        $startingTimes = [];
        $endingTimes = [];
        $now = time();

        foreach ($subscriptions as $subscription)
        {
            $starts = $now;
            if (!empty($subscription['profile']['trial_end']))
                $starts = $subscription['profile']['trial_end'];
            else if (!empty($subscription['profile']['trial_days']))
                $starts = strtotime("+" . $subscription['profile']['trial_days'] . " days", $now);

            $ends = $starts + strtotime("+" . $subscription['profile']['interval_count'] . " " . $subscription['profile']['interval']);

            $startingTimes[$starts] = $starts;
            $endingTimes[$ends] = $ends;
        }

        if (count($startingTimes) > 1)
            return false;

        if (count($endingTimes) > 1)
            return false;

        return true;
    }

    public function renewTogetherByProducts($products)
    {
        $keys = [];

        foreach ($products as $product)
        {
            $key = $this->getSubscriptionIntervalKeyFromProduct($product);
            if (empty($key))
                continue;

            if (!empty($keys) && !in_array($key, $keys))
                return false;

            $keys[] = $key;
        }

        return true;
    }
}
