<?php

namespace Hummingbird\Mod5\Controller\Index;

use Magento\Framework\App\Action\Action;
use Magento\Framework\View\Result\PageFactory;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Psr\Log\LoggerInterface;


class Index extends Action {

    private $logger;
    protected $pageFactory;
    protected $redirectFactory;

    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        RedirectFactory $redirectFactory
    ){
        $this->pageFactory = $pageFactory;
        $this->redirectFactory = $redirectFactory;
        return parent::__construct($context);
    }

    public function execute(){

        $result = $this->pageFactory->create();

        
        // $result = $this->redirectFactory->create();
        // $result->setPath("aim-analog-watch.html");

        return $result;
        // $result->setPath("aim-analog-watch.html");
        // $result->setContent('<div>Sample CMS Page Content</div>');

        // return $result;

    }


}


?>