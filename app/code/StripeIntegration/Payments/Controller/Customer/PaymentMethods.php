<?php

namespace StripeIntegration\Payments\Controller\Customer;

use StripeIntegration\Payments\Helper\Logger;
use Magento\Framework\Exception\LocalizedException;

class PaymentMethods extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \Magento\Framework\View\Result\PageFactory
     */
    protected $resultPageFactory;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Framework\View\Result\PageFactory resultPageFactory
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Magento\Customer\Model\Session $session,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper
    )
    {
        $this->resultPageFactory = $resultPageFactory;
        parent::__construct($context);

        $this->config = $config;
        $this->helper = $helper;
        $this->stripeCustomer = $helper->getCustomerModel();
        $this->customerSession = $session;

        if (!$session->isLoggedIn())
            $this->_redirect('customer/account/login');
    }

    public function execute()
    {
        $params = $this->getRequest()->getParams();

        if (isset($params['delete']))
            return $this->delete($params['delete']);

        return $this->resultPageFactory->create();
    }

    public function delete($token)
    {
        try
        {
            $customerId = $this->customerSession->getCustomer()->getId();
            $statuses = ['processing', 'fraud', 'pending_payment', 'payment_review', 'pending', 'holded'];
            $orders = $this->helper->getCustomerOrders($customerId, $statuses, $token);
            foreach ($orders as $order)
            {
                $message = __("Sorry, it is not possible to delete this payment method because order #%1 which was placed using it is still being processed.", $order->getIncrementId());
                throw new LocalizedException($message);
            }

            $card = $this->stripeCustomer->deletePaymentMethod($token);

            // In case we deleted a source
            if (isset($card->card))
                $card = $card->card;

            if (!empty($card->last4))
                $this->helper->addSuccess(__("Card **** %1 has been deleted.", $card->last4));
            else
                $this->helper->addSuccess(__("The payment method has been deleted."));
        }
        catch (LocalizedException $e)
        {
            $this->helper->addError($e->getMessage());
        }
        catch (\Stripe\Exception\CardException $e)
        {
            $this->helper->addError($e->getMessage());
        }
        catch (\Exception $e)
        {
            $this->helper->addError($e->getMessage());
            $this->helper->logError($e->getMessage());
            $this->helper->logError($e->getTraceAsString());
        }

        $this->_redirect('stripe/customer/paymentmethods');
    }
}
