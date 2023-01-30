<?php

namespace Hummingbird\Mod8\Model\ResourceModel\Employee;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection {


    public function _construct(){
        $this->_init(\Hummingbird\Mod8\Model\Employee::class,\Hummingbird\Mod8\Model\ResourceModel\Employee::class);
    }

}

?>