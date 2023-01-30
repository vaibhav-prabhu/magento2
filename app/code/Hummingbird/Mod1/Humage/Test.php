<?php

namespace Hummingbird\Mod1\Humage;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Framework\View\Result\PageFactory;

class Test {

    public $data;
    public $pageFactory;
    public $str;

    public function __construct(
        CategoryInterface $categoryInterface,
        PageFactory $pageFactory,
        $data = ["Test_data1","Test_data2","Test_data3"],
        $str = "This is test string"
    ){
        $this->pageFactory = $pageFactory;
        $this->data = $data;
        $this->str = $str;
    }

    public function displayParams() {
        $str = implode("\n",$this->data)."\n".$str;
        $result = $this->pageFactory->create();
        $result->setContents($str);
        return $result;
    }

}


?>