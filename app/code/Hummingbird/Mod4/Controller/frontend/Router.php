<?php

namespace Hummingbird\Mod4\Controller\frontend;

use Magento\Framework\App\RouterInterface;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\RequestInterface;
use Psr\Log\LoggerInterface;

class Router implements RouterInterface {


    public $actionPath;
    private $logger;

    public function __construct(ActionFactory $actionFactory, LoggerInterface $logger){
        $this->actionPath = $actionFactory;
        $this->logger = $logger;
    }


    public function match(RequestInterface $request){
        $info = trim($request->getPathInfo(),'/');
        $this->logger->info($info);
        if(preg_match_all('/[A-Z][a-z0-9-_]*/',$info,$m)){
            // $this->logger->info(print_r($m));
            // $request->setPathInfo(sprintf("%s/%s/%s",$m[1],$m[2],$m[3]));
            $request->setModuleName(strtolower($m[0][0]));
            $request->setControllerName(strtolower($m[0][1]));
            $request->setActionName(strtolower($m[0][2]));
            return $this->actionPath->create('\Magento\Framework\App\Action\Forward', ['request' => $request]);
            // return null;
        }
        return null;
    }

}

?>