<?php

namespace Hummingbird\Mod4\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;


class RouterView implements ObserverInterface {

    private $logger;

    public function __construct(LoggerInterface $logger){
        $this->logger = $logger;
    }

    public function execute(Observer $observer){
        // $result = $observer->getData("router")->getRedirectUrl();

        // $this->logger->info("heelo");
    }

}



?>