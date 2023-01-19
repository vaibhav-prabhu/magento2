<?php

namespace Hummingbird\Mod2\Plugin;


class CustomMessage {

    private $logger;
    public function __construct(\Psr\Log\LoggerInterface $logger){
        $this->logger = $logger;
    }

    public function afterGetWelcome(
        \Magento\Theme\Block\Html\Header $subject,
        $result
    ){

        $result = "Custom Vaibhav Welcome";

        return $result;

    }

     public function afterGetCopyright(
        \Magento\Theme\Block\Html\Footer $subject,
        $result
    ){

        $result = "Custom Vaibhav Copyright";

        return $result;

    }

}


?>