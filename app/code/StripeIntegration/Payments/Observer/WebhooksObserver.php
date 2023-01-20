<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Exception\WebhookException;

class WebhooksObserver implements ObserverInterface
{
    public function __construct(
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Helper\Generic $paymentsHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Model\InvoiceFactory $invoiceFactory,
        \StripeIntegration\Payments\Model\PaymentIntentFactory $paymentIntentFactory,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\SubscriptionFactory $subscriptionFactory,
        \StripeIntegration\Payments\Model\PaymentElementFactory $paymentElementFactory,
        \StripeIntegration\Payments\Helper\RecurringOrder $recurringOrderHelper,
        \StripeIntegration\Payments\Helper\CheckoutSession $checkoutSessionHelper,
        \Magento\Sales\Model\Order\Email\Sender\OrderCommentSender $orderCommentSender,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Framework\DB\Transaction $dbTransaction,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Sales\Model\Order\Payment\Transaction\Builder $transactionBuilder,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Api\InvoiceRepositoryInterface $invoiceRepository
    )
    {
        $this->webhooksHelper = $webhooksHelper;
        $this->paymentsHelper = $paymentsHelper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->addressHelper = $addressHelper;
        $this->orderHelper = $orderHelper;
        $this->invoiceFactory = $invoiceFactory;
        $this->paymentIntentFactory = $paymentIntentFactory;
        $this->config = $config;
        $this->subscriptionFactory = $subscriptionFactory;
        $this->paymentElementFactory = $paymentElementFactory;
        $this->recurringOrderHelper = $recurringOrderHelper;
        $this->checkoutSessionHelper = $checkoutSessionHelper;
        $this->orderCommentSender = $orderCommentSender;
        $this->eventManager = $eventManager;
        $this->invoiceService = $invoiceService;
        $this->dbTransaction = $dbTransaction;
        $this->cache = $cache;
        $this->transactionBuilder = $transactionBuilder;
        $this->orderRepository = $orderRepository;
        $this->invoiceRepository = $invoiceRepository;
    }

    protected function orderAgeLessThan($minutes, $order)
    {
        $created = strtotime($order->getCreatedAt());
        $now = time();
        return (($now - $created) < ($minutes * 60));
    }

    public function wasCapturedFromAdmin($object)
    {
        if (!empty($object['id']) && $this->cache->load("admin_captured_" . $object['id']))
            return true;

        if (!empty($object['payment_intent']) && is_string($object['payment_intent']) && $this->cache->load("admin_captured_" . $object['payment_intent']))
            return true;

        return false;
    }

    public function wasRefundedFromAdmin($object)
    {
        if (!empty($object['id']) && $this->cache->load("admin_refunded_" . $object['id']))
            return true;

        return false;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $eventName = $observer->getEvent()->getName();
        $arrEvent = $observer->getData('arrEvent');
        $stdEvent = $observer->getData('stdEvent');
        $object = $observer->getData('object');
        $paymentMethod = $observer->getData('paymentMethod');
        $isAsynchronousPaymentMethod = false;

        switch ($eventName)
        {
            case 'stripe_payments_webhook_checkout_session_expired':

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

                $this->addOrderComment($order, __("Stripe Checkout session has expired without a payment."));

                if ($this->paymentsHelper->isPendingCheckoutOrder($order))
                    $this->paymentsHelper->cancelOrCloseOrder($order);

                break;

            // Creates an invoice for an order when the payment is captured from the Stripe dashboard
            case 'stripe_payments_webhook_charge_captured':

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);
                $payment = $order->getPayment();

                if (empty($object['payment_intent']))
                    return;

                $paymentIntentId = $object['payment_intent'];

                $chargeAmount = $this->paymentsHelper->convertStripeAmountToOrderAmount($object['amount_captured'], $object['currency'], $order);
                $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE;
                $transaction = $this->paymentsHelper->addTransaction($order, $paymentIntentId, $transactionType, $paymentIntentId);
                $transaction->setAdditionalInformation("amount", $chargeAmount);
                $transaction->setAdditionalInformation("currency", $object['currency']);
                $transaction->save();

                $humanReadableAmount = $this->paymentsHelper->addCurrencySymbol($chargeAmount, $object['currency']);
                $comment = __("%1 amount of %2 via Stripe. Transaction ID: %3", __("Captured"), $humanReadableAmount, $paymentIntentId);
                $order->addStatusToHistory(false, $comment, $isCustomerNotified = false);
                $this->orderRepository->save($order);

                if ($this->wasCapturedFromAdmin($object))
                    return;

                $params = [
                    "amount" => $object['amount_captured'],
                    "currency" => $object['currency']
                ];

                $captureCase = \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE;

                $this->paymentsHelper->invoiceOrder($order, $paymentIntentId, $captureCase, $params);

                break;

            case 'stripe_payments_webhook_review_closed':

                if (empty($object['payment_intent']))
                    return;

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

                $this->eventManager->dispatch(
                    'stripe_payments_review_closed_before',
                    ['order' => $order, 'object' => $object]
                );

                if ($object['reason'] == "approved")
                {
                    if (!$order->canHold())
                        $order->unhold();

                    $comment = __("The payment has been approved through Stripe.");
                    $order->addStatusToHistory(false, $comment, $isCustomerNotified = false);
                    $this->paymentsHelper->saveOrder($order);
                }
                else
                {
                    $comment = __("The payment was canceled through Stripe with reason: %1.", ucfirst(str_replace("_", " ", $object['reason'])));
                    $order->addStatusToHistory(false, $comment, $isCustomerNotified = false);
                    $this->paymentsHelper->saveOrder($order);
                }

                $this->eventManager->dispatch(
                    'stripe_payments_review_closed_after',
                    ['order' => $order, 'object' => $object]
                );

                break;

            case 'stripe_payments_webhook_invoice_finalized':

                try
                {
                    $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);
                }
                catch (\Exception $e)
                {
                    // May come from a PaymentElement subscription creation
                    break;
                }

                switch ($order->getPayment()->getMethod())
                {
                    case "stripe_payments_invoice":
                        $comment = __("A payment is pending for this order. Invoice ID: %1", $object['id']);
                        $this->paymentsHelper->setOrderState($order, \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT, $comment);
                        $this->paymentsHelper->saveOrder($order);
                        break;
                }

                break;

            case 'stripe_payments_webhook_customer_subscription_created':

                $subscription = $stdEvent->data->object;

                try
                {
                    $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);
                    $this->subscriptionsHelper->updateSubscriptionEntry($subscription, $order);
                }
                catch (\Exception $e)
                {
                    if ($object['status'] == "incomplete" || $object['status'] == "trialing")
                    {
                        // A PaymentElement has created an incomplete subscription which has not order yet
                        $this->subscriptionsHelper->updateSubscriptionEntry($subscription, null);
                    }
                    else
                    {
                        throw $e;
                    }
                }

                break;

            case 'stripe_payments_webhook_invoice_voided':
            case 'stripe_payments_webhook_invoice_marked_uncollectible':

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

                switch ($order->getPayment()->getMethod())
                {
                    case "stripe_payments_invoice":
                        $this->webhooksHelper->refundOfflineOrCancel($order);
                        $comment = __("The invoice was voided from the Stripe Dashboard.");
                        $order->addStatusToHistory(false, $comment, $isCustomerNotified = false);
                        $this->paymentsHelper->saveOrder($order);
                        break;
                }

                break;

            case 'stripe_payments_webhook_charge_refunded':

                if ($this->wasRefundedFromAdmin($object))
                    return;

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

                $result = $this->webhooksHelper->refund($order, $object);
                break;

            case 'stripe_payments_webhook_setup_intent_canceled':
            case 'stripe_payments_webhook_payment_intent_canceled':

                if ($object["status"] != "canceled")
                    break;

                $orders = $this->webhooksHelper->loadOrderFromEvent($arrEvent, true);

                foreach ($orders as $order)
                {
                    if ($object["cancellation_reason"] == "abandoned")
                    {
                        $msg = __("Customer abandoned the cart. The payment session has expired.");
                        $this->addOrderComment($order, $msg);
                        $this->paymentsHelper->cancelOrCloseOrder($order);
                    }
                }
                break;

            case 'stripe_payments_webhook_payment_intent_succeeded':

                break;

            case 'stripe_payments_webhook_setup_intent_setup_failed':
            case 'stripe_payments_webhook_payment_intent_payment_failed':

                $orders = $this->webhooksHelper->loadOrderFromEvent($arrEvent, true);

                foreach ($orders as $order)
                {
                    if (!empty($object['last_payment_error']['message']))
                        $lastError = $object['last_payment_error'];
                    elseif (!empty($object['last_setup_error']['message']))
                        $lastError = $object['last_setup_error'];
                    else
                        $lastError = null;

                    if (!empty($lastError['message'])) // This is set with Stripe Checkout / redirect flow
                    {
                        switch ($lastError['code'])
                        {
                            case 'payment_intent_authentication_failure':
                                $msg = __("Payment authentication failed.");
                                break;
                            case 'payment_intent_payment_attempt_failed':
                                if (strpos($lastError['message'], "expired") !== false)
                                {
                                    $msg = __("Customer abandoned the cart. The payment session has expired.");
                                    $this->paymentsHelper->cancelOrCloseOrder($order);
                                }
                                else
                                    $msg = __("Payment failed: %1", $lastError['message']);
                                break;
                            default:
                                $msg = __("Payment failed: %1", $lastError['message']);
                                break;
                        }
                    }
                    else if (!empty($object['failure_message']))
                        $msg = __("Payment failed: %1", $object['failure_message']);
                    else if (!empty($object["outcome"]["seller_message"]))
                        $msg = __("Payment failed: %1", $object["outcome"]["seller_message"]);
                    else
                        $msg = __("Payment failed.");

                    $this->addOrderComment($order, $msg);
                }

                break;

            case 'stripe_payments_webhook_setup_intent_succeeded':

                // This is a trial subscription order for which no charge.succeeded event will be received
                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

                $paymentElement = $this->paymentElementFactory->create()->load($object['id'], 'setup_intent_id');
                if (!$paymentElement->getId())
                    break;

                if (!$paymentElement->getSubscriptionId())
                    break;

                $subscription = $this->config->getStripeClient()->subscriptions->retrieve($paymentElement->getSubscriptionId());

                $updateData = [];

                if (empty($subscription->metadata->{"Order #"}))
                {
                    // With PaymentElement subscriptions, the subscription object is created before the order is placed,
                    // and thus it does not have the order number at creation time.
                    $updateData["metadata"] = ["Order #" => $order->getIncrementId()];
                }

                if (!empty($object['payment_method']))
                    $updateData['default_payment_method'] = $object['payment_method'];

                if (!empty($updateData))
                    $this->config->getStripeClient()->subscriptions->update($subscription->id, $updateData);

                if ($subscription->status != "trialing")
                    break;

                $transactions = $this->paymentsHelper->getOrderTransactions($order);
                if (count($transactions) === 0)
                    $this->paymentsHelper->setTotalPaid($order, 0, $order->getBaseCurrencyCode());

                // Trial subscriptions should still be fulfilled. A new order will be created when the trial ends.
                $order->setCanSendNewEmailFlag(true);
                $state = \Magento\Sales\Model\Order::STATE_PROCESSING;
                $status = $order->getConfig()->getStateDefaultStatus($state);
                $comment = __("Your trial period for order #%1 has started.", $order->getIncrementId());
                $order->setState($state)->addStatusToHistory($status, $comment, $isCustomerNotified = true);
                $this->paymentsHelper->saveOrder($order);

                break;

            case 'stripe_payments_webhook_source_chargeable':

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

                $this->webhooksHelper->charge($order, $object);
                break;

            case 'stripe_payments_webhook_source_canceled':

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

                $canceled = $this->paymentsHelper->cancelOrCloseOrder($order);
                if ($canceled)
                    $this->addOrderCommentWithEmail($order, "Sorry, your order has been canceled because a payment request was sent to your bank, but we did not receive a response back. Please contact us or place your order again.");
                break;

            case 'stripe_payments_webhook_source_failed':

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

                $this->paymentsHelper->cancelOrCloseOrder($order);
                $this->addOrderCommentWithEmail($order, "Your order has been canceled because the payment authorization failed.");
                break;

            case 'stripe_payments_webhook_charge_succeeded':

                if (!empty($object['metadata']['Multishipping']))
                {
                    $orders = $this->webhooksHelper->loadOrderFromEvent($arrEvent, true);
                    $this->deduplicatePaymentMethod($object, $orders[0]); // We only want to do this once

                    $paymentIntentModel = $this->paymentIntentFactory->create();

                    foreach ($orders as $order)
                        $this->orderHelper->onMultishippingChargeSucceeded($order, $object);

                    return;
                }

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

                if (!in_array($order->getState(), ['new', 'pending_payment', 'processing', 'payment_review']))
                {
                    $isUnpaid = ($order->getTotalPaid() < $order->getGrandTotal());
                    $hasTrialSubscriptions = $this->paymentsHelper->hasTrialSubscriptionsIn($order->getAllItems());
                    $isVirtualOrder = $order->getIsVirtual();

                    if ($isUnpaid && $hasTrialSubscriptions)
                    {
                        // Exception to the rule: Trial subscription orders with a 0 amount initial payment will be in Complete status
                        // In this case we want to register a new charge against a completed order.
                    }
                    else if ($isVirtualOrder && $this->orderAgeLessThan($minutes = 60 * 12, $order))
                    {
                        // Exception 2 to the rule: Virtual subscription orders will be in Complete status.
                        // In this case we want to register a new charge against a completed order.
                    }
                    else
                    {
                        // We may receive a charge.succeeded event from a recurring subscription payment. In that case we want to create
                        // a new order for the new payment, rather than registering the charge against the original order.
                        break;
                    }
                }

                switch ($order->getPayment()->getMethod())
                {
                    case 'stripe_payments':
                    case 'stripe_payments_checkout':
                        $this->paymentsHelper->sendNewOrderEmailFor($order);
                        break;

                    case 'stripe_payments_express':
                        break;

                    default:
                        return;
                }

                $this->deduplicatePaymentMethod($object, $order);

                if (empty($object['payment_intent']))
                    throw new WebhookException("This charge was not created by a payment intent.");

                $transactionId = $object['payment_intent'];

                $payment = $order->getPayment();
                $payment->setTransactionId($transactionId)
                    ->setLastTransId($transactionId)
                    ->setIsTransactionPending(false)
                    ->setIsTransactionClosed(0)
                    ->setIsFraudDetected(false)
                    ->save();

                $chargeAmount = $this->paymentsHelper->convertStripeAmountToOrderAmount($object['amount'], $object['currency'], $order);
                $isFullyPaid = false;
                $amountCaptured = ($object["captured"] ? $object['amount_captured'] : 0);
                $currency = $object["currency"];
                $transactionsTotal = $this->paymentsHelper->convertStripeAmountToOrderAmount($amountCaptured, $currency, $order);

                $transactions = $this->paymentsHelper->getOrderTransactions($order);
                foreach ($transactions as $t)
                {
                    if ($t->getTxnType() != 'authorization')
                        $transactionsTotal += $t->getAdditionalInformation("amount");
                }

                if ($transactionsTotal >= $order->getGrandTotal())
                    $isFullyPaid = true;

                $this->orderHelper->onTransaction($order, $object, $transactionId);

                // If the order was partially invoiced in the past, it was because there were both regular products and trial subscriptions in the cart.
                // When trialing subscriptions activate, we create a new order with a separate invoice, and for that reason we do not want to invoice the
                // original order, otherwise the Magento invoice totals reports will be higher than the actual charged amount. We instead maintain a
                // single invoice per order for a single collected payment at the time it was placed.
                $shouldInvoice = ($order->canInvoice() && $order->getInvoiceCollection()->count() == 0);

                if ($shouldInvoice)
                {
                    if ($this->config->isAuthorizeOnly())
                    {
                        if ($this->config->isAutomaticInvoicingEnabled())
                            $this->paymentsHelper->invoicePendingOrder($order, $transactionId);
                    }
                    else if (!$isFullyPaid)
                    {
                        $this->paymentsHelper->invoicePendingOrder($order, $transactionId);
                    }
                    else
                    {
                        $invoice = $this->paymentsHelper->invoiceOrder($order, $transactionId, \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
                    }
                }

                try
                {
                    $this->paymentsHelper->setTotalPaid($order, $transactionsTotal, $object['currency']);
                }
                catch (\Exception $e)
                {
                    $this->paymentsHelper->logError("ERROR: Could not set the total paid amount for order #" . $order->getIncrementId());
                }

                if ($isFullyPaid)
                {
                    $invoiceCollection = $order->getInvoiceCollection();
                    if ($invoiceCollection->count() > 0)
                    {
                        $invoice = $invoiceCollection->getFirstItem();
                        if ($invoice->getState() == \Magento\Sales\Model\Order\Invoice::STATE_OPEN)
                        {
                            $invoice->pay();
                            $this->paymentsHelper->saveInvoice($invoice);
                        }
                    }
                }

                if ($this->config->isStripeRadarEnabled() && !empty($object['outcome']['type']) && $object['outcome']['type'] == "manual_review")
                    $this->paymentsHelper->holdOrder($order);

                $this->paymentsHelper->saveOrder($order);

                // Update the payment intents table, because the payment method was created after the order was placed
                $paymentIntentModel = $this->paymentIntentFactory->create()->load($object['payment_intent'], 'pi_id');
                $quoteId = $paymentIntentModel->getQuoteId();
                if ($quoteId == $order->getQuoteId())
                {
                    $paymentIntentModel->setPmId($object['payment_method']);
                    $paymentIntentModel->setOrderId($order->getId());
                    if (is_numeric($order->getCustomerId()) && $order->getCustomerId() > 0)
                        $paymentIntentModel->setCustomerId($order->getCustomerId());
                    $paymentIntentModel->save();
                }

                break;

            case 'stripe_payments_webhook_charge_failed':

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

                if ($isAsynchronousPaymentMethod)
                {
                    $this->paymentsHelper->cancelOrCloseOrder($order);

                    if (!empty($object['failure_message']))
                    {
                        $msg = (string)__("Your order has been canceled because the payment was declined: %1", $object['failure_message']);
                        $this->addOrderCommentWithEmail($order, $msg);
                    }
                    else
                    {
                        $msg = (string)__("Your order has been canceled because the payment was declined.");
                        $this->addOrderCommentWithEmail($order, $msg);
                    }
                }
                else
                {
                    if (!empty($object['failure_message']))
                        $msg = (string)__("Payment failed: %1", $object['failure_message']);
                    else if (!empty($object["outcome"]["seller_message"]))
                        $msg = (string)__("Payment failed: %1", $object["outcome"]["seller_message"]);
                    else
                        $msg = (string)__("Payment failed.");

                    $this->addOrderComment($order, $msg);
                }

                break;

            // Recurring subscription payments
            case 'stripe_payments_webhook_invoice_payment_succeeded':

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

                if (empty($order->getPayment()))
                    throw new WebhookException("Order #%1 does not have any associated payment details.", $order->getIncrementId());

                $paymentMethod = $order->getPayment()->getMethod();
                $invoiceId = $stdEvent->data->object->id;
                $invoiceParams = [
                    'expand' => [
                        'lines.data.price.product',
                        'subscription',
                        'payment_intent'
                    ]
                ];
                $invoice = $this->config->getStripeClient()->invoices->retrieve($invoiceId, $invoiceParams);
                $isTrialingSubscription = (!empty($invoice->subscription->status) && $invoice->subscription->status == "trialing");
                $isNewSubscriptionOrder = (!empty($object["billing_reason"]) && $object["billing_reason"] == "subscription_create");

                if ($isTrialingSubscription)
                {
                    // No payment was collected for this invoice (i.e. trial subscription only)
                    $order->setCanSendNewEmailFlag(true);
                    $this->paymentsHelper->notifyCustomer($order, __("Your trial period for order #%1 has started.", $order->getIncrementId()));

                    // If a charge.succeeded event was not received, set the total paid amount to 0
                    $transactions = $this->paymentsHelper->getOrderTransactions($order);
                    if (count($transactions) === 0)
                        $this->paymentsHelper->setTotalPaid($order, 0, $object['currency']);

                    // Trial subscriptions should still be fulfilled. A new order will be created when the trial ends.
                    $state = \Magento\Sales\Model\Order::STATE_PROCESSING;
                    $status = $order->getConfig()->getStateDefaultStatus($state);
                    $order->setState($state)->setStatus($status);
                    $this->paymentsHelper->saveOrder($order);
                }

                switch ($paymentMethod)
                {
                    case 'stripe_payments':
                    case 'stripe_payments_express':

                        $subscriptionId = $invoice->subscription->id;
                        $subscriptionModel = $this->subscriptionFactory->create()->load($subscriptionId, "subscription_id");
                        $subscriptionModel->initFrom($invoice->subscription, $order)->setIsNew(false)->save();

                        $updateParams = [];
                        if (empty($invoice->subscription->default_payment_method) && !empty($invoice->payment_intent->payment_method))
                            $updateParams["default_payment_method"] = $invoice->payment_intent->payment_method;

                        if (empty($invoice->subscription->metadata->{"Order #"}))
                            $updateParams["metadata"] = ["Order #" => $order->getIncrementId()];

                        if (!empty($updateParams))
                            $this->config->getStripeClient()->subscriptions->update($subscriptionId, $updateParams);

                        if (!$isNewSubscriptionOrder)
                        {
                            // This is a recurring payment, so create a brand new order based on the original one
                            $this->recurringOrderHelper->createFromInvoiceId($invoiceId);
                        }

                        break;

                    case 'stripe_payments_checkout':

                        if ($isNewSubscriptionOrder && !empty($invoice->payment_intent))
                        {
                            // A subscription order was placed via Stripe Checkout. Description and metadata can be set only
                            // after the payment intent is confirmed and the subscription is created.
                            $quote = $this->paymentsHelper->loadQuoteById($order->getQuoteId());
                            $params = $this->paymentIntentFactory->create()->getParamsFrom($quote, $order, $invoice->payment_intent->payment_method);
                            $updateParams = $this->checkoutSessionHelper->getPaymentIntentUpdateParams($params, $invoice->payment_intent, $filter = ["description", "metadata"]);
                            $this->config->getStripeClient()->paymentIntents->update($invoice->payment_intent->id, $updateParams);
                            $invoice = $this->config->getStripeClient()->invoices->retrieve($invoiceId, $invoiceParams);
                        }

                        // If this is a subscription order which was just placed, create an invoice for the order and return
                        // @todo: Do we get here if the payment is fraudulent, and does a duplicate order get created?
                        if ($isNewSubscriptionOrder)
                        {
                            $checkoutSessionId = $order->getPayment()->getAdditionalInformation('checkout_session_id');
                            if (empty($checkoutSessionId))
                                throw new WebhookException("Order #%1 is not associated with a valid Stripe Checkout Session.", $order->getIncrementId());

                            if ($isTrialingSubscription)
                                break;

                            $invoiceParams = [
                                "amount" => $invoice->payment_intent->amount,
                                "currency" => $invoice->payment_intent->currency,
                                "shipping" => 0,
                                "tax" => $invoice->tax,
                            ];

                            foreach ($invoice->lines->data as $invoiceLineItem)
                            {
                                if (!empty($invoiceLineItem->price->product->metadata->{"Type"}) && $invoiceLineItem->price->product->metadata->{"Type"} == "Shipping")
                                {
                                    $invoiceParams["shipping"] += $invoiceLineItem->price->unit_amount * $invoiceLineItem->quantity;
                                }
                            }

                            $paymentIntentModel = $this->paymentIntentFactory->create();
                            $paymentIntentModel->processAuthenticatedOrder($order, $invoice->payment_intent);

                            if (!empty($invoice->payment_intent->amount) && $invoice->payment_intent->amount > 0)
                            {
                                $this->paymentsHelper->invoiceOrder($order, $transactionId = $invoice->payment_intent->id, $captureCase = \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE, $amount = null, $save = true);

                                if ($invoice->payment_intent->status == "succeeded")
                                    $action = __("Captured");
                                else if ($invoice->payment_intent->status == "requires_capture")
                                    $action = __("Authorized");
                                else
                                    $action = __("Processed");

                                $amount = $this->paymentsHelper->getFormattedStripeAmount($invoice->payment_intent->amount, $invoice->payment_intent->currency, $order);
                                $comment = __("%action amount %amount through Stripe.", ['action' => $action, 'amount' => $amount]);
                                $order->addStatusToHistory($status = \Magento\Sales\Model\Order::STATE_PROCESSING, $comment, $isCustomerNotified = false)->save();
                            }
                        }
                        else
                        {
                            // At the activation of a trial subscription, mark the original order as paid
                            if ($order->getTotalPaid() < $order->getGrandTotal())
                            {
                                $transactionId = $this->paymentsHelper->cleanToken($order->getPayment()->getLastTransId());
                                if (empty($transactionId))
                                    $transactionId = $invoice->payment_intent;

                                $this->paymentsHelper->invoiceOrder($order, $transactionId, $captureCase = \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE, $amount = null, $save = true);
                            }

                            // Otherwise, this is a recurring payment, so create a brand new order based on the original one
                            $this->recurringOrderHelper->createFromSubscriptionItems($invoiceId);
                        }

                        break;

                    default:
                        # code...
                        break;
                }

                break;

            case 'stripe_payments_webhook_invoice_paid':

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);
                $paymentMethod = $order->getPayment()->getMethod();

                if ($paymentMethod != "stripe_payments_invoice")
                    break;

                $order->getPayment()->setLastTransId($object['payment_intent'])->save();

                foreach($order->getInvoiceCollection() as $invoice)
                {
                    $invoice->setTransactionId($object['payment_intent']);
                    $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
                    $invoice->pay();
                    $this->paymentsHelper->saveInvoice($invoice);
                }

                $this->paymentsHelper->setProcessingState($order, __("The customer has paid the invoice for this order."));
                $this->paymentsHelper->saveOrder($order);

                break;

            case 'stripe_payments_webhook_invoice_payment_failed':
                //$this->paymentFailed($event);
                break;

            default:
                # code...
                break;
        }
    }

    public function addOrderCommentWithEmail($order, $comment)
    {
        if (is_string($comment))
            $comment = __($comment);

        try
        {
            $this->orderCommentSender->send($order, $notify = true, $comment);
        }
        catch (\Exception $e)
        {
            // Just ignore this case
        }

        try
        {
            $order->addStatusToHistory($status = false, $comment, $isCustomerNotified = true);
            $this->paymentsHelper->saveOrder($order);
        }
        catch (\Exception $e)
        {
            $this->webhooksHelper->log($e->getMessage(), $e);
        }
    }

    public function addOrderComment($order, $comment)
    {
        $order->addStatusToHistory($status = false, $comment, $isCustomerNotified = false);
        $this->paymentsHelper->saveOrder($order);
    }

    public function getShippingAmount($event)
    {
        if (empty($event->data->object->lines->data))
            return 0;

        foreach ($event->data->object->lines->data as $lineItem)
        {
            if (!empty($lineItem->description) && $lineItem->description == "Shipping")
            {
                return $lineItem->amount;
            }
        }
    }

    public function getTaxAmount($event)
    {
        if (empty($event->data->object->tax))
            return 0;

        return $event->data->object->tax;
    }

    public function deduplicatePaymentMethod($object, $order)
    {
        if (!empty($object['customer']) && !empty($object['payment_method']))
        {
            $type = $object['payment_method_details']['type'];
            if (!empty($object['payment_method_details'][$type]['fingerprint']))
            {
                $this->paymentsHelper->deduplicatePaymentMethod(
                    $object['customer'],
                    $object['payment_method'],
                    $type,
                    $object['payment_method_details'][$type]['fingerprint'],
                    $this->config->getStripeClient()
                );
            }

            $paymentMethod = $this->config->getStripeClient()->paymentMethods->retrieve($object['payment_method'], []);
            if ($paymentMethod->customer) // true if the PM is saved on the customer
            {
                // Update the billing address on the payment method if that is already attached to a customer
                $this->config->getStripeClient()->paymentMethods->update(
                    $object['payment_method'],
                    ['billing_details' => $this->addressHelper->getStripeAddressFromMagentoAddress($order->getBillingAddress())]
                );
            }
        }
    }
}
