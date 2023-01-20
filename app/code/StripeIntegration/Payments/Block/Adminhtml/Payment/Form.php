<?php

namespace StripeIntegration\Payments\Block\Adminhtml\Payment;

use StripeIntegration\Payments\Helper\Logger;

// Payment method form in the Magento admin area
class Form extends \Magento\Payment\Block\Form\Cc
{
    protected $_template = 'form/stripe_payments.phtml';

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Model\Config $paymentConfig,
        \Magento\Framework\Data\Form\FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $paymentConfig, $data);
        $this->formKey = $formKey;
    }

    public function getFormKey()
    {
         return $this->formKey->getFormKey();
    }
}
