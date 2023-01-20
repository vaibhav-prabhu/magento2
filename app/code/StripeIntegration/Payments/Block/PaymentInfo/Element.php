<?php

namespace StripeIntegration\Payments\Block\PaymentInfo;

use Magento\Framework\Phrase;
use StripeIntegration\Payments\Gateway\Response\FraudHandler;
use StripeIntegration\Payments\Helper\Logger;

class Element extends \StripeIntegration\Payments\Block\PaymentInfo\Checkout
{
    protected $_template = 'paymentInfo/element.phtml';
    protected $paymentIntents = [];
    public $subscription = null;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Gateway\ConfigInterface $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptions,
        \StripeIntegration\Payments\Model\Config $paymentsConfig,
        \StripeIntegration\Payments\Model\PaymentElement $paymentElement,
        \StripeIntegration\Payments\Helper\Api $api,
        \Magento\Directory\Model\Country $country,
        \Magento\Payment\Model\Info $info,
        \Magento\Framework\Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $config, $helper, $paymentMethodHelper, $subscriptions, $paymentsConfig, $api, $country, $info, $registry, $data);

        $this->paymentElement = $paymentElement;
    }

    public function getPaymentMethod()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (!empty($paymentIntent->payment_method->type))
            return $paymentIntent->payment_method;

        return null;
    }

    public function isMultiShipping()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->metadata["Multishipping"]))
            return false;

        return true;
    }

    public function getFormattedAmount()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->amount))
            return '';

        return $this->helper->formatStripePrice($paymentIntent->amount, $paymentIntent->currency);
    }

    public function getFormattedMultishippingAmount()
    {
        $total = $this->getFormattedAmount();

        $paymentIntent = $this->getPaymentIntent();

        $info = $this->getInfo();
        if (!is_numeric($info->getAmountOrdered()))
            return $total;

        $partial = $this->helper->addCurrencySymbol($info->getAmountOrdered(), $paymentIntent->currency);

        return $partial;
    }

    public function getPaymentStatus()
    {
        $paymentIntent = $this->getPaymentIntent();

        return $this->getPaymentIntentStatus($paymentIntent);
    }

    public function getSubscription()
    {
        if (empty($this->subscription))
        {
            $info = $this->getInfo();
            if ($info && $info->getAdditionalInformation("subscription_id"))
            {
                try
                {
                    $subscriptionId = $info->getAdditionalInformation("subscription_id");
                    $this->subscription = $this->paymentsConfig->getStripeClient()->subscriptions->retrieve($subscriptionId);
                }
                catch (\Exception $e)
                {
                    $this->helper->logError($e->getMessage(), $e->getTraceAsString());
                    return null;
                }
            }
        }

        return $this->subscription;
    }

    public function getCustomerId()
    {
        $info = $this->getInfo();
        if ($info && $info->getAdditionalInformation("customer_stripe_id"))
            return $info->getAdditionalInformation("customer_stripe_id");

        return null;
    }

    public function isStripeMethod()
    {
        $method = $this->getInfo()->getMethod();

        if (strpos($method, "stripe_payments") !== 0 || $method == "stripe_payments_invoice")
            return false;

        return true;
    }

    public function getPaymentIntent()
    {
        $transactionId = $this->getInfo()->getLastTransId();
        $transactionId = $this->helper->cleanToken($transactionId);

        if (empty($transactionId) || strpos($transactionId, "pi_") !== 0)
            return null;

        if (isset($this->paymentIntents[$transactionId]))
            return $this->paymentIntents[$transactionId];

        try
        {
            return $this->paymentIntents[$transactionId] = $this->paymentsConfig->getStripeClient()->paymentIntents->retrieve($transactionId, ['expand' => ['payment_method']]);
        }
        catch (\Exception $e)
        {
            return $this->paymentIntents[$transactionId] = null;
        }
    }

    public function getSetupIntent()
    {
        $transactionId = $this->getInfo()->getLastTransId();
        $transactionId = $this->helper->cleanToken($transactionId);

        if (empty($transactionId) || strpos($transactionId, "seti_") !== 0)
            return null;

        if (isset($this->setupIntents[$transactionId]))
            return $this->setupIntents[$transactionId];

        try
        {
            return $this->setupIntents[$transactionId] = $this->paymentsConfig->getStripeClient()->setupIntents->retrieve($transactionId, ['expand' => ['payment_method']]);
        }
        catch (\Exception $e)
        {
            return $this->setupIntents[$transactionId] = null;
        }
    }

    public function getMode()
    {
        $paymentIntent = $this->getPaymentIntent();
        $setupIntent = $this->getSetupIntent();

        if ($paymentIntent && $paymentIntent->livemode)
            return "";
        else if ($setupIntent && $setupIntent->livemode)
            return "";

        return "test/";
    }

    public function getOXXOVoucherLink()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (!empty($paymentIntent->next_action->oxxo_display_details->hosted_voucher_url))
            return $paymentIntent->next_action->oxxo_display_details->hosted_voucher_url;

        return null;
    }
}
