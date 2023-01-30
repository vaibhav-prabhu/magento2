<?php


namespace Hummingbird\Mod4\Controller;

use Magento\Framework\App\RouterInterface;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Psr\Log\LoggerInterface;

class ContactRoute implements RouterInterface {

    private $logger;
    protected $actionPath;

    public $redirectFactory;

    public function __construct(ActionFactory $actionFactory, LoggerInterface $logger,RedirectFactory $redirectFactory){
        $this->logger = $logger;
        $this->actionPath = $actionFactory;
        $this->redirectFactory = $redirectFactory;
    }

    public function match(RequestInterface $request){

        $info = trim($request->getPathInfo(),"/");
        $this->logger->info($info);
        if($info == "contactuspage.html"){
            // $result = $this->redirectFactory()->create();
            // $result->setModuleName("contact");
            $request->setModuleName('contact');
            // $request->setAlias(\Magento\Framework\Url::REWRITE_REQUEST_PATH_ALIAS,"contact");
            return $this->actionPath->create('\Magento\Framework\App\Action\Forward', ['request' => $request]);
            // return $result;
        }

        return null;
    }

}



?>