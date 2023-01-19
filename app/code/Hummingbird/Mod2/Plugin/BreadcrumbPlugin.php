<?php

namespace Hummingbird\Mod2\Plugin;


use Psr\Log\LoggerInterface;

class BreadcrumbPlugin{

    private $logger;

    public function __construct(LoggerInterface $logger){
        $this->logger = $logger;
    }

    public function beforeAddCrumb(
        \Magento\Theme\Block\Html\Breadcrumbs $subject,
        $crumbName,
        $crumbInfo
    ){
        $crumbInfo['label'] = "Hummingbird " . $crumbInfo['label'];
        // $crumbName = "Hummingbird " . $crumbName;

        return [$crumbName,$crumbInfo];
    }
}

?>