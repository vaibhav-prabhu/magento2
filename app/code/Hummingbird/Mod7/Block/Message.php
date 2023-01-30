<?php


namespace Hummingbird\Mod7\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class Message extends  Template {

    protected $message = "My Default message";

    public function __construct(
        Context $context,
        array $data = []
    ){
        parent::__construct($context, $data);
    }


    public function getMessage(){
        return $this->message;
    }

    protected function _afterToHtml($html){
        // $html += "This is after2 html block in mod7";
        return parent::_afterToHtml($html);
    }

}


?>