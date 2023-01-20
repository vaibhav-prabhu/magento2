<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;

class InitParams
{

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Locale $localeHelper,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\PaymentIntent $paymentIntent,
        \StripeIntegration\Payments\Model\PaymentElement $paymentElement
    ) {
        $this->helper = $helper;
        $this->localeHelper = $localeHelper;
        $this->addressHelper = $addressHelper;
        $this->config = $config;
        $this->paymentIntent = $paymentIntent;
        $this->paymentElement = $paymentElement;
        $this->customer = $helper->getCustomerModel();
    }

    public function getCheckoutParams()
    {
        if (!$this->config->isEnabled())
        {
            $params = [];
        }
        else if ($this->helper->isMultiShipping()) // Called by the UIConfigProvider
        {
            return $this->getMultishippingParams();
        }
        else
        {
            $params = [
                "apiKey" => $this->config->getPublishableKey(),
                "locale" => $this->localeHelper->getStripeJsLocale()
            ];
        }

        return \Zend_Json::encode($params);
    }

    public function getAdminParams()
    {
        $params = [
            "apiKey" => $this->config->getPublishableKey(),
            "locale" => $this->localeHelper->getStripeJsLocale()
        ];

        return \Zend_Json::encode($params);
    }

    public function getMultishippingParams()
    {
        $params = [
            "apiKey" => $this->config->getPublishableKey(),
            "locale" => $this->localeHelper->getStripeJsLocale(),
            "savedMethods" => $this->customer->getSavedPaymentMethods(null, true)
        ];

        return \Zend_Json::encode($params);
    }
}
