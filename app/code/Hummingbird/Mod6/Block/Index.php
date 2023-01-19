<?php

namespace Hummingbird\Mod6\Block;

use Magento\Framework\View\Element\Template;

class Index extends Template {

    public $html = "<h3>This is a afterToHtml Block</h3>";

    protected function _toHtml(){
        return '<h1>This is a toHtml Block Title</h2>';
    }

    protected function _afterToHtml($html){
        $html = $html . " <h3>After To Html Block </h3> ";
        return $html;
    }



}


?>