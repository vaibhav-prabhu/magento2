<?php

namespace Hummingbird\Mod5\Controller\Adminhtml\UserStory;


use Magento\Framework\Controller\ResultFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Psr\Log\LoggerInterface;


class Index extends Action {

    private $logger;
    protected $_resultFactory;

    public function __construct(
        Context $context,
        ResultFactory $resultFactory,
        LoggerInterface $logger
    ){
        $this->logger = $logger;
        $this->_resultFactory = $resultFactory;
        return parent::__construct($context);
    }

    public function execute(){
        $result = $this->_resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setContents("Hello Admin You Have Access");
        $this->_formKeyValidator = true;
        return $result;
    }

    protected function _isAllowed(){
        $access = $this->getRequest()->getParam("access");
        $this->logger->info($access);
        return isset($access) && filter_var($access, FILTER_VALIDATE_BOOLEAN);
    }

    public function _processUrlKeys(){
        return true;
    }

    protected function _validateSecretKey()
    {
        return true;
    }

}

?>