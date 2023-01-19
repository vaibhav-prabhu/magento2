<?php

namespace Hummingbird\Mod2\Plugin;

// use Magento\Catalog\Model\Product;
use Psr\Log\LoggerInterface;

class SalePlugin {
    private $logger;

    public function __construct(LoggerInterface $logger){
        $this->logger = $logger;
    }

    public function afterGetName(
        \Magento\Catalog\Model\Product $subject,
        $result
     ){
        // $this->logger->info("logged");
        if($subject->getPrice() < 60 && $subject->getPrice() != 0){
            return $result . " On Sale! ";
        }
        elseif($subject->getMinimalPrice() < 60 && $subject->getMinimalPrice() != 0){
            return $result . " On Sale ";
        }
        return $result;
    }
}

