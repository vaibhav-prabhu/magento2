<?php

namespace StripeIntegration\Payments\Test\Integration\Helper;

class Event
{
    protected static $eventID;
    protected $tests = null;
    public $stripeConfig;
    public $objectManager;
    public $objectCollection;

    public function __construct($tests, $type = null)
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = $tests;

        if (empty($this::$eventID))
            $this::$eventID = time();

        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->request = $this->objectManager->get(\Magento\Framework\App\Request\Http::class);
        $this->response = $this->objectManager->get(\Magento\Framework\App\Response\Http::class);
        $this->webhooks = $this->objectManager->get(\StripeIntegration\Payments\Helper\Webhooks::class);

        if ($type)
            $this->setType($type);
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function setType($type)
    {
        switch (true)
        {
            case ($type == 'customer.subscription.created'):
                $this->objectCollection = "subscriptions";
                break;

            case (strpos($type, "charge.") === 0):
                $this->objectCollection = "charges";
                break;

            case (strpos($type, "review.") === 0):
                $this->objectCollection = "reviews";
                break;

            case (strpos($type, "payment_intent.") === 0):
                $this->objectCollection = "paymentIntents";
                break;

            case (strpos($type, "invoice.") === 0):
                $this->objectCollection = "invoices";
                break;

            case (strpos($type, "checkout.session.") === 0):
                $this->objectCollection = "checkout.sessions";
                break;

            case (strpos($type, "setup_intent.") === 0):
                $this->objectCollection = "setupIntents";
                break;

            default:
                throw new \Exception("Event type $type is not supported");
        }

        $this->eventType = $type;

        return $this;
    }

    public function getObject($objectId)
    {
        switch ($this->objectCollection)
        {
            case "checkout.sessions":
                return $this->stripeConfig->getStripeClient()->checkout->sessions->retrieve($objectId);
            default:
                return $this->stripeConfig->getStripeClient()->{$this->objectCollection}->retrieve($objectId);
        }
    }

    public function getObjectData($object, $extraParams = [])
    {
        $data = null;

        if (is_string($object))
        {
            $data = $this->getObject($object);
        }
        else if (is_object($object) || is_array($object))
        {
            $data = $object;
        }

        if (!empty($extraParams))
        {
            $data = json_decode(json_encode($data), true);
            $data = array_merge($data, $extraParams);
        }

        return json_encode($data);
    }

    public function getEventPayload($object, $extraParams = [])
    {
        return '{
  "id": "'. $this->getEventId() .'",
  "object": "event",
  "api_version": "2020-08-27",
  "created": 1627988871,
  "data": {
    "object": '.$this->getObjectData($object, $extraParams).'
  },
  "livemode": false,
  "pending_webhooks": 1,
  "request": {
    "id": "req_BKKckAZxOJfuGB",
    "idempotency_key": null
  },
  "type": "'.$this->eventType.'"
}';
    }

    public function dispatch($object, $extraParams = [])
    {
        $payload = $this->getEventPayload($object, $extraParams);
        $this->request->setMethod("POST");
        $this->request->setContent($payload);
        $this->webhooks->dispatchEvent();
    }

    protected function getEventId()
    {
        return 'evt_xxx_' . $this::$eventID++;
    }

    public function getInvoiceFromSubscription($subscription)
    {
        if (is_object($subscription->latest_invoice))
        {
            if (is_object($subscription->latest_invoice->charge))
                return $subscription->latest_invoice;
            else
            {
                $invoiceId = $subscription->latest_invoice->id;
            }
        }
        else
            $invoiceId = $subscription->latest_invoice;

        return $this->stripeConfig->getStripeClient()->invoices->retrieve($invoiceId, ['expand' => ['charge']]);
    }

    public function triggerSubscriptionEvents($subscription)
    {
        $this->tests->assertNotEmpty($subscription->latest_invoice);

        $invoice = $this->getInvoiceFromSubscription($subscription);

        $this->triggerEvent("invoice.finalized", $invoice);

        $this->triggerEvent("customer.subscription.created", $subscription);

        if ($invoice->charge)
            $this->triggerPaymentIntentEvents($invoice->payment_intent);

        $this->triggerEvent('invoice.payment_succeeded', $invoice);
    }

    public function triggerPaymentIntentEvents($paymentIntent, $test = null)
    {
        if (is_string($paymentIntent))
            $paymentIntent = $this->stripeConfig->getStripeClient()->paymentIntents->retrieve($paymentIntent);

        if (!empty($paymentIntent->charges->data[0]))
            $this->triggerEvent('charge.succeeded', $paymentIntent->charges->data[0]);

        $this->triggerEvent('payment_intent.succeeded', $paymentIntent);

        return $paymentIntent;
    }

    public function triggerEvent($type, $object, $extraParams = [])
    {
        $this->setType($type);
        $this->dispatch($object, $extraParams);
        $this->tests->assertEquals("", $this->getResponse()->getContent());
        $this->tests->assertEquals(200, $this->getResponse()->getStatusCode());
    }

    public function trigger($type, $object, $extraParams = [])
    {
        $this->triggerEvent($type, $object, $extraParams);
    }
}
