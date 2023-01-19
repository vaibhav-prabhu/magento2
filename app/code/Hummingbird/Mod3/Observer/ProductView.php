<?php

namespace Hummingbird\Mod3\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class ProductView implements ObserverInterface {

    private $logger;

    public function __construct(LoggerInterface $logger){
        $this->logger = $logger;
    }

    public function execute(Observer $observer){
        $result = $observer->getData("product");
        $this->logger->info($result->getName());
    }
}


?>