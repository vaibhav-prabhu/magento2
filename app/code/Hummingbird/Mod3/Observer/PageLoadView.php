<?php

namespace Hummingbird\Mod3\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class PageLoadView implements ObserverInterface {

    private $logger;

    public function __construct(LoggerInterface $logger){
        $this->logger = $logger;
    }

    public function execute(Observer $observer){
        $result = $observer->getData("response");

        // $this->logger->info($result->getBody());
        $this->logger->info("Page loaded");
    }

}

?>