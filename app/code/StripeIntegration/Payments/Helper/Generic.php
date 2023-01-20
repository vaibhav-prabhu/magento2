<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Backend\Model\Session;
use StripeIntegration\Payments\Model;
use Psr\Log\LoggerInterface;
use Magento\Framework\Validator\Exception;
use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Model\PaymentMethod;
use StripeIntegration\Payments\Model\Config;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Store\Model\ScopeInterface;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\LocalizedException;

class Generic
{
    public $magentoCustomerId = null;
    public $urlBuilder = null;
    protected $cards = [];
    public $orderComments = [];
    public $currentCustomer = null;
    public $productRepository = null;
    public $bundleProductOptions = [];
    public $quoteHasSubscriptions = [];

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        \Magento\Backend\Model\Session\Quote $backendSessionQuote,
        \Magento\Framework\App\Request\Http $request,
        LoggerInterface $logger,
        \Magento\Framework\App\State $appState,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Sales\Model\Order $order,
        \Magento\Sales\Model\Order\Invoice $invoice,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Sales\Model\Order\Creditmemo $creditmemo,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\App\ResourceConnection $resource,
        \Magento\Framework\Registry $coreRegistry,
        \Magento\Sales\Block\Adminhtml\Order\Create\Form\Address $adminOrderAddressForm,
        \Magento\Customer\Model\CustomerRegistry $customerRegistry,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Sales\Api\Data\OrderInterfaceFactory $orderFactory,
        \Magento\Checkout\Model\Cart $cart,
        \Magento\Sales\Model\Order\Invoice\CommentFactory $invoiceCommentFactory,
        \Magento\Customer\Model\Address $customerAddress,
        \Magento\Framework\Webapi\Response $apiResponse,
        \Magento\Framework\DB\TransactionFactory $transactionFactory,
        \Magento\Framework\App\RequestInterface $requestInterface,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Magento\Framework\Pricing\Helper\Data $pricingHelper,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        \Magento\Authorization\Model\UserContextInterface $userContext,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepositoryInterface,
        \Magento\Sales\Model\Order\Email\Sender\OrderCommentSender $orderCommentSender,
        \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory,
        \Magento\Sales\Model\Service\CreditmemoService $creditmemoService,
        \Magento\Sales\Api\InvoiceManagementInterface $invoiceManagement,
        \Magento\Sales\Api\Data\OrderPaymentExtensionInterfaceFactory $paymentExtensionFactory,
        \StripeIntegration\Payments\Model\ResourceModel\StripeCustomer\Collection $customerCollection,
        \StripeIntegration\Payments\Helper\TaxHelper $taxHelper,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\Catalog\Helper\ImageFactory $imageFactory,
        \StripeIntegration\Payments\Helper\ApiFactory $apiFactory,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Model\PaymentIntentFactory $paymentIntentFactory,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        \Magento\SalesRule\Model\CouponFactory $couponFactory,
        \StripeIntegration\Payments\Model\CouponFactory $stripeCouponFactory,
        \Magento\Checkout\Helper\Data $checkoutHelper,
        \Magento\SalesRule\Api\RuleRepositoryInterface $ruleRepository,
        \Magento\Sales\Api\Data\TransactionSearchResultInterfaceFactory $transactionSearchResultFactory,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \Magento\Tax\Model\Config $taxConfig,
        \StripeIntegration\Payments\Helper\SubscriptionQuote $subscriptionQuote,
        \Magento\Bundle\Model\OptionFactory $bundleOptionFactory,
        \Magento\Bundle\Model\Product\TypeFactory $bundleProductTypeFactory,
        \Magento\Directory\Model\CurrencyFactory $currencyFactory,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Api\InvoiceRepositoryInterface $invoiceRepository,
        \Magento\Sales\Api\TransactionRepositoryInterface $transactionRepository,
        \Magento\Sales\Api\CreditmemoRepositoryInterface $creditmemoRepository,
        \Magento\Framework\Session\SessionManagerInterface $sessionManager,
        \StripeIntegration\Payments\Model\Multishipping\QuoteFactory $multishippingQuoteFactory
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->backendSessionQuote = $backendSessionQuote;
        $this->request = $request;
        $this->logger = $logger;
        $this->appState = $appState;
        $this->storeManager = $storeManager;
        $this->order = $order;
        $this->invoice = $invoice;
        $this->invoiceService = $invoiceService;
        $this->creditmemo = $creditmemo;
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->resource = $resource;
        $this->coreRegistry = $coreRegistry;
        $this->adminOrderAddressForm = $adminOrderAddressForm;
        $this->customerRegistry = $customerRegistry;
        $this->messageManager = $messageManager;
        $this->productFactory = $productFactory;
        $this->quoteFactory = $quoteFactory;
        $this->orderFactory = $orderFactory;
        $this->cart = $cart;
        $this->invoiceCommentFactory = $invoiceCommentFactory;
        $this->customerAddress = $customerAddress;
        $this->apiResponse = $apiResponse;
        $this->transactionFactory = $transactionFactory;
        $this->requestInterface = $requestInterface;
        $this->urlBuilder = $urlBuilder;
        $this->pricingHelper = $pricingHelper;
        $this->cache = $cache;
        $this->encryptor = $encryptor;
        $this->userContext = $userContext;
        $this->orderSender = $orderSender;
        $this->priceCurrency = $priceCurrency;
        $this->customerRepositoryInterface = $customerRepositoryInterface;
        $this->orderCommentSender = $orderCommentSender;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoService = $creditmemoService;
        $this->invoiceManagement = $invoiceManagement;
        $this->paymentExtensionFactory = $paymentExtensionFactory;
        $this->customerCollection = $customerCollection;
        $this->taxHelper = $taxHelper;
        $this->productRepository = $productRepository;
        $this->imageFactory = $imageFactory;
        $this->apiFactory = $apiFactory;
        $this->addressHelper = $addressHelper;
        $this->paymentIntentFactory = $paymentIntentFactory;
        $this->quoteRepository = $quoteRepository;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->couponFactory = $couponFactory;
        $this->stripeCouponFactory = $stripeCouponFactory;
        $this->checkoutHelper = $checkoutHelper;
        $this->ruleRepository = $ruleRepository;
        $this->invoiceSender = $invoiceSender;
        $this->quoteHelper = $quoteHelper;
        $this->taxConfig = $taxConfig;
        $this->transactionSearchResultFactory = $transactionSearchResultFactory;
        $this->subscriptionQuote = $subscriptionQuote;
        $this->bundleOptionFactory = $bundleOptionFactory;
        $this->bundleProductTypeFactory = $bundleProductTypeFactory;
        $this->currencyFactory = $currencyFactory;
        $this->orderRepository = $orderRepository;
        $this->invoiceRepository = $invoiceRepository;
        $this->transactionRepository = $transactionRepository;
        $this->creditmemoRepository = $creditmemoRepository;
        $this->sessionManager = $sessionManager;
        $this->multishippingQuoteFactory = $multishippingQuoteFactory;
    }

    public function getProductImage($product, $type = 'product_thumbnail_image')
    {
        return $this->imageFactory->create()
            ->init($product, $type)
            ->setImageFile($product->getSmallImage()) // image,small_image,thumbnail
            ->resize(380)
            ->getUrl();
    }

    public function getBackendSessionQuote()
    {
        return $this->backendSessionQuote->getQuote();
    }

    public function isSecure()
    {
        return $this->request->isSecure();
    }

    public function getSessionQuote()
    {
        return $this->checkoutSession->getQuote();
    }

    public function getQuote($quoteId = null)
    {
        // Admin area new order page
        if ($this->isAdmin())
            return $this->getBackendSessionQuote();

        // Front end checkout
        $quote = $this->getSessionQuote();

        // API Request
        if (empty($quote) || !is_numeric($quote->getGrandTotal()))
        {
            if ($quoteId)
                $quote = $this->quoteRepository->get($quoteId);
            else if ($this->quoteHelper->quoteId)
                $quote = $this->quoteRepository->get($this->quoteHelper->quoteId);
        }

        return $quote;
    }

    public function getStoreId()
    {
        if ($this->isAdmin())
        {
            if ($this->request->getParam('order_id', null))
            {
                // Viewing an order
                $order = $this->order->load($this->request->getParam('order_id', null));
                return $order->getStoreId();
            }
            if ($this->request->getParam('invoice_id', null))
            {
                // Viewing an invoice
                $invoice = $this->invoice->load($this->request->getParam('invoice_id', null));
                return $invoice->getStoreId();
            }
            else if ($this->request->getParam('creditmemo_id', null))
            {
                // Viewing a credit memo
                $creditmemo = $this->creditmemo->load($this->request->getParam('creditmemo_id', null));
                return $creditmemo->getStoreId();
            }
            else
            {
                // Creating a new order
                $quote = $this->getBackendSessionQuote();
                return $quote->getStoreId();
            }
        }
        else
        {
            return $this->storeManager->getStore()->getId();
        }
    }

    public function getCurrentStore()
    {
        return $this->storeManager->getStore();
    }

    public function loadProductBySku($sku)
    {
        try
        {
            return $this->productRepository->get($sku);
        }
        catch (\Exception $e)
        {
            return null;
        }
    }

    public function loadProductById($productId)
    {
        if (!isset($this->products))
            $this->products = [];

        if (!empty($this->products[$productId]))
            return $this->products[$productId];

        $this->products[$productId] = $this->productFactory->create()->load($productId);

        return $this->products[$productId];
    }

    public function loadQuoteById($quoteId)
    {
        if (!isset($this->quotes))
            $this->quotes = [];

        if (!empty($this->quotes[$quoteId]))
            return $this->quotes[$quoteId];

        $this->quotes[$quoteId] = $this->quoteFactory->create()->load($quoteId);

        return $this->quotes[$quoteId];
    }

    public function loadOrderByIncrementId($incrementId)
    {
        if (!isset($this->orders))
            $this->orders = [];

        if (!empty($this->orders[$incrementId]))
            return $this->orders[$incrementId];

        try
        {
            $order = $this->orderFactory->create()->loadByIncrementId($incrementId);

            if ($order && $order->getId())
                return $this->orders[$incrementId] = $order;
        }
        catch (\Exception $e)
        {
            return null;
        }
    }

    public function loadOrderById($orderId)
    {
        return $this->orderFactory->create()->load($orderId);
    }

    public function loadCustomerById($customerId)
    {
        return $this->customerRepositoryInterface->getById($customerId);
    }

    public function createInvoiceComment($msg, $notify = false, $visibleOnFront = false)
    {
        return $this->invoiceCommentFactory->create()
            ->setComment($msg)
            ->setIsCustomerNotified($notify)
            ->setIsVisibleOnFront($visibleOnFront);
    }

    public function isAdmin()
    {
        $areaCode = $this->appState->getAreaCode();

        return $areaCode == \Magento\Backend\App\Area\FrontNameResolver::AREA_CODE;
    }

    public function isAPIRequest()
    {
        $areaCode = $this->appState->getAreaCode();

        switch ($areaCode)
        {
            case 'webapi_rest': // \Magento\Framework\App\Area::AREA_WEBAPI_REST:
            case 'webapi_soap': // \Magento\Framework\App\Area::AREA_WEBAPI_SOAP:
            case 'graphql': // \Magento\Framework\App\Area::AREA_GRAPHQL: - Magento 2.1 doesn't have the constant
                return true;
            default:
                return false;
        }
    }

    public function isCustomerLoggedIn()
    {
        return $this->customerSession->isLoggedIn();
    }

    public function getCustomerId()
    {
        // If we are in the back office
        if ($this->isAdmin())
        {
            // About to refund/invoice an order
            if ($order = $this->coreRegistry->registry('current_order'))
                return $order->getCustomerId();

            // About to capture an invoice
            if ($invoice = $this->coreRegistry->registry('current_invoice'))
                return $invoice->getCustomerId();

            // Creating a new order from admin
            if ($this->adminOrderAddressForm && $this->adminOrderAddressForm->getCustomerId())
                return $this->adminOrderAddressForm->getCustomerId();
        }
        // If we are on the REST API
        else if ($this->userContext->getUserType() == UserContextInterface::USER_TYPE_CUSTOMER)
        {
            return $this->userContext->getUserId();
        }
        // If we are on the checkout page
        else if ($this->customerSession->isLoggedIn())
        {
            return $this->customerSession->getCustomerId();
        }
        // A webhook has instantiated this object
        else if (!empty($this->magentoCustomerId))
        {
            return $this->magentoCustomerId;
        }

        return null;
    }

    public function getMagentoCustomer()
    {
        if ($this->customerSession->getCustomer()->getEntityId())
            return $this->customerSession->getCustomer();

        $customerId = $this->getCustomerId();
        if (!$customerId) return;

        $customer = $this->customerRegistry->retrieve($customerId);

        if ($customer->getEntityId())
            return $customer;

        return null;
    }

    public function isGuest()
    {
        return !$this->customerSession->isLoggedIn();
    }

    // Should return the email address of guest customers
    public function getCustomerEmail()
    {
        $customer = $this->getMagentoCustomer();

        if (!$customer)
            $customer = $this->getGuestCustomer();

        if (!$customer)
            return null;

        return trim(strtolower($customer->getEmail()));
    }

    public function getGuestCustomer($order = null)
    {
        if ($order)
        {
            return $this->getAddressFrom($order, 'billing');
        }
        else if (isset($this->_order))
        {
            return $this->getAddressFrom($this->_order, 'billing');
        }
        else
            return null;
    }

    public function getCustomerDefaultBillingAddress()
    {
        $customer = $this->getMagentoCustomer();
        if (!$customer) return null;

        $addressId = $customer->getDefaultBilling();
        if (!$addressId) return null;

        $this->customerAddress->clearInstance();
        $address = $this->customerAddress->load($addressId);
        return $address;
    }

    public function getCustomerBillingAddress()
    {
        $quote = $this->getSessionQuote();
        if (empty($quote))
            return null;

        return $quote->getBillingAddress();
    }

    public function getMultiCurrencyAmount($payment, $baseAmount)
    {
        $order = $payment->getOrder();
        $grandTotal = $order->getGrandTotal();
        $baseGrandTotal = $order->getBaseGrandTotal();

        $rate = $order->getBaseToOrderRate();
        if ($rate == 0) $rate = 1;

        // Full capture, ignore currency rate in case it changed
        if ($baseAmount == $baseGrandTotal)
            return $grandTotal;
        // Partial capture, consider currency rate but don't capture more than the original amount
        else if (is_numeric($rate))
            return min($baseAmount * $rate, $grandTotal);
        // Not a multicurrency capture
        else
            return $baseAmount;
    }

    public function getAddressFrom($order, $addressType = 'shipping')
    {
        if (!$order) return null;

        $addresses = $order->getAddresses();
        if (!empty($addresses))
        {
            foreach ($addresses as $address)
            {
                if ($address["address_type"] == $addressType)
                    return $address;
            }
        }
        else if ($addressType == "shipping" && $order->getShippingAddress() && $order->getShippingAddress()->getStreet(1))
        {
            return $order->getShippingAddress();
        }
        else if ($addressType == "billing" && $order->getBillingAddress() && $order->getBillingAddress()->getStreet(1))
        {
            return $order->getBillingAddress();
        }

        return null;
    }

    // Do not use Config::isSubscriptionsEnabled(), a circular dependency injection will appear
    public function isSubscriptionsEnabled()
    {
        $storeId = $this->getStoreId();

        $data = $this->scopeConfig->getValue("payment/stripe_payments_subscriptions/active", ScopeInterface::SCOPE_STORE, $storeId);

        return (bool)$data;
    }

    private function getProductOptionFor($item)
    {
        if (!$item->getParentItem())
            return null;

        $name = $item->getName();

        if ($productOptions = $item->getParentItem()->getProductOptions())
        {
            if (!empty($productOptions["bundle_options"]))
            {
                foreach ($productOptions["bundle_options"] as $bundleOption)
                {
                    if (!empty($bundleOption["value"]))
                    {
                        foreach ($bundleOption["value"] as $value)
                        {
                            if ($value["title"] == $name)
                            {
                                return $value;
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    private function getSubscriptionQuoteItemFromBundle($item, $qty, $order)
    {
        $name = $item->getName();
        $productId = $item->getProductId();

        $parentQty = (($item->getParentItem() && $item->getParentItem()->getQty()) ? $item->getParentItem()->getQty() : 1);
        if ($item->getQty())
            $qty = $item->getQty() * $parentQty;

        if ($productOption = $this->getProductOptionFor($item)) // Order
        {
            if (!empty($productOption["price"]))
                $customPrice = $productOption["price"] / $parentQty;
            else
                $customPrice = $item->getPrice();

            // @todo: Report Magento bug between quote and order prices
            if ($order->getIncrementId())
                $newQuote = $this->subscriptionQuote->createNewQuoteFrom($order, $productId, $qty, null, $customPrice);
            else
                $newQuote = $this->subscriptionQuote->createNewQuoteFrom($order, $productId, $qty, $customPrice);

            foreach ($newQuote->getAllItems() as $newQuoteItem)
                return $newQuoteItem;
        }
        else if ($qtyOptions = $item->getParentItem()->getQtyOptions()) // Quote
        {
            $selections = $this->getBundleSelections($this->getStoreId(), $productId, $item->getParentItem()->getProduct());
            foreach ($qtyOptions as $qtyOption)
            {
                if ($qtyOption->getProductId() == $productId)
                {
                    $customPrice = $item->getProduct()->getPrice();
                    foreach ($selections as $selection)
                    {
                        if ($selection->getProductId() == $productId)
                        {
                            if ($selection->getSelectionPriceType() == 0) // 0 - fixed, 1 - percent
                            {
                                if ($selection->getSelectionPriceValue() && $selection->getSelectionPriceValue() > 0)
                                {
                                    $customPrice = $selection->getSelectionPriceValue();
                                }
                                else if ($selection->getPrice() && $selection->getPrice() > 0)
                                {
                                    $customPrice = $selection->getPrice();
                                }
                            }
                            else if ($selection->getSelectionPriceType() == 1)
                            {
                                $percent = $selection->getSelectionPriceValue();
                                // @todo - percent prices is not implemented
                                $this->dieWithError(__("Unsupported bundle subscription."));
                            }

                            break;
                        }
                    }

                    if ($order->getIncrementId())
                        $newQuote = $this->subscriptionQuote->createNewQuoteFrom($order, $productId, $qty, null, $customPrice);
                    else
                        $newQuote = $this->subscriptionQuote->createNewQuoteFrom($order, $productId, $qty, $customPrice);

                    foreach ($newQuote->getAllItems() as $newQuoteItem)
                        return $newQuoteItem;
                }
            }
        }

        $this->dieWithError(__("Unsupported bundle subscription."));
    }

    public function getBundleSelections($storeId, $productId, $product)
    {
        $options = $this->bundleOptionFactory->create()
            ->getResourceCollection()
            ->setProductIdFilter($productId)
            ->setPositionOrder();
        $options->joinValues($storeId);
        $typeInstance = $this->bundleProductTypeFactory->create();
        $selections = $typeInstance->getSelectionsCollection($typeInstance->getOptionsIds($product), $product);
        return $selections;
    }

    public function getItemQty($item)
    {
        $qty = max(/* quote */ $item->getQty(), /* order */ $item->getQtyOrdered());

        if ($item->getParentItem() && $item->getParentItem()->getProductType() == "configurable")
        {
            if (is_numeric($item->getParentItem()->getQty()))
                $qty *= $item->getParentItem()->getQty();
        }
        else if ($item->getParentItem() && $item->getParentItem()->getProductType() == "bundle")
        {
            if ($productOption = $this->getProductOptionFor($item))
            {
                if (!empty($productOption["qty"]))
                    $qty *= $productOption["qty"];
            }
        }

        return $qty;
    }

    public function getSubscriptionQuoteItemWithTotalsFrom($item, $order)
    {
        $qty = max(/* quote */ $item->getQty(), /* order */ $item->getQtyOrdered());

        if ($item->getParentItem() && $item->getParentItem()->getProductType() == "configurable")
        {
            return $item->getParentItem();
        }
        else if ($item->getParentItem() && $item->getParentItem()->getProductType() == "bundle")
        {
            return $this->getSubscriptionQuoteItemFromBundle($item, $qty, $order);
        }
        else
            return $item;
    }

    /**
     * Description
     * @param object $orderItem
     * @return \Magento\Catalog\Model\Product|null
     */
    public function getSubscriptionProductFromOrderItem($item)
    {
        if (!in_array($item->getProductType(), ["simple", "virtual"]))
            return null;

        $product = $this->loadProductById($item->getProductId());

        if ($product && $product->getStripeSubEnabled())
            return $product;

        return null;
    }

    public function isOrIncludesSubscription($orderItem)
    {
        $ids = $this->getSubscriptionIdsFromOrderItem($orderItem);
        return !empty($ids);
    }

    public function getSubscriptionProductsFromOrderItem($orderItem)
    {
        $products = [];
        $subscriptionProductIds = $this->getSubscriptionIdsFromOrderItem($orderItem);

        foreach ($subscriptionProductIds as $subscriptionProductId)
        {
            $products[$subscriptionProductId] = $this->loadProductById($subscriptionProductId);
        }

        return $products;
    }

    public function getSubscriptionIdsFromOrderItem($orderItem)
    {
        $ids = [];

        $type = $orderItem->getProductType();

        if ($type == "downloadable")
            return $ids;

        if (in_array($type, ["simple", "virtual"]))
        {
            $product = $this->loadProductById($orderItem->getProductId());
            if ($product->getStripeSubEnabled())
                return [ $orderItem->getProductId() ];
        }

        if ($type == "configurable")
        {
            foreach($orderItem->getChildrenItems() as $item)
            {
                $product = $this->loadProductById($item->getProductId());
                if ($product->getStripeSubEnabled())
                    $ids[] = $item->getProductId();
            }

            return $ids;
        }

        if ($type == "bundle")
        {
            $productIds = $this->getSelectedProductIdsFromBundleOrderItem($orderItem);

            foreach($productIds as $productId)
            {
                $product = $this->loadProductById($productId);
                if ($product->getStripeSubEnabled())
                    $ids[] = $productId;
            }

            return $ids;
        }

        return $ids;
    }

    public function getBundleProductOptionsData($productId)
    {
        if (!empty($this->bundleProductOptions[$productId]))
            return $this->bundleProductOptions[$productId];

        $product = $this->loadProductById($productId);

        $selectionCollection = $product->getTypeInstance(true)
            ->getSelectionsCollection(
                $product->getTypeInstance(true)->getOptionsIds($product),
                $product
            );

        $productsArray = [];

        foreach ($selectionCollection as $selection)
        {
            $selectionArray = [];
            $selectionArray['name'] = $selection->getName();
            $selectionArray['quantity'] = $selection->getSelectionQty();
            $selectionArray['price'] = $selection->getPrice();
            $selectionArray['product_id'] = $selection->getProductId();
            $productsArray[$selection->getOptionId()][$selection->getSelectionId()] = $selectionArray;
        }

        return $this->bundleProductOptions[$productId] = $productsArray;
    }

    public function getSelectedProductIdsFromBundleOrderItem($orderItem)
    {
        if ($orderItem->getProductType() != "bundle")
            return [];

        $productOptions = $orderItem->getProductOptions();
        if (empty($productOptions))
            return [];

        if (empty($productOptions["info_buyRequest"]["bundle_option"]))
            return [];

        $bundleOption = $productOptions["info_buyRequest"]["bundle_option"];

        $bundleData = $this->getBundleProductOptionsData($orderItem->getProductId());
        if (empty($bundleData))
            return [];

        $productIds = [];

        foreach ($bundleOption as $optionId => $option)
        {
            if (is_numeric($option) && !empty($bundleData[$optionId][$option]["product_id"]))
            {
                $productId = $bundleData[$optionId][$option]["product_id"];
                $productIds[$productId] = $productId;
            }
            else
            {
                foreach ($option as $selectionId => $selection)
                {
                    if (!empty($bundleData[$optionId][$selectionId]["product_id"]))
                    {
                        $productId = $bundleData[$optionId][$selectionId]["product_id"];
                        $productIds[$productId] = $productId;
                    }
                }
            }
        }

        return $productIds;
    }

    public function getSelectedProductIdsFromBundleQuoteItem($quoteItem)
    {
        if ($quoteItem->getProductType() != "bundle")
            return [];

        $productOptions = $quoteItem->getProductOptions();
        if (empty($productOptions))
            return [];

        if (empty($productOptions["info_buyRequest"]["bundle_option"]))
            return [];

        $bundleOption = $productOptions["info_buyRequest"]["bundle_option"];

        $bundleData = $this->getBundleProductOptionsData($quoteItem->getProductId());
        if (empty($bundleData))
            return [];

        $productIds = [];

        foreach ($bundleOption as $optionId => $option)
        {
            if (is_numeric($option) && !empty($bundleData[$optionId][$option]["product_id"]))
            {
                $productId = $bundleData[$optionId][$option]["product_id"];
                $productIds[$productId] = $productId;
            }
            else
            {
                foreach ($option as $selectionId => $selection)
                {
                    if (!empty($bundleData[$optionId][$selectionId]["product_id"]))
                    {
                        $productId = $bundleData[$optionId][$selectionId]["product_id"];
                        $productIds[$productId] = $productId;
                    }
                }
            }
        }

        return $productIds;
    }

    /**
     * Description
     * @param array<\Magento\Sales\Model\Order\Item> $items
     * @return bool
     */
    public function hasSubscriptionsIn($items, $returnSubscriptions = false)
    {
        if (!$this->isSubscriptionsEnabled())
            return false;

        if (empty($items))
            return false;

        foreach ($items as $item)
        {
            $product = $this->getSubscriptionProductFromOrderItem($item);
            if ($product)
                return true;
        }

        return false;
    }

    public function hasTrialSubscriptions($quote = null)
    {
        if (isset($this->_hasTrialSubscriptions) && $this->_hasTrialSubscriptions)
            return true;

        if (!$quote)
            $quote = $this->getQuote();

        $items = $quote->getAllItems();

        return $this->_hasTrialSubscriptions = $this->hasTrialSubscriptionsIn($items);
    }

    /**
     * Description
     * @param array<\Magento\Sales\Model\Order\Item> $items
     * @return bool
     */
    public function hasTrialSubscriptionsIn($items)
    {
        if (!$this->isSubscriptionsEnabled())
            return false;

        foreach ($items as $item)
        {
            $product = $this->getSubscriptionProductFromOrderItem($item);
            if (!$product)
                continue;

            $trial = $product->getStripeSubTrial();
            if (is_numeric($trial) && $trial > 0)
                return true;
            else
                continue;
        }

        return false;
    }

    public function hasOnlySubscriptionsIn($items)
    {
        if (!$this->isSubscriptionsEnabled())
            return false;

        foreach ($items as $item)
        {
            $product = $this->getSubscriptionProductFromOrderItem($item);
            if (!$product)
                continue;

            if ($product->getStripeSubEnabled())
                return true;
        }

        return false;
    }

    public function hasOnlyTrialSubscriptionsIn($items)
    {
        if (!$this->isSubscriptionsEnabled())
            return false;

        $found = false;

        foreach ($items as $item)
        {
            $product = $this->getSubscriptionProductFromOrderItem($item);
            if (!$product)
                continue;

            $trial = $product->getStripeSubTrial();
            if (is_numeric($trial) && $trial > 0)
                $found = true;
            else
                return false;
        }

        return $found;
    }

    public function hasSubscriptions($quote = null)
    {
        if (empty($quote))
            $quote = $this->getQuote();

        if (empty($quote))
            return false;

        $quoteId = $quote->getId();
        if (isset($this->quoteHasSubscriptions[$quoteId]))
            return $this->quoteHasSubscriptions[$quoteId];

        if ($quote)
            $items = $quote->getAllItems();
        else
            $items = $this->getQuote()->getAllItems();

        return $this->quoteHasSubscriptions[$quoteId] = $this->hasSubscriptionsIn($items);
    }

    public function hasOnlyTrialSubscriptions($quote = null)
    {
        if (!$quote)
            $quote = $this->getQuote();

        if ($quote && $quote->getId() && isset($this->hasOnlyTrialSubscriptions[$quote->getId()]))
            return $this->hasOnlyTrialSubscriptions[$quote->getId()];

        $items = $quote->getAllItems();

        return $this->hasOnlyTrialSubscriptions[$quote->getId()] = $this->hasOnlyTrialSubscriptionsIn($items);
    }

    public function isZeroDecimal($currency)
    {
        return in_array(strtolower($currency), array(
            'bif', 'djf', 'jpy', 'krw', 'pyg', 'vnd', 'xaf',
            'xpf', 'clp', 'gnf', 'kmf', 'mga', 'rwf', 'vuv', 'xof'));
    }

    public function isAuthorizationExpired($charge)
    {
        if (!$charge->refunded)
            return false;

        if (empty($charge->refunds->data[0]->reason))
            return false;

        if ($charge->refunds->data[0]->reason == "expired_uncaptured_charge")
            return true;

        return false;
    }

    public function addWarning($msg)
    {
        if (is_string($msg))
            $msg = __($msg);

        if ($this->isAdmin())
            $this->messageManager->addWarning($msg);
        else if ($this->isMultiShipping())
            $this->messageManager->addWarningMessage($msg);
    }

    public function addError($msg)
    {
        if (is_string($msg))
            $msg = __($msg);

        if ($this->isMultiShipping())
            $this->messageManager->addErrorMessage( $msg );
        else
            $this->messageManager->addError( $msg );
    }

    public function addSuccess($msg)
    {
        if (is_string($msg))
            $msg = __($msg);

        if ($this->isMultiShipping())
            $this->messageManager->addSuccessMessage( $msg );
        else
            $this->messageManager->addSuccess( $msg );
    }

    public function logError($msg, $trace = null)
    {
        if (!$this->isAuthenticationRequiredMessage($msg))
        {
            $entry = Config::module() . ": " . $msg;

            if ($trace)
                $entry .= "\n$trace";

            \StripeIntegration\Payments\Helper\Logger::log($entry);
        }
    }

    public function logInfo($msg)
    {
        $entry = Config::module() . ": " . $msg;
        \StripeIntegration\Payments\Helper\Logger::logInfo($entry);
    }

    public function isStripeAPIKeyError($msg)
    {
        $pos1 = stripos($msg, "Invalid API key provided");
        $pos2 = stripos($msg, "No API key provided");
        if ($pos1 !== false || $pos2 !== false)
            return true;

        return false;
    }

    public function cleanError($msg)
    {
        if ($this->isStripeAPIKeyError($msg))
            return "Invalid Stripe API key provided.";

        return $msg;
    }

    public function isMultiShipping($quote = null)
    {
        if (empty($quote))
            $quote = $this->getQuote();

        if (empty($quote))
            return false;

        return $quote->getIsMultiShipping();
    }

    public function shouldLogExceptionTrace($e)
    {
        if (empty($e))
            return false;

        $msg = $e->getMessage();
        if ($this->isAuthenticationRequiredMessage($msg))
            return false;

        if (get_class($e) == \Stripe\Exception\CardException::class) // i.e. card declined, insufficient funds etc
            return false;

        if (get_class($e) == \Magento\Framework\Exception\CouldNotSaveException::class)
        {
            switch ($msg)
            {
                case "Your card was declined.":
                    return false;
                default:
                    break;
            }
        }

        return true;
    }

    public function dieWithError($msg, $e = null)
    {
        $this->logError($msg);

        if ($this->shouldLogExceptionTrace($e))
        {
            if ($e->getMessage() != $msg)
                $this->logError($e->getMessage());

            $this->logError($e->getTraceAsString());
        }

        if ($this->isAdmin())
            throw new CouldNotSaveException(__($msg));
        else if ($this->isAPIRequest())
            throw new CouldNotSaveException(__($this->cleanError($msg)), $e);
        else if ($this->isMultiShipping())
            throw new \Magento\Framework\Exception\LocalizedException(__($msg), $e);
        else
        {
            // We return in direct controller requests which already have their own error handlers
            // and during integration testing.
            $error = $this->cleanError($msg);
            $this->addError($error);
            return $error;
        }
    }

    public function maskException($e)
    {
        if (strpos($e->getMessage(), "Received unknown parameter: payment_method_options[card][moto]") === 0)
            $message = "You have enabled MOTO exemptions from the Stripe module configuration section, but your Stripe account has not been gated to use MOTO exemptions. Please contact magento@stripe.com to request MOTO enabled for your Stripe account.";
        else
            $message = $e->getMessage();

        return $this->dieWithError($message, $e);
    }

    public function isValidToken($token)
    {
        if (!is_string($token))
            return false;

        if (!strlen($token))
            return false;

        if (strpos($token, "_") === FALSE)
            return false;

        return true;
    }

    public function captureOrder($order)
    {
        foreach($order->getInvoiceCollection() as $invoice)
        {
            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
            $invoice->capture();
            $this->invoiceRepository->save($invoice);
        }
    }

    public function getInvoiceAmounts($invoice, $details)
    {
        $currency = strtolower($details['currency']);
        $cents = 100;
        if ($this->isZeroDecimal($currency))
            $cents = 1;
        $amount = ($details['amount'] / $cents);
        $baseAmount = round($amount / $invoice->getBaseToOrderRate(), 2);

        if (!empty($details["shipping"]))
        {
            $shipping = ($details['shipping'] / $cents);
            $baseShipping = round($shipping / $invoice->getBaseToOrderRate(), 2);
        }
        else
        {
            $shipping = 0;
            $baseShipping = 0;
        }

        if (!empty($details["tax"]))
        {
            $tax = ($details['tax'] / $cents);
            $baseTax = round($tax / $invoice->getBaseToOrderRate(), 2);
        }
        else
        {
            $tax = 0;
            $baseTax = 0;
        }

        return [
            "amount" => $amount,
            "base_amount" => $baseAmount,
            "shipping" => $shipping,
            "base_shipping" => $baseShipping,
            "tax" => $tax,
            "base_tax" => $baseTax
        ];
    }

    // Used for partial invoicing triggered from a partial Stripe dashboard capture
    public function adjustInvoiceAmounts(&$invoice, $details)
    {
        if (!is_array($details))
            return;

        $amounts = $this->getInvoiceAmounts($invoice, $details);
        $amount = $amounts['amount'];
        $baseAmount = $amounts['base_amount'];

        if ($invoice->getGrandTotal() != $amount)
        {
            if (!empty($amounts['shipping']))
                $invoice->setShippingAmount($amounts['shipping']);

            if (!empty($amounts['base_shipping']))
                $invoice->setBaseShippingAmount($amounts['base_shipping']);

            if (!empty($amounts['tax']))
                $invoice->setTaxAmount($amounts['tax']);

            if (!empty($amounts['base_tax']))
                $invoice->setBaseTaxAmount($amounts['base_tax']);

            $invoice->setGrandTotal($amount);
            $invoice->setBaseGrandTotal($baseAmount);

            $subtotal = 0;
            $baseSubtotal = 0;
            $items = $invoice->getAllItems();
            foreach ($items as $item)
            {
                $subtotal += $item->getRowTotal();
                $baseSubtotal += $item->getBaseRowTotal();
            }

            $invoice->setSubtotal($subtotal);
            $invoice->setBaseSubtotal($baseSubtotal);
        }
    }

    public function invoiceSubscriptionOrder($order, $transactionId = null, $captureCase = \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE, $amount = null, $save = true)
    {
        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase($captureCase);

        if ($transactionId)
        {
            $invoice->setTransactionId($transactionId);
            $order->getPayment()->setLastTransId($transactionId);
        }

        $this->adjustInvoiceAmounts($invoice, $amount);

        $invoice->register();

        $comment = __("Captured payment of %1 through Stripe.", $order->formatPrice($invoice->getGrandTotal()));
        $order->addStatusToHistory($status = 'processing', $comment, $isCustomerNotified = false);

        if ($save)
        {
            $this->saveInvoice($invoice);
            $this->saveOrder($order);
        }

        try
        {
            $this->invoiceSender->send($invoice);
        }
        catch (\Exception $e)
        {
            $this->logError($e->getMessage(), $e->getTraceAsString());
        }

        return $invoice;
    }

    public function invoiceOrder($order, $transactionId = null, $captureCase = \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE, $amount = null, $save = true)
    {
        // This will kick in with "Authorize Only" mode orders, but not with "Authorize & Capture"
        if ($order->canInvoice())
        {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase($captureCase);

            if ($transactionId)
            {
                $invoice->setTransactionId($transactionId);
                $order->getPayment()->setLastTransId($transactionId);
            }

            $this->adjustInvoiceAmounts($invoice, $amount);

            $invoice->register();

            if ($save)
            {
                $this->saveInvoice($invoice);
                $this->saveOrder($order);
            }

            return $invoice;
        }
        // Invoices have already been generated with either Authorize Only or Authorize & Capture, but have not actually been captured because
        // the source is not chargeable yet. These should have a pending status.
        else
        {
            foreach($order->getInvoiceCollection() as $invoice)
            {
                if ($invoice->canCapture())
                {
                    $invoice->setRequestedCaptureCase($captureCase);

                    $this->adjustInvoiceAmounts($invoice, $amount);

                    if ($transactionId && !$invoice->getTransactionId())
                    {
                        $invoice->setTransactionId($transactionId);
                        $order->getPayment()->setLastTransId($transactionId);
                    }

                    $invoice->pay();

                    if ($save)
                    {
                        $this->saveInvoice($invoice);
                        $this->saveOrder($order);
                    }

                    return $invoice;
                }
            }
        }

        return null;
    }

    // Pending orders are the ones that were placed with an asynchronous payment method, such as SOFORT or SEPA Direct Debit,
    // which may finalize the charge after several days or weeks
    public function invoicePendingOrder($order, $transactionId = null, $amount = null)
    {
        if (!$order->canInvoice())
            throw new \Exception("Order #" . $order->getIncrementId() . " cannot be invoiced.");

        $invoice = $this->invoiceService->prepareInvoice($order);

        if ($transactionId)
        {
            $captureCase = \Magento\Sales\Model\Order\Invoice::NOT_CAPTURE;
            $invoice->setTransactionId($transactionId);
            $order->getPayment()->setLastTransId($transactionId);
        }
        else
        {
            $captureCase = \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE;
        }

        $invoice->setRequestedCaptureCase($captureCase);

        $this->adjustInvoiceAmounts($invoice, $amount);

        $invoice->register();

        $this->saveInvoice($invoice);
        $this->saveOrder($order);

        return $invoice;
    }

    public function cancelOrCloseOrder($order, $refundInvoices = false, $refundOffline = true)
    {
        $canceled = false;

        // When in Authorize & Capture, uncaptured invoices exist, so we should cancel them first
        foreach($order->getInvoiceCollection() as $invoice)
        {
            if ($invoice->canCancel())
            {
                $invoice->cancel();
                $this->saveInvoice($invoice);
                $canceled = true;
            }
            else if ($refundInvoices)
            {
                $creditmemo = $this->creditmemoFactory->createByOrder($order);
                $creditmemo->setInvoice($invoice);
                $this->creditmemoService->refund($creditmemo, $refundOffline);
                $this->saveCreditmemo($creditmemo);
                $canceled = true;
            }
        }

        // When there are no invoices, the order can be canceled
        if ($order->canCancel())
        {
            $order->cancel();
            $canceled = true;
        }

        $this->saveOrder($order);

        return $canceled;
    }

    public function getSanitizedBillingInfo()
    {
        // This method is unnecessary in M2, the checkout passes the correct billing details
    }

    public function retrieveSource($token)
    {
        if (isset($this->sources[$token]))
            return $this->sources[$token];

        $this->sources[$token] = \Stripe\Source::retrieve($token);

        return $this->sources[$token];
    }

    public function maskError($msg)
    {
        if (stripos($msg, "You must verify a phone number on your Stripe account") === 0)
            return $msg;

        return false;
    }

    // Removes decorative strings that Magento adds to the transaction ID
    public function cleanToken($token)
    {
        return preg_replace('/-.*$/', '', $token);
    }

    public function retrieveCard($customer, $token)
    {
        if (isset($this->cards[$token]))
            return $this->cards[$token];

        $card = $customer->sources->retrieve($token);
        $this->cards[$token] = $card;

        return $card;
    }

    public function convertPaymentMethodToCard($paymentMethod)
    {
        if (!$paymentMethod || empty($paymentMethod->card))
            return null;

        $card = json_decode(json_encode($paymentMethod->card));
        $card->id = $paymentMethod->id;

        return $card;
    }

    public function cardType($code)
    {
        switch ($code) {
            case 'visa': return "Visa";
            case 'amex': return "American Express";
            case 'mastercard': return "MasterCard";
            case 'discover': return "Discover";
            case 'diners': return "Diners Club";
            case 'jcb': return "JCB";
            case 'unionpay': return "UnionPay";
            case 'cartes_bancaires': return "Cartes Bancaires";
            case null:
            case "":
                return "Card";
            default:
                return ucfirst($code);
        }
    }

    public function listCards($customer, $params = array())
    {
        try
        {
            $sources = $customer->sources;
            if (!empty($sources))
            {
                $cards = [];

                // Cards created through the Payment Methods API
                $data = \Stripe\PaymentMethod::all(['customer' => $customer->id, 'type' => 'card', 'limit' => 100]);
                foreach ($data->autoPagingIterator() as $pm)
                {
                    $cards[] = $this->convertPaymentMethodToCard($pm);
                }

                return $cards;
            }
            else
                return null;
        }
        catch (\Exception $e)
        {
            return null;
        }
    }

    public function findCard($customer, $last4, $expMonth, $expYear)
    {
        $cards = $this->listCards($customer);
        foreach ($cards as $card)
        {
            if ($last4 == $card->last4 &&
                $expMonth == $card->exp_month &&
                $expYear == $card->exp_year)
            {
                return $card;
            }
        }

        return false;
    }

    public function findCardByFingerprint($customer, $fingerprint)
    {
        $cards = $this->listCards($customer);
        foreach ($cards as $card)
        {
            if ($card->fingerprint == $fingerprint)
            {
                return $card;
            }
        }

        return false;
    }

    public function formatStripePrice($price, $currency = null)
    {
        $precision = 0;
        if (!$this->isZeroDecimal($currency))
        {
            $price /= 100;
            $precision = 2;
        }

        return $this->priceCurrency->format($price, false, $precision, null, strtoupper($currency));
    }

    public function getUrl($path, $additionalParams = [])
    {
        $params = ['_secure' => $this->request->isSecure()];
        return $this->urlBuilder->getUrl($path, $params + $additionalParams);
    }

    public function getRequest()
    {
        return $this->request;
    }

    public function updateBillingAddress($token)
    {
        if (strpos($token, "pm_") === 0)
        {
            $paymentMethod = \Stripe\PaymentMethod::retrieve($token);
            $quote = $this->getQuote();
            $magentoBillingDetails = $this->addressHelper->getStripeAddressFromMagentoAddress($quote->getBillingAddress());
            $paymentMethodBillingDetails = [
                "address" => [
                    "city" => $paymentMethod->billing_details->address->city,
                    "line1" => $paymentMethod->billing_details->address->line1,
                    "line2" => $paymentMethod->billing_details->address->line2,
                    "country" => $paymentMethod->billing_details->address->country,
                    "postal_code" => $paymentMethod->billing_details->address->postal_code,
                    "state" => $paymentMethod->billing_details->address->state
                ],
                "phone" => $paymentMethod->billing_details->phone,
                "name" => $paymentMethod->billing_details->name,
                "email" => $paymentMethod->billing_details->email
            ];
            if ($paymentMethodBillingDetails != $magentoBillingDetails || $paymentMethodBillingDetails["address"] != $magentoBillingDetails["address"])
            {
                \Stripe\PaymentMethod::update(
                  $paymentMethod->id,
                  ['billing_details' => $magentoBillingDetails]
                );
            }
        }
    }

    public function sendNewOrderEmailFor($order, $forceSend = false)
    {
        if (empty($order) || !$order->getId())
            return;

        if (!$order->getEmailSent() && $forceSend)
        {
            $order->setCanSendNewEmailFlag(true);
        }

        // Send the order email
        if ($order->getCanSendNewEmailFlag())
        {
            try
            {
                $this->orderSender->send($order);
            }
            catch (\Exception $e)
            {
                $this->logError($e->getMessage());
                $this->logError($e->getTraceAsString());
            }
        }
    }

    // An assumption is made that Webhooks->initStripeFrom($order) has already been called
    // to set the store and currency before the conversion, as the pricingHelper uses those
    public function getFormattedStripeAmount($amount, $currency, $order)
    {
        $orderAmount = $this->convertStripeAmountToOrderAmount($amount, $currency, $order);

        return $this->addCurrencySymbol($orderAmount, $currency);
    }

    public function convertBaseAmountToStoreAmount($baseAmount)
    {
        $store = $this->storeManager->getStore();
        return $store->getBaseCurrency()->convert($baseAmount, $store->getCurrentCurrencyCode());
    }

    public function convertBaseAmountToOrderAmount($baseAmount, $order, $stripeCurrency, $precision = 4)
    {
        $currency = $order->getOrderCurrencyCode();

        if (strtolower($stripeCurrency) == strtolower($order->getOrderCurrencyCode()))
        {
            $rate = $order->getBaseToOrderRate();
            if (empty($rate))
                return $baseAmount; // The base currency and the order currency are the same

            return round($baseAmount * $rate, $precision);
        }
        else
        {
            $store = $this->storeManager->getStore();
            $amount = $store->getBaseCurrency()->convert($baseAmount, $stripeCurrency);

            return round($amount, $precision);
        }

        // $rate = $this->currencyFactory->create()->load($order->getBaseCurrencyCode())->getAnyRate($currency);
    }

    public function convertMagentoAmountToStripeAmount($amount, $currency)
    {
        if (empty($amount) || !is_numeric($amount) || $amount < 0)
            return 0;

        $cents = 100;
        if ($this->isZeroDecimal($currency))
            $cents = 1;

        $amount = round($amount, 2);

        return round($amount * $cents);
    }

    public function convertOrderAmountToBaseAmount($amount, $currency, $order)
    {
        if (strtolower($currency) == strtolower($order->getOrderCurrencyCode()))
            $rate = $order->getBaseToOrderRate();
        else
            throw new \Exception("Currency code $currency was not used to place order #" . $order->getIncrementId());

        // $rate = $this->currencyFactory->create()->load($order->getBaseCurrencyCode())->getAnyRate($currency);
        if (empty($rate))
            return $amount; // The base currency and the order currency are the same

        return round($amount / $rate, 2);
    }

    public function convertStripeAmountToBaseOrderAmount($amount, $currency, $order)
    {
        if (strtolower($currency) != strtolower($order->getOrderCurrencyCode()))
            throw new \Exception("The order currency does not match the Stripe currency");

        $cents = 100;

        if ($this->isZeroDecimal($currency))
            $cents = 1;

        $amount = ($amount / $cents);
        $baseAmount = round($amount / $order->getBaseToOrderRate(), 2);

        return $baseAmount;
    }

    public function convertStripeAmountToBaseQuoteAmount($amount, $currency, $quote)
    {
        if (strtolower($currency) != strtolower($quote->getQuoteCurrencyCode()))
            throw new \Exception("The order currency does not match the Stripe currency");

        $cents = 100;

        if ($this->isZeroDecimal($currency))
            $cents = 1;

        $amount = ($amount / $cents);
        $baseAmount = round($amount / $quote->getBaseToQuoteRate(), 2);

        return $baseAmount;
    }

    public function convertStripeAmountToOrderAmount($amount, $currency, $order)
    {
        if (strtolower($currency) != strtolower($order->getOrderCurrencyCode()))
            throw new \Exception("The order currency does not match the Stripe currency");

        $cents = 100;

        if ($this->isZeroDecimal($currency))
            $cents = 1;

        $amount = ($amount / (float)$cents);

        return $amount;
    }

    public function convertStripeAmountToQuoteAmount($amount, $currency, $quote)
    {
        if (strtolower($currency) != strtolower($quote->getQuoteCurrencyCode()))
            throw new \Exception("The quote currency does not match the Stripe currency");

        $cents = 100;

        if ($this->isZeroDecimal($currency))
            $cents = 1;

        $amount = ($amount / (float)$cents);

        return $amount;
    }

    public function convertStripeAmountToMagentoAmount($amount, $currency)
    {
        $cents = 100;

        if ($this->isZeroDecimal($currency))
            $cents = 1;

        $amount = ($amount / $cents);

        return round($amount, 2);
    }

    public function getCurrentCurrencyCode()
    {
        return $this->storeManager->getStore()->getCurrentCurrency()->getCode();
    }

    public function addCurrencySymbol($amount, $currencyCode = null)
    {
        if (empty($currencyCode))
            $currencyCode = $this->getCurrentCurrencyCode();

        $precision = 2;
        if ($this->isZeroDecimal($currencyCode))
            $precision = 0;

        return $this->priceCurrency->format($amount, false, $precision, null, strtoupper($currencyCode));
    }

    public function getSubscriptionProductFromQuoteItem($quoteItem)
    {
        if (!in_array($quoteItem->getProductType(), ["simple", "virtual"]))
            return null;

        $productId = $quoteItem->getProductId();

        if (empty($productId))
            return null;

        $product = $this->loadProductById($productId);

        if (!$product->getStripeSubEnabled())
            return null;

        return $product;
    }

    public function getClearSourceInfo($data)
    {
        $info = [];
        $remove = ['mandate_url', 'fingerprint', 'client_token', 'data_string'];
        foreach ($data as $key => $value)
        {
            if (!in_array($key, $remove))
                $info[$key] = $value;
        }

        // Remove Klarna pay fields
        $startsWith = ["pay_"];
        foreach ($info as $key => $value)
        {
            foreach ($startsWith as $part)
            {
                if (strpos($key, $part) === 0)
                    unset($info[$key]);
            }
        }

        return $info;
    }

    public function notifyCustomer($order, $comment)
    {
        $order->addStatusToHistory($status = false, $comment, $isCustomerNotified = true);
        $order->setCustomerNote($comment);
        try
        {
            $this->orderCommentSender->send($order, $notify = true, $comment);
        }
        catch (\Exception $e)
        {
            $this->logError("Order email sending failed: " . $e->getMessage());
        }
    }

    public function sendNewOrderEmailWithComment($order, $comment)
    {
        $order->addStatusToHistory($status = false, $comment, $isCustomerNotified = true);
        $this->orderComments[$order->getIncrementId()] = $comment;
        $order->setEmailSent(false);
        $this->orderSender->send($order, true);
    }

    public function isAuthenticationRequiredMessage($message)
    {
        return (strpos($message, "Authentication Required: ") !== false);
    }

    public function getMultishippingOrdersDescription($quote, $orders)
    {
        $customerName = $quote->getCustomerFirstname() . " " . $quote->getCustomerLastname();

        $orderIncrementIds = [];
        foreach ($orders as $order)
            $orderIncrementIds[] = "#" . $order->getIncrementId();

        $description = __("Multishipping orders %1 by %2", implode(", ", $orderIncrementIds), $customerName);

        return $description;
    }

    public function getOrderDescription($order)
    {
        if ($order->getCustomerIsGuest())
        {
            $customer = $this->getGuestCustomer($order);
            $customerName = $customer->getFirstname() . ' ' . $customer->getLastname();
        }
        else
            $customerName = $order->getCustomerName();

        if ($this->hasSubscriptionsIn($order->getAllItems()))
            $subscription = "subscription ";
        else
            $subscription = "";

        if ($this->isMultiShipping())
            $description = "Multi-shipping {$subscription}order #" . $order->getRealOrderId() . " by $customerName";
        else
            $description = "{$subscription}order #" . $order->getRealOrderId() . " by $customerName";

        return ucfirst($description);
    }

    public function getQuoteDescription($quote)
    {
        if ($quote->getCustomerIsGuest())
        {
            $customer = $this->getGuestCustomer($quote);
            $customerName = $customer->getFirstname() . ' ' . $customer->getLastname();
        }
        else
            $customerName = $quote->getCustomerName();

        if (!empty($customerName))
            $description = __("Cart %1 by %2", $quote->getId(), $customerName);
        else
            $description = __("Cart %1", $quote->getId());

        return $description;
    }

    public function supportsSubscriptions(?string $method)
    {
        if (empty($method))
            return false;

        return in_array($method, ["stripe_payments", "stripe_payments_checkout", "stripe_payments_express"]);
    }

    public function isStripeCheckoutMethod(?string $method)
    {
        if (empty($method))
            return false;

        return in_array($method, ["stripe_payments_checkout"]);
    }

    public function getLevel3DataFrom($order)
    {
        if (empty($order))
            return null;

        $merchantReference = $order->getIncrementId();

        if (empty($merchantReference))
            return null;

        $currency = $order->getOrderCurrencyCode();
        $cents = $this->isZeroDecimal($currency) ? 1 : 100;

        $data = [
            "merchant_reference" => $merchantReference,
            "line_items" => $this->getLevel3DataLineItemsFrom($order, $cents)
        ];

        if (!$order->getIsVirtual())
        {
            $data["shipping_address_zip"] = $order->getShippingAddress()->getPostcode();
            $data["shipping_amount"] = round($order->getShippingInclTax() * $cents);
        }

        $data = array_merge($data, $this->getLevel3AdditionalDataFrom($order, $cents));

        return $data;
    }

    public function getLevel3DataLineItemsFrom($order, $cents)
    {
        $items = [];

        $quoteItems = $order->getAllVisibleItems();
        foreach ($quoteItems as $item)
        {
            $amount = $item->getPrice();
            $tax = round($item->getTaxAmount() * $cents);
            $discount = round($item->getDiscountAmount() * $cents);

            $items[] = [
                "product_code" => substr($item->getSku(), 0, 12),
                "product_description" => substr($item->getName(), 0, 26),
                "unit_cost" => round($amount * $cents),
                "quantity" => $item->getQtyOrdered(),
                "tax_amount" => $tax,
                "discount_amount" => $discount
            ];
        }

        return $items;
    }

    public function getLevel3AdditionalDataFrom($order, $cents)
    {
        // You can overwrite to add the shipping_from_zip or customer_reference parameters here
        return [];
    }

    public function getCustomerModel()
    {
        if ($this->currentCustomer)
            return $this->currentCustomer;

        $pk = $this->getPublishableKey();
        if (empty($pk))
            return $this->currentCustomer = \Magento\Framework\App\ObjectManager::getInstance()->create('StripeIntegration\Payments\Model\StripeCustomer');

        $customerId = $this->getCustomerId();
        $model = null;

        if (is_numeric($customerId) && $customerId > 0)
        {
            $model = $this->customerCollection->getByCustomerId($customerId, $pk);
            if ($model && $model->getId())
            {
                $model->updateSessionId();
                $this->currentCustomer = $model;
            }
        }
        else
        {
            $stripeCustomerId = $this->sessionManager->getStripeCustomerId();
            $model = null;

            if ($stripeCustomerId)
            {
                $model = $this->customerCollection->getByStripeCustomerIdAndPk($stripeCustomerId, $pk);
            }
            else
            {
                $sessionId = $this->sessionManager->getSessionId();
                $model = $this->customerCollection->getBySessionId($sessionId, $pk);
            }

            if ($model && $model->getId())
                $this->currentCustomer = $model;
        }

        if (!$this->currentCustomer)
            $this->currentCustomer = \Magento\Framework\App\ObjectManager::getInstance()->create('StripeIntegration\Payments\Model\StripeCustomer');

        return $this->currentCustomer;
    }

    public function getCustomerModelByStripeId($stripeId)
    {
        return $this->customerCollection->getByStripeCustomerId($stripeId);
    }

    public function getPublishableKey()
    {
        $storeId = $this->getStoreId();
        $mode = $this->scopeConfig->getValue("payment/stripe_payments_basic/stripe_mode", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        $pk = $this->scopeConfig->getValue("payment/stripe_payments_basic/stripe_{$mode}_pk", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        return trim($pk);
    }

    public function getStripeUrl($liveMode, $objectType, $id)
    {
        if ($liveMode)
            return "https://dashboard.stripe.com/$objectType/$id";
        else
            return "https://dashboard.stripe.com/test/$objectType/$id";
    }

    public function holdOrder(&$order)
    {
        $order->setHoldBeforeState($order->getState());
        $order->setHoldBeforeStatus($order->getStatus());
        $order->setState(\Magento\Sales\Model\Order::STATE_HOLDED)
            ->setStatus($order->getConfig()->getStateDefaultStatus(\Magento\Sales\Model\Order::STATE_HOLDED));
        $comment = __("Order placed under manual review by Stripe Radar.");
        $order->addStatusToHistory(false, $comment, false);

        $pi = $this->cleanToken($order->getPayment()->getLastTransId());
        if (!empty($pi))
        {
            // @todo: Why are we doing this inside holdOrder() ?
            $paymentIntent = $this->paymentIntentFactory->create();
            $paymentIntent->load($pi, 'pi_id'); // Finds or creates the row
            $paymentIntent->setPiId($pi);
            $paymentIntent->setOrderIncrementId($order->getIncrementId());
            $paymentIntent->setQuoteId($order->getQuoteId());
            $paymentIntent->save();
        }

        return $order;
    }

    public function addOrderComment($msg, $order, $isCustomerNotified = false)
    {
        if ($order)
            $order->addCommentToStatusHistory($msg);
    }

    public function overrideInvoiceActionComment(\Magento\Sales\Model\Order\Payment $payment, $msg)
    {
        $extensionAttributes = $payment->getExtensionAttributes();
        if ($extensionAttributes === null)
        {
            $extensionAttributes = $this->paymentExtensionFactory->create();
            $payment->setExtensionAttributes($extensionAttributes);
        }

        $extensionAttributes->setNotificationMessage($msg);
    }

    public function overrideCancelActionComment(\Magento\Sales\Model\Order\Payment $payment, $msg)
    {
        $payment->setMessage($msg);
        $this->overrideInvoiceActionComment($payment, $msg);
    }

    public function capture($token, $payment, $amount, $useSavedCard = false)
    {
        $token = $this->cleanToken($token);
        $order = $payment->getOrder();

        if ($token == "cannot_capture_subscriptions")
        {
            $msg = __("Subscription items cannot be captured online. Will capture offline instead.");
            $this->addWarning($msg);
            $this->addOrderComment($msg, $order);
            return;
        }

        try
        {
            $paymentObject = $ch = null;
            $finalAmount = $amountToCapture = 0;

            if (strpos($token, 'pi_') === 0)
            {
                $pi = \Stripe\PaymentIntent::retrieve($token);

                if (empty($pi->charges->data[0]))
                    $this->dieWithError(__("The payment for this order has not been authorized yet."));

                $ch = $pi->charges->data[0];
                $paymentObject = $pi;
                $amountToCapture = "amount_to_capture";
            }
            else if (strpos($token, 'ch_') === 0)
            {
                $ch = \Stripe\Charge::retrieve($token);
                $paymentObject = $ch;
                $amountToCapture = "amount";
            }
            else
            {
                $this->dieWithError(__("We do not know how to capture payments with a token of this format."));
            }

            $currency = $ch->currency;

            if ($currency == strtolower($order->getOrderCurrencyCode()))
                $finalAmount = $this->getMultiCurrencyAmount($payment, $amount);
            else if ($currency == strtolower($order->getBaseCurrencyCode()))
                $finalAmount = $amount;
            else
                $this->dieWithError(__("Cannot capture payment because it was created using a different currency (%1).", $ch->currency));

            $cents = 100;
            if ($this->isZeroDecimal($currency))
                $cents = 1;

            $stripeAmount = round($finalAmount * $cents);

            if ($this->isAuthorizationExpired($ch))
            {
                if ($useSavedCard)
                    $this->apiFactory->create()->reCreateCharge($payment, $amount, $ch);
                else
                    return $this->dieWithError("The payment authorization with the customer's bank has expired. If you wish to create a new payment using a saved card, please enable Expired Authorizations from Configuration &rarr; Sales &rarr; Payment Methods &rarr; Stripe &rarr; Card Payments &rarr; Expired Authorizations.");
            }
            else if ($ch->refunded)
            {
                $this->dieWithError("The amount for this invoice has been refunded in Stripe.");
            }
            else if ($ch->captured)
            {
                $capturedAmount = $ch->amount - $ch->amount_refunded;
                $humanReadableAmount = $this->formatStripePrice($stripeAmount - $capturedAmount, $ch->currency);

                if ($order->getInvoiceCollection()->getSize() > 0)
                {
                    foreach ($order->getInvoiceCollection() as $invoice)
                    {
                        if ($invoice->getState() == \Magento\Sales\Model\Order\Invoice::STATE_PAID)
                        {
                            if ($invoice->getGrandTotal() < $order->getGrandTotal()) // Is this a partial invoice?
                            {
                                if ($useSavedCard)
                                {
                                    $this->apiFactory->create()->reCreateCharge($payment, $amount, $ch);
                                    return;
                                }
                                else
                                {
                                    return $this->dieWithError("The payment has already been partially captured, and the remaining amount has been released. If you wish to create a new payment using a saved card, please enable Expired Authorizations from Configuration &rarr; Sales &rarr; Payment Methods &rarr; Stripe &rarr; Card Payments &rarr; Expired Authorizations.");
                                }
                            }
                            else
                            {
                                // In theory we should never get in here because Magento cannot Invoice orders which have already been fully invoiced..
                                $msg = __("%1 could not be captured online because it was already captured via Stripe. Capturing %1 offline instead.", $humanReadableAmount);
                                $this->addWarning($msg);
                                $this->addOrderComment($msg, $order);
                                return;
                            }
                        }
                    }
                }

                if ($this->hasTrialSubscriptionsIn($order->getAllItems()))
                {
                    $msg = __("%1 could not be captured online because this cart includes subscriptions which are trialing. Capturing %1 offline instead.", $humanReadableAmount);
                }
                else if (($stripeAmount - $capturedAmount) == 0)
                {
                    // Case with a regular item and a subscription with PaymentElement, before the webhook arrives.
                    $humanReadableAmount = $this->formatStripePrice($stripeAmount, $ch->currency);
                    $msg = __("%1 has already captured via Stripe. The invoice was in Pending status, likely because a webhook could not be delivered to your website. Capturing %1 offline instead.", $humanReadableAmount);
                }
                else
                    $msg = __("%1 could not be captured online because it was already captured via Stripe. Capturing %1 offline instead.", $humanReadableAmount);

                $this->addWarning($msg);
                $this->addOrderComment($msg, $order);
            }
            else // status == pending
            {
                $availableAmount = $ch->amount;
                if ($availableAmount < $stripeAmount)
                {
                    $available = $this->formatStripePrice($availableAmount, $ch->currency);
                    $requested = $this->formatStripePrice($stripeAmount, $ch->currency);

                    if ($this->hasSubscriptionsIn($order->getAllItems()))
                        $msg = __("Capturing %1 instead of %2 because subscription items cannot be captured.", $available, $requested);
                    else
                        $msg = __("The maximum available amount to capture is %1, but a capture of %2 was requested. Will capture %1 instead.", $available, $requested);

                    $this->addWarning($msg);
                    $this->addOrderComment($msg, $order);
                    $stripeAmount = $availableAmount;
                }

                $this->cache->save($value = "1", $key = "admin_captured_" . $paymentObject->id, ["stripe_payments"], $lifetime = 60 * 60);
                $paymentObject->capture(array($amountToCapture => $stripeAmount));
            }
        }
        catch (\Exception $e)
        {
            $this->dieWithError($e->getMessage(), $e);
        }
    }

    public function deduplicatePaymentMethod($customerId, $paymentMethodId, $paymentMethodType, $fingerprint, $stripeClient)
    {
        if ($paymentMethodType != "card" || empty($fingerprint) || empty($customerId) || empty($paymentMethodId))
            return;

        try
        {

            switch ($paymentMethodType)
            {
                case "card":

                    $subscriptions = [];
                    $data = $stripeClient->subscriptions->all(['limit' => 100, 'customer' => $customerId]);
                    foreach ($data->autoPagingIterator() as $subscription)
                        $subscriptions[] = $subscription;

                    $collection = $stripeClient->paymentMethods->all([
                      'customer' => $customerId,
                      'type' => $paymentMethodType
                    ]);

                    foreach ($collection->data as $paymentMethod)
                    {
                        if ($paymentMethod['id'] == $paymentMethodId || $paymentMethod['card']['fingerprint'] != $fingerprint)
                            continue;

                        // Update subscriptions which use the card that will be deleted
                        foreach ($subscriptions as $subscription)
                        {
                            if ($subscription->default_payment_method == $paymentMethod['id'])
                            {
                                try
                                {
                                    $stripeClient->subscriptions->update($subscription->id, ['default_payment_method' => $paymentMethodId]);
                                }
                                catch (\Exception $e)
                                {
                                    $this->logError($e->getMessage());
                                    $this->logError($e->getTraceAsString());
                                }
                            }
                        }

                        // Detach the card from the customer
                        try
                        {
                            $stripeClient->paymentMethods->detach($paymentMethod['id']);
                        }
                        catch (\Exception $e)
                        {
                            $this->logError($e->getMessage());
                            $this->logError($e->getTraceAsString());
                        }
                    }

                    break;

                default:

                    break;
            }
        }
        catch (\Exception $e)
        {
            $this->logError($e->getMessage());
            $this->logError($e->getTraceAsString());
        }
    }

    public function getPRAPIMethodType()
    {
        if (empty($_SERVER['HTTP_USER_AGENT']))
            return null;

        $userAgent = strtolower($_SERVER['HTTP_USER_AGENT']);

        if (strpos($userAgent, 'chrome') !== false)
            return 'Google Pay';

        if (strpos($userAgent, 'safari') !== false)
            return 'Apple Pay';

        if (strpos($userAgent, 'edge') !== false)
            return 'Microsoft Pay';

        if (strpos($userAgent, 'opera') !== false)
            return 'Opera Browser Wallet';

        if (strpos($userAgent, 'firefox') !== false)
            return 'Firefox Browser Wallet';

        if (strpos($userAgent, 'samsung') !== false)
            return 'Samsung Browser Wallet';

        if (strpos($userAgent, 'qqbrowser') !== false)
            return 'QQ Browser Wallet';

        return null;
    }

    public function getPaymentLocation($location)
    {
        if (stripos($location, 'product') === 0)
            return "Product Page";

        switch ($location) {
            case 'cart':
                return "Shopping Cart Page";

            case 'checkout':
                return "Checkout Page";

            case 'minicart':
                return "Mini cart";

            default:
                return "Unknown";
        }
    }

    public function getCustomerOrders($customerId, $statuses = [], $paymentMethodId = null)
    {
        $collection = $this->orderCollectionFactory->create($customerId)
            ->addAttributeToSelect('*')
            ->join(
                ['pi' => $this->resource->getConnection()->getTableName('stripe_payment_intents')],
                'main_table.customer_id = pi.customer_id and main_table.increment_id = pi.order_increment_id',
                []
            )
            ->setOrder(
                'created_at',
                'desc'
            );

        if (!empty($statuses))
            $collection->addFieldToFilter('main_table.status', ['in' => $statuses]);

        if (!empty($paymentMethodId))
            $collection->addFieldToFilter('pi.pm_id', ['eq' => $paymentMethodId]);

        return $collection;
    }

    public function loadCouponByCouponCode($couponCode)
    {
        return $this->couponFactory->create()->loadByCode($couponCode);
    }

    public function loadRuleByRuleId($ruleId)
    {
        return $this->ruleRepository->getById($ruleId);
    }

    public function loadStripeCouponByRuleId($ruleId)
    {
        return $this->stripeCouponFactory->create()->load($ruleId, 'rule_id');
    }

    public function refundPaymentIntent($payment, $amount, $currency)
    {
        $paymentIntentId = $payment->getLastTransId();
        $paymentIntentId = $this->cleanToken($paymentIntentId);

        // Redirect-based payment method where an invoice is in Pending status, with no transaction ID
        if (empty($paymentIntentId))
            return; // Creates an Offline Credit Memo

        if (strpos($paymentIntentId, 'pi_') !== 0)
            throw new LocalizedException(__("Could not refund invoice because %1 is not a valid Payment Intent ID", $paymentIntentId));

        $params = ["amount" => $this->convertMagentoAmountToStripeAmount($amount, $currency)];

        $pi = \Stripe\PaymentIntent::retrieve($paymentIntentId);

        if ($pi->status == \StripeIntegration\Payments\Model\PaymentIntent::AUTHORIZED)
        {
            $pi->cancel();
            return;
        }
        else
        {
            $charge = $pi->charges->data[0];
            $params["charge"] = $charge->id;
        }

        if (!$charge->refunded) // This is true when an authorization has expired or when there was a refund through the Stripe account
        {
            $this->cache->save($value = "1", $key = "admin_refunded_" . $charge->id, ["stripe_payments"], $lifetime = 60 * 60);
            \Stripe\Refund::create($params);
        }
        else
        {
            $comment = __('An attempt to manually refund the order was made, however this order was already refunded in Stripe. Creating an offline refund instead.');
            $payment->getOrder()->addStatusToHistory($status = false, $comment, $isCustomerNotified = false);
        }
    }

    public function setQuoteTaxFrom($stripeTaxAmount, $stripeCurrency, $quote)
    {
        // Stripe uses a different tax rounding algorithm than Magento, so check for tax rounding errors and fix them
        $tax = $this->convertStripeAmountToQuoteAmount($stripeTaxAmount, $stripeCurrency, $quote);
        $baseTax = $this->convertStripeAmountToBaseQuoteAmount($stripeTaxAmount, $stripeCurrency, $quote);
        $taxDiff = $tax - $quote->getTaxAmount();
        $baseTaxDiff = $baseTax - $quote->getBaseTaxAmount();
        $quote->setTaxAmount($tax);
        $quote->setBaseTaxAmount($baseTax);
        $quote->setGrandTotal($quote->getGrandTotal() + $taxDiff);
        $quote->setBaseGrandTotal($quote->getBaseGrandTotal() + $baseTaxDiff);
    }

    public function sendPaymentFailedEmail($quote, $msg)
    {
        try
        {
            $this->checkoutHelper->sendPaymentFailedEmail($quote, $msg);
        }
        catch (\Exception $e)
        {
            $this->logError($e->getMessage(), $e->getTraceAsString());
        }
    }

    public function isRecurringOrder($method)
    {
        try
        {
            $info = $method->getInfoInstance();

            if (!$info)
                return false;

            return $info->getAdditionalInformation("is_recurring_subscription");
        }
        catch (\Exception $e)
        {
            return false;
        }

        return false;
    }

    public function resetPaymentData($payment)
    {
        // Reset a previously initialized 3D Secure session
        $payment->setAdditionalInformation('stripejs_token', null)
            ->setAdditionalInformation('token', null)
            ->setAdditionalInformation("is_recurring_subscription", null)
            ->setAdditionalInformation("is_migrated_subscription", null)
            ->setAdditionalInformation("subscription_customer", null)
            ->setAdditionalInformation("subscription_start", null)
            ->setAdditionalInformation("remove_initial_fee", null)
            ->setAdditionalInformation("off_session", null)
            ->setAdditionalInformation("customer_stripe_id", null)
            ->setAdditionalInformation("client_side_confirmation", null)
            ->setAdditionalInformation("payment_location", null);
    }

    public function assignPaymentData($payment, $data)
    {
        $this->resetPaymentData($payment);

        if ($this->isMultiShipping())
        {
            $payment->setAdditionalInformation("payment_location", "Multishipping checkout");
        }
        else if ($this->isAdmin())
        {
            $payment->setAdditionalInformation("payment_location", "Admin area");
        }
        else if ($this->isAPIRequest())
        {
            $payment->setAdditionalInformation("payment_location", "API");
        }
        else if (!empty($data['is_recurring_subscription']))
        {
            $payment->setAdditionalInformation("payment_location", "Recurring subscription order");
        }
        else if (!empty($data['is_migrated_subscription']))
        {
            $payment->setAdditionalInformation("payment_location", "CLI migrated subscription order");
        }
        else if (!empty($data['is_prapi']))
        {
            $payment->setAdditionalInformation('is_prapi', true);
            $payment->setAdditionalInformation('prapi_location', $data['prapi_location']);

            if (!empty($data['prapi_title']))
            {
                $payment->setAdditionalInformation('prapi_title', $data['prapi_title']);
                $location = $data['prapi_title'] . " via " . $data['prapi_location'] . " page";
                $payment->setAdditionalInformation("payment_location", $location);
            }
            else
            {
                $payment->setAdditionalInformation("payment_location", "Wallet payment");
            }
        }

        if (!empty($data['client_side_confirmation']))
        {
            // Used by the new checkout flow
            $token = (isset($data['payment_method']) ? $data['payment_method'] : null);
            $payment->setAdditionalInformation('client_side_confirmation', true);
            $payment->setAdditionalInformation('token', $token);
        }
        else if (!empty($data['cc_stripejs_token']))
        {
            // Used in the Magento admin, Wallet button, GraphQL API and REST API
            $card = explode(':', $data['cc_stripejs_token']);
            $data['cc_stripejs_token'] = $card[0];
            $payment->setAdditionalInformation('token', $card[0]);

            if ($this->isMultiShipping())
            {
                $quoteId = $payment->getQuoteId();
                $multishippingQuoteModel = $this->multishippingQuoteFactory->create();
                $multishippingQuoteModel->load($quoteId, 'quote_id');
                $multishippingQuoteModel->setQuoteId($quoteId);
                $multishippingQuoteModel->setPaymentMethodId($card[0]);
                $multishippingQuoteModel->save();
            }
        }
        else if (!empty($data['is_recurring_subscription']))
            $payment->setAdditionalInformation('is_recurring_subscription', $data['is_recurring_subscription']);

        if (!empty($data['is_migrated_subscription']))
            $payment->setAdditionalInformation('is_migrated_subscription', true);
    }

    public function shippingIncludesTax($store = null)
    {
        return $this->taxConfig->shippingPriceIncludesTax($store);
    }

    public function priceIncludesTax($store = null)
    {
        return $this->taxConfig->priceIncludesTax($store);
    }

    /**
     * Transaction interface types
     * const TYPE_PAYMENT = 'payment';
     * const TYPE_ORDER = 'order';
     * const TYPE_AUTH = 'authorization';
     * const TYPE_CAPTURE = 'capture';
     * const TYPE_VOID = 'void';
     * const TYPE_REFUND = 'refund';
     **/
    public function addTransaction($order, $transactionId, $transactionType = "capture", $parentTransactionId = null)
    {
        try
        {
            $payment = $order->getPayment();

            if ($parentTransactionId)
            {
                $payment->setTransactionId($transactionId . "-$transactionType");
                $payment->setParentTransactionId($parentTransactionId);
            }
            else
            {
                $payment->setTransactionId($transactionId);
                $payment->setParentTransactionId(null);
            }

            $transaction = $payment->addTransaction($transactionType, null, true);
            return  $transaction;
        }
        catch (Exception $e)
        {
            $this->logError($e->getMessage(), $e->getTraceAsString());
        }
    }

    public function getOrderTransactions($order)
    {
        $transactions = $this->transactionSearchResultFactory->create()->addOrderIdFilter($order->getId());
        return $transactions->getItems();
    }

    // $orderItemQtys = [$orderItem->getId() => int $qty, ...]
    public function invoiceOrderItems($order, $orderItemQtys, $save = true)
    {
        if (empty($orderItemQtys))
            return null;

        $invoice = $this->invoiceService->prepareInvoice($order, $orderItemQtys);
        $invoice->register();
        $order->setIsInProcess(true);

        if ($save)
        {
            $this->saveInvoice($invoice);
            $this->saveOrder($order);
        }

        return $invoice;
    }

    public function getQuoteFromOrder($order)
    {
        if (!$order->getQuoteId())
            $this->dieWithError("The order has no associated quote ID.");

        return $this->loadQuoteById($order->getQuoteId());
    }

    public function setTotalPaid(&$order, $amount, $currency)
    {
        $currency = strtolower($currency);
        $isMultiCurrencyOrder = ($order->getOrderCurrencyCode() != $order->getBaseCurrencyCode());

        if ($currency == strtolower($order->getOrderCurrencyCode()))
        {
            // Convert amount to 4 decimal points
            if ($amount == round($order->getGrandTotal(), 2))
                $amount = $order->getGrandTotal();

            $order->setTotalPaid(min($order->getGrandTotal(), $amount));

            if (!$isMultiCurrencyOrder)
            {
                $order->setBaseTotalPaid(min($order->getBaseGrandTotal(), $amount));
            }
            else if ($amount == $order->getGrandTotal())
            {
                // A multi-currency order has been paid in full
                $order->setBaseTotalPaid($order->getBaseGrandTotal());
            }
            else
            {
                // For partial payments, we should not try to set the base total paid because it may result in a tax rounding error
                // It is best to leave Magento manage the base amount
                /*
                $baseTransactionsTotal = $this->convertOrderAmountToBaseAmount($amount, $currency, $order);
                $order->setBaseTotalPaid($baseTransactionsTotal);
                */
            }
        }
        else if ($currency == strtolower($order->getBaseCurrencyCode()))
        {
            $order->setBaseTotalPaid(min($order->getBaseGrandTotal(), $amount));
            $rate = $order->getBaseToOrderRate();
            if (empty($rate))
                $rate = 1;
            $order->setTotalPaid(min($order->getGrandTotal(), $amount * $rate));
        }
        else
            throw new \Exception("Currency code $currency was not used to place order #" . $order->getIncrementId());

        $this->orderRepository->save($order);
    }

    public function isPendingCheckoutOrder($order)
    {
        $method = $order->getPayment()->getMethod();
        if (!$this->isStripeCheckoutMethod($method))
            return false;

        if ($order->getState() != "new")
            return false;

        if ($order->getPayment()->getLastTransId())
            return false;

        return true;
    }

    public function clearCache()
    {
        $this->products = [];
        $this->orders = [];
        $this->quoteHasSubscriptions = [];
        return $this;
    }

    public function saveOrder($order)
    {
        return $this->orderRepository->save($order);
    }

    public function saveInvoice($invoice)
    {
        return $this->invoiceRepository->save($invoice);
    }

    public function saveQuote($quote)
    {
        return $this->quoteRepository->save($quote);
    }

    public function saveTransaction($transaction)
    {
        return $this->transactionRepository->save($transaction);
    }

    public function saveCreditmemo($creditmemo)
    {
        return $this->creditmemoRepository->save($creditmemo);
    }

    public function setProcessingState($order, $comment = null, $isCustomerNotified = false)
    {
        $state = \Magento\Sales\Model\Order::STATE_PROCESSING;
        $status = $order->getConfig()->getStateDefaultStatus($state);

        if ($comment)
            $order->setState($state)->addStatusToHistory($status, $comment, $isCustomerNotified);
        else
            $order->setState($state)->setStatus($status);
    }

    public function setOrderState($order, $state, $comment = null, $isCustomerNotified = false)
    {
        $status = $order->getConfig()->getStateDefaultStatus($state);

        if ($comment)
            $order->setState($state)->addStatusToHistory($status, $comment, $isCustomerNotified);
        else
            $order->setState($state)->setStatus($status);
    }

    public function getStripeApiTimeDifference()
    {
        $timeDifference = $this->cache->load("stripe_api_time_difference");
        if (!is_numeric($timeDifference))
        {
            $localTime = time();
            $product = \Stripe\Product::create([
               'name' => 'Time Query',
               'type' => 'service'
            ]);
            $timeDifference = $product->created - ($localTime + 1); // The 1 added second accounts for the delay in creating the product
            $this->cache->save($timeDifference, $key = "stripe_api_time_difference", $tags = ["stripe_payments"], $lifetime = 24 * 60 * 60);
            $product->delete();
        }
        return $timeDifference;
    }

    public function getOrdersByTransactionId($transactionId)
    {
        $orders = [];
        $transactions = $this->transactionSearchResultFactory->create()->addFieldToFilter('txn_id', $transactionId);

        foreach ($transactions as $transaction)
        {
            if (!$transaction->getOrderId())
                continue;

            $orderId = $transaction->getOrderId();
            if (isset($orders[$orderId]))
                continue;

            $order = $this->loadOrderById($orderId);
            if ($order && $order->getId())
                $orders[$orderId] = $order;
        }

        return $orders;
    }

    public function getCache()
    {
        return $this->cache;
    }
}
