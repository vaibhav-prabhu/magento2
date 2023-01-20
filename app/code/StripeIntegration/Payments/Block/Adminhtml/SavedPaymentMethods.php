<?php

namespace StripeIntegration\Payments\Block\Adminhtml;

use Magento\Framework\Phrase;
use StripeIntegration\Payments\Gateway\Response\FraudHandler;
use StripeIntegration\Payments\Helper\Logger;

class SavedPaymentMethods extends \Magento\Backend\Block\Widget\Form\Generic
{
    protected $_template = 'form/saved_payment_methods.phtml';

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Model\Config $paymentsConfig,
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Data\FormFactory $formFactory,
        array $data = []
    ) {
        parent::__construct($context, $registry, $formFactory, $data);

        $this->helper = $helper;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->paymentsConfig = $paymentsConfig;
        $this->customer = $helper->getCustomerModel();
    }

    public function getSavedPaymentMethods()
    {
        if (!$this->customer->getStripeId())
        {
            $this->customer->createStripeCustomer();
            return [];
        }
        else if (!$this->customer->getCustomerId())
        {
            // Guest customers
            $params = $this->customer->getParams();
            $this->customer->createNewStripeCustomer($params); // Misleading method name, this updates the customer object
        }

        $methods = $this->customer->getSavedPaymentMethods();

        $data = [];

        foreach ($methods as $type => $methodList)
        {
            foreach ($methodList as $method)
            {
                $data[] = [
                    'id' => $method->id,
                    'icon' => $this->paymentMethodHelper->getIcon($method),
                    'label' => $this->paymentMethodHelper->getLabel($method)
                ];
            }
        }

        return $data;
    }

    public function getAddNewPaymentMethodURL()
    {
        $mode = $this->paymentsConfig->getStripeMode();

        if ($mode == "test")
            return "http://dashboard.stripe.com/test/customers/" . $this->customer->getStripeId();
        else
            return "http://dashboard.stripe.com/customers/" . $this->customer->getStripeId();
    }
}
