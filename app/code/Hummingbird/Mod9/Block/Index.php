<?php

namespace Hummingbird\Mod9\Block;

use Magento\Framework\View\Element\Template;
use Hummingbird\Mod9\Helper\Data;


class Index extends Template {

    public $helper;

    public function __construct(
        Template\Context $context,
        Data $helper,
        array $data = []
    ){
        $this->helper = $helper;
        parent::__construct($context,$data);
    }

    public function getEnabled(){
        return $this->helper->getEnableData();
    }

    public function getText(){
        return $this->helper->getTextData();
    }


}

?>