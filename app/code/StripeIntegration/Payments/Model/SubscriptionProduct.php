<?php

namespace StripeIntegration\Payments\Model;

use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Exception\InvalidSubscriptionProduct;

class SubscriptionProduct
{
    public $quoteItem = null;
    public $product = null;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper
    )
    {
        $this->helper = $helper;
    }

    public function initFromQuoteItem($item)
    {
        if (empty($item) || !$item->getId())
            throw new InvalidSubscriptionProduct("Invalid quote item.");

        $this->quoteItem = $item;
        $this->product = null;

        return $this;
    }

    public function getProduct()
    {
        if ($this->product)
            return $this->product;

        if (!$this->quoteItem)
            return null;

        if (!$this->quoteItem->getProduct())
            return null;

        if (!$this->quoteItem->getProduct()->getId())
            return null;

        $productId = $this->quoteItem->getProduct()->getId();
        $product = $this->helper->loadProductById($productId);
        if (!$product || !$product->getId())
            return null;

        if ($product->getStripeSubEnabled() != 1)
            return null;

        return $this->product = $product;
    }

    public function getTrialDays()
    {
        $product = $this->getProduct();

        if (!$product)
            return null;

        if (empty($product->getStripeSubTrial()))
            return null;

        return $product->getStripeSubTrial();
    }

    public function hasTrialPeriod()
    {
        $trialDays = $this->getTrialDays();
        if (!is_numeric($trialDays) || $trialDays < 1)
            return false;

        return true;
    }

    public function getTrialEnd()
    {
        if (!$this->hasTrialPeriod())
            return null;

        $trialDays = $this->getTrialDays();
        $timeDifference = $this->helper->getStripeApiTimeDifference();

        return (time() + $trialDays * 24 * 60 * 60 + $timeDifference);
    }
}
