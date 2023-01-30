<?php

namespace Hummingbird\Mod9\Controller\Index;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Psr\Log\LoggerInterface;

class Index extends Action {

    private $logger;
    public $pageFactory;
    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        LoggerInterface $logger
    ){
        $this->logger = $logger;
        $this->pageFactory = $pageFactory;
        parent::__construct($context);
    }

    public function execute(){
        $result = $this->pageFactory->create();
        return $result;
    }

}

?>