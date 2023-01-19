<?php

namespace Hummingbird\Mod6\Controller\Index;

use Magento\Framework\App\Action\Action;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\Action\Context;
// use Hummingbird\Mod6\Block\Index as BIndex;
use Psr\Log\LoggerInterface;

class Index extends Action {

    private $logger;

    protected $pageFactory;

    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        LoggerInterface $logger
    ){
        $this->logger = $logger;
        $this->pageFactory = $pageFactory;
        return parent::__construct($context);
    }

    public function execute(){
       $layout = $this->pageFactory->create()->getLayout();
       $block = $layout->createBlock('Hummingbird\Mod6\Block\Index');
       $result = $this->pageFactory->create();
        $result->getConfig()->setDescription($block->toHtml());
       return $result;
    }

}


?>