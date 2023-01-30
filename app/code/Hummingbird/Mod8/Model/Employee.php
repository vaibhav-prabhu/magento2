<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Hummingbird\Mod8\Model;

use Magento\Framework\Model\AbstractModel;

class Employee extends AbstractModel {

    public function _construct()
    {
        $this->_init(\Hummingbird\Mod8\Model\ResourceModel\Employee::class);
    }

}