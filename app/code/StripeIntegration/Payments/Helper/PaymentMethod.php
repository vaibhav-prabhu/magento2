<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;

class PaymentMethod
{
    protected $icons = [];
    protected $themeModel = null;

    public function __construct(
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Framework\View\Asset\Repository $assetRepo,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\View\Design\Theme\ThemeProviderInterface $themeProvider
    ) {
        $this->request = $request;
        $this->assetRepo = $assetRepo;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->themeProvider = $themeProvider;
    }

    public function getCardIcon($brand)
    {
        $icons = $this->getPaymentMethodIcons();

        if (isset($icons[$brand]))
            return $icons[$brand];

        return $icons['generic'];
    }

    public function getCardLabel($card, $hideLast4 = false)
    {
        if (!empty($card->last4) && !$hideLast4)
            return __("•••• %1", $card->last4);

        if (!empty($card->brand))
            return $this->getCardName($card->brand);

        return __("Card");
    }

    protected function getCardName($brand)
    {
        switch ($brand) {
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
                return ucfirst($brand);
        }
    }

    public function getIcon($method)
    {
        $icons = $this->getPaymentMethodIcons();

        $type = $method->type;

        if (isset($icons[$type]))
            return $icons[$type];

        if ($type == "card" && !empty($method->card->brand) && isset($icons[$method->card->brand]))
            return $icons[$method->card->brand];

        if ($type == "card")
            return $icons['generic'];
        else
            return $icons['bank'];
    }

    public function getLabel($method)
    {
        if (empty($method->type))
            return null;

        $methodName = $this->getPaymentMethodName($method->type);
        $details = $method->{$method->type};

        switch ($method->type)
        {
            case "card":
                return $this->getCardLabel($method->card);
            case "sepa_debit":
            case "au_becs_debit":
            case "acss_debit":
                return __("%1 •••• %2", $methodName, $details->last4);
            case 'boleto':
                return __("%1 - %2", $methodName, $details->tax_id);
            default:
                return str_replace("_", " ", ucfirst($methodName));
        }
    }

    public function getPaymentMethodIcons()
    {
        if (!empty($this->icons))
            return $this->icons;

        return $this->icons = [
            // APMs
            'acss_debit' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bank.svg"),
            'afterpay_clearpay' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/afterpay_clearpay.svg"),
            'alipay' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/alipay.svg"),
            'bacs_debit' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bacs_debit.svg"),
            'au_becs_debit' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bank.svg"),
            'bancontact' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bancontact.svg"),
            'boleto' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/boleto.svg"),
            'eps' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/eps.svg"),
            'fpx' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/fpx.svg"),
            'giropay' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/giropay.svg"),
            'grabpay' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/grabpay.svg"),
            'ideal' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/ideal.svg"),
            'klarna' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/klarna.svg"),
            'paypal' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/paypal.svg"),
            'multibanco' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/multibanco.svg"),
            'p24' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/p24.svg"),
            'sepa' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/sepa_debit.svg"),
            'sepa_debit' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/sepa_debit.svg"),
            'sepa_credit' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/sepa_credit.svg"),
            'sofort' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/klarna.svg"),
            'wechat' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/wechat.svg"),
            'ach' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/ach.svg"),
            'oxxo' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/oxxo.svg"),
            'bank' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bank.svg"),

            // Cards
            'amex' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/amex.svg"),
            'cartes_bancaires' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/cartes_bancaires.svg"),
            'diners' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/diners.svg"),
            'discover' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/discover.svg"),
            'generic' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/generic.svg"),
            'jcb' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/jcb.svg"),
            'mastercard' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/mastercard.svg"),
            'visa' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/visa.svg")
        ];
    }

    public function getPaymentMethodName($code)
    {
        switch ($code)
        {
            case 'visa': return "Visa";
            case 'amex': return "American Express";
            case 'mastercard': return "MasterCard";
            case 'discover': return "Discover";
            case 'diners': return "Diners Club";
            case 'jcb': return "JCB";
            case 'unionpay': return "UnionPay";
            case 'cartes_bancaires': return "Cartes Bancaires";
            case 'bacs_debit': return "BACS Direct Debit";
            case 'au_becs_debit': return "BECS Direct Debit";
            case 'boleto': return "Boleto";
            case 'acss_debit': return "ACSS Direct Debit / Canadian PADs";
            case 'ach_debit': return "ACH Direct Debit";
            case 'oxxo': return "OXXO";
            case 'klarna': return "Klarna";
            case 'sepa': return "SEPA Direct Debit";
            case 'sepa_debit': return "SEPA Direct Debit";
            case 'sepa_credit': return "SEPA Credit Transfer";
            case 'sofort': return "SOFORT";
            case 'ideal': return "iDEAL";
            case 'paypal': return "PayPal";
            case 'wechat': return "WeChat Pay";
            case 'alipay': return "Alipay";
            case 'grabpay': return "GrabPay";
            case 'afterpay_clearpay': return "Afterpay / Clearpay";
            case 'multibanco': return "Multibanco";
            case 'p24': return "P24";
            case 'giropay': return "Giropay";
            case 'eps': return "EPS";
            case 'bancontact': return "Bancontact";
            default:
                return ucwords(str_replace("_", " ", $code));
        }
    }

    public function formatPaymentMethods($methods)
    {
        $savedMethods = [];

        foreach ($methods as $type => $methodList)
        {
            $methodName = $this->getPaymentMethodName($type);

            switch ($type)
            {
                case "card":
                    foreach ($methodList as $method)
                    {
                        $details = $method->card;
                        $key = $details->fingerprint;
                        $savedMethods[$key] = [
                            "type" => $type,
                            "label" => $this->getCardLabel($details),
                            "value" => $method->id,
                            "icon" => $this->getCardIcon($details->brand)
                        ];
                    }
                    break;
                case 'sepa_debit':
                    foreach ($methodList as $method)
                    {
                        $details = $method->sepa_debit;
                        $key = $details->fingerprint;
                        $savedMethods[$key] = [
                            "type" => $type,
                            "label" => __("%1 •••• %2", $methodName, $details->last4),
                            "value" => $method->id,
                            "icon" => $this->getCardIcon($type)
                        ];
                    }
                    break;
                case 'au_becs_debit':
                    foreach ($methodList as $method)
                    {
                        $details = $method->au_becs_debit;
                        $key = $details->fingerprint;
                        $savedMethods[$key] = [
                            "type" => $type,
                            "label" => __("%1 •••• %2", $methodName, $details->last4),
                            "value" => $method->id,
                            "icon" => $this->getCardIcon($type)
                        ];
                    }
                    break;
                case 'acss_debit':
                    foreach ($methodList as $method)
                    {
                        $details = $method->acss_debit;
                        $key = $details->fingerprint;
                        $savedMethods[$key] = [
                            "type" => $type,
                            "label" => __("%1 •••• %2", $methodName, $details->last4),
                            "value" => $method->id,
                            "icon" => $this->getCardIcon($type)
                        ];
                    }
                    break;
                case 'boleto':
                    foreach ($methodList as $method)
                    {
                        $details = $method->boleto;
                        $key = $details->fingerprint;
                        $savedMethods[$key] = [
                            "type" => $type,
                            "label" => __("%1 - %2", $methodName, $details->tax_id),
                            "value" => $method->id,
                            "icon" => $this->getCardIcon($type)
                        ];
                    }
                    break;
                case 'alipay':
                case 'bacs_debit':
                case 'bancontact':
                case 'au_becs_debit':
                case 'ideal':
                case 'acss_debit':
                case 'sofort':
                    break;
                default:
                    break;
            }
        }

        return $savedMethods;
    }

    protected function getViewFileUrl($fileId)
    {
        try
        {
            $params = [
                '_secure' => $this->request->isSecure(),
                'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
                'themeModel' => $this->getThemeModel()
            ];
            return $this->assetRepo->getUrlWithParams($fileId, $params);
        }
        catch (LocalizedException $e)
        {
            return null;
        }
    }

    protected function getThemeModel()
    {
        if ($this->themeModel)
            return $this->themeModel;

        $themeId = $this->scopeConfig->getValue(
            \Magento\Framework\View\DesignInterface::XML_PATH_THEME_ID,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $this->storeManager->getStore()->getId()
        );

        /** @var $theme \Magento\Framework\View\Design\ThemeInterface */
        $this->themeModel = $this->themeProvider->getThemeById($themeId);

        return $this->themeModel;
    }

}
