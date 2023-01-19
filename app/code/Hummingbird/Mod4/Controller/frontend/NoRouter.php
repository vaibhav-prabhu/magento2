<?php


namespace Hummingbird\Mod4\Controller\frontend;

use Psr\Log\LoggerInterface;


class NoRouter implements \Magento\Framework\App\Router\NoRouteHandlerInterface {

    private $logger;

    public function __construct(LoggerInterface $logger ){
        $this->logger = $logger;
    }

    public function process(\Magento\Framework\App\RequestInterface $request){

        $this->logger->info($request->getFrontName());
        if($request->getFrontName() == "admin"){
            return false;
        }
        
        $request->setModuleName('contact');
        $request->setControllerName('index');
        $request->setActionName('index');

        return true;
    }


}


?>