<?php

namespace Hummingbird\Mod6\Plugin;

use Magento\Catalog\Block\Product\View\Description;
use Psr\Log\LoggerInterface;

class ProductDescPlugin {

    private $logger;
    public function __construct(LoggerInterface $logger){
        $this->logger = $logger;
    }

    public function afterGetProduct(Description $subject,$result){

        $result->setDescription("some description");
        $this->logger->info("from description");
        return $result;
    }

}


?>