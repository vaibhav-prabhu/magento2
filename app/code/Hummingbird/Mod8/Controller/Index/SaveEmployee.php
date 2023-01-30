<?php

namespace Hummingbird\Mod8\Controller\Index;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
// use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Hummingbird\Mod8\Model\Employee as EmployeeModel;
use Hummingbird\Mod8\Model\ResourceModel\Employee as EmployeeResource;
use Psr\Log\LoggerInterface;

class SaveEmployee extends Action {

    private $logger;

    public $redirectFactory;
    public $em;
    public $emr;

    public function __construct(
        Context $context,
        RedirectFactory $redirectFactory,
        EmployeeModel $em,
        EmployeeResource $emr,
        LoggerInterface $logger
    ){
        $this->redirectFactory = $redirectFactory;
        $this->logger = $logger;
        $this->em = $em;
        $this->emr = $emr;
        return parent::__construct($context);
        //https: //www.codilar.com/magento-2-models-resource-models-and-collections/
    }

    public function execute(){
        $data = $this->getRequest()->getParams();
        $this->logger->info(implode($data));
        // $this->logger->info("page request");
        // $this->logger->info("save page");
        $em = $this->em->setData($data);
        try {
            $this->emr->save($em);
            $this->messageManager->addSuccessMessage("New Entry Added");

        }
        catch(\Exception $exception){
            $this->messageManager->addErrorMessage($exception->getMessage());
        }

        $result = $this->redirectFactory->create();
        $result->setPath("mod8/index/index");

        return $result;
    }

}


?>