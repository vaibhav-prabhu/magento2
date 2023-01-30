<?php

namespace Hummingbird\Mod8\Block;

use Magento\Framework\View\Element\Template;
use Hummingbird\Mod8\Model\ResourceModel\Employee\Collection;
use Psr\Log\LoggerInterface;


class Index extends Template {

    public $collection;
    private $logger;

    public function __construct(
        Template\Context $context,
        Collection $collection,
        LoggerInterface $logger,
        array $data = []
    ){
        $this->collection = $collection;
        $this->logger = $logger;
        parent::__construct($context, $data);
    }


    public function getAllEmployee(){
        return $this->collection;
    }

    public function saveEmployee(){
        $this->logger->info($this->getUrl("mod8/index/saveemployee"));
        return $this->getUrl("mod8/index/saveemployee");
    }

}


?>