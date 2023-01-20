<?php
namespace StripeIntegration\Payments\Plugin\Multishipping;

class Helper
{
    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper
    ) {
        $this->config = $config;
        $this->helper = $helper;
    }

    public function aroundIsMultishippingCheckoutAvailable(\Magento\Multishipping\Helper\Data $subject, $result)
    {
        if (!$this->config->isSubscriptionsEnabled())
            return $result;

        if ($this->helper->hasSubscriptions())
            return false;

        return $result;
    }
}
