<?php


namespace Hummingbird\Mod9\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;


class Data extends AbstractHelper {

    public $scopeConfig;
    public function __construct(Context $context,ScopeConfigInterface $scopeConfig){
        parent::__construct($context);
    }


    public function getEnableData(){
        return $this->scopeConfig->getValue('vaibhav_section/vaibhav_group/field1', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }


    public function getTextData(){
        return $this->scopeConfig->getValue("vaibhav_section/vaibhav_group/field2", \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }




}


?>