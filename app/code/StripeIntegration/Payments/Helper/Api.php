<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\CouldNotSaveException;
use StripeIntegration\Payments\Model;
use StripeIntegration\Payments\Model\PaymentMethod;
use StripeIntegration\Payments\Model\Config;
use Psr\Log\LoggerInterface;
use Magento\Framework\Validator\Exception;
use StripeIntegration\Payments\Helper\Logger;

class Api
{
    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        LoggerInterface $logger,
        Generic $helper,
        \StripeIntegration\Payments\Model\PaymentIntent $paymentIntent,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \StripeIntegration\Payments\Helper\Rollback $rollback,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \StripeIntegration\Payments\Model\ResourceModel\PaymentIntent\CollectionFactory $paymentIntentCollectionFactory
    ) {
        $this->logger = $logger;
        $this->helper = $helper;
        $this->config = $config;
        $this->customer = $helper->getCustomerModel();
        $this->_eventManager = $eventManager;
        $this->rollback = $rollback;
        $this->paymentIntent = $paymentIntent;
        $this->quoteFactory = $quoteFactory;
        $this->paymentIntentCollectionFactory = $paymentIntentCollectionFactory;
    }

    public function retrieveCharge($token)
    {
        if (empty($token))
            return null;

        if (strpos($token, 'pi_') === 0)
        {
            $pi = \Stripe\PaymentIntent::retrieve($token);

            if (empty($pi->charges->data[0]))
                return null;

            return $pi->charges->data[0];
        }
        else if (strpos($token, 'in_') === 0)
        {
            // Subscriptions save the invoice number instead
            $in = \Stripe\Invoice::retrieve(['id' => $token, 'expand' => ['charge']]);

            return $in->charge;
        }

        return \Stripe\Charge::retrieve($token);
    }

    public function reCreateCharge($payment, $baseAmount, $originalCharge)
    {
        $order = $payment->getOrder();

        if (empty($originalCharge->payment_method) || empty($originalCharge->customer))
            throw new LocalizedException(__("The authorization has expired and the original payment method cannot be reused to re-create the payment."));

        $token = $originalCharge->payment_method;

        $fraud = false;

        $amount = $this->helper->convertBaseAmountToOrderAmount($baseAmount, $payment->getOrder(), $originalCharge->currency, 2);

        if ($amount > 0)
        {
            $quoteId = $order->getQuoteId();

            // We get here if an existing authorization has expired, in which case
            // we want to discard old Payment Intents and create a new one
            $this->paymentIntentCollectionFactory->create()->deleteForQuoteId($quoteId);

            $quote = $this->quoteFactory->create()->load($quoteId);

            $params = $this->paymentIntent->getParamsFrom($quote, $order, $token);
            $params['capture_method'] = \StripeIntegration\Payments\Model\PaymentIntent::CAPTURE_METHOD_AUTOMATIC;
            $params["customer"] = $originalCharge->customer;
            $params["amount"] = $this->helper->convertMagentoAmountToStripeAmount($amount, $originalCharge->currency);
            $params["currency"] = $originalCharge->currency;
            if (isset($params["payment_method_options"]))
                unset($params["payment_method_options"]);

            $paymentIntent = $this->config->getStripeClient()->paymentIntents->create($params);
            $confirmParams = $this->paymentIntent->getConfirmParams($order, $paymentIntent);
            $paymentIntent = $this->paymentIntent->confirm($paymentIntent, $confirmParams);
            $this->paymentIntent->processAuthenticatedOrder($order, $paymentIntent);
        }
    }
}
