<?php

namespace StripeIntegration\Payments\Cron;

use StripeIntegration\Payments\Exception\SkipCaptureException;

class CaptureAuthorizations
{
    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Sales\Model\ResourceModel\Order\Collection $orderCollection,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Mail\Template\TransportBuilder $transportBuilder,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Multishipping $multishippingHelper,
        \StripeIntegration\Payments\Model\ResourceModel\Multishipping\Quote\Collection $multishippingQuoteCollection
    ) {
        $this->config = $config;
        $this->cache = $cache;
        $this->orderCollection = $orderCollection;
        $this->scopeConfig = $scopeConfig;
        $this->transportBuilder = $transportBuilder;
        $this->helper = $helper;
        $this->multishippingHelper = $multishippingHelper;
        $this->multishippingQuoteCollection = $multishippingQuoteCollection;
    }

    public function execute()
    {
        $quoteModels = $this->multishippingQuoteCollection->getUncaptured(0, 1);
        $transactionIds = [];

        foreach ($quoteModels as $quoteModel)
        {
            $paymentIntentId = $quoteModel->getPaymentIntentId();
            $transactionIds[$paymentIntentId] = $quoteModel;
        }

        foreach ($transactionIds as $paymentIntentId => $quoteModel)
        {
            $orders = $this->helper->getOrdersByTransactionId($paymentIntentId);
            if (empty($orders))
                continue;

            try
            {
                $this->multishippingHelper->captureOrdersFromCronJob($orders, $paymentIntentId);
                $quoteModel->setCaptured(true);
                $quoteModel->save();
            }
            catch (SkipCaptureException $e)
            {
                if ($e->getCode() == SkipCaptureException::ORDERS_NOT_PROCESSED)
                {
                    if (!$quoteModel->getWarningEmailSent())
                    {
                        $this->sendReminderEmail($paymentIntentId, $orders);
                        $quoteModel->setWarningEmailSent(true);
                        $quoteModel->save();
                    }
                }
                else if ($e->getCode() == SkipCaptureException::ZERO_AMOUNT)
                {
                    // The orders were likely canceled
                }
                else
                {
                    $this->helper->logError($e->getMessage());
                }
            }
            catch (\Exception $e)
            {
                $this->helper->logError($e->getMessage(), $e->getTraceAsString());
            }
        }
    }

    protected function sendReminderEmail($paymentIntentId, $orderCollection)
    {
        try
        {
            $generalEmail = $this->scopeConfig->getValue('trans_email/ident_general/email', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            $generalName = $this->scopeConfig->getValue('trans_email/ident_general/name', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

            $sender = [
                'name' => $generalName,
                'email' => $generalEmail
            ];

            $incrementIds = [];
            foreach ($orderCollection as $order)
            {
                $incrementIds[] = "#" . $order->getIncrementId();
            }

            $transport = $this->transportBuilder
                ->setTemplateIdentifier('stripe_expiring_authorization')
                ->setTemplateOptions([ 'area' => 'frontend', 'store' => $this->helper->getStoreId() ])
                ->setTemplateVars([ 'orderNumbers'  => implode(", ", $incrementIds) ])
                ->setFromByScope($sender)
                ->addTo($generalEmail, $generalName)
                ->getTransport();

            $transport->sendMessage();
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
        }
    }
}
