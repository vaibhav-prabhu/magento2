<?php

namespace Hummingbird\Mod8\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Employee extends AbstractDb {


    public function _construct()
    {
        $this->_init('employee_table', 'employee_id');
    }

}

?>