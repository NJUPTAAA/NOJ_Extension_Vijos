<?php
namespace App\Babel\Extension\vijos;

use App\Babel\Install\InstallerBase;
use Exception;

class Installer extends InstallerBase
{
    public $ocode="vijos";

    public function install()
    {
        // throw new Exception("No Install Method Provided");
        $this->_install($this->ocode);
    }

    public function uninstall()
    {
        // throw new Exception("No Uninstall Method Provided");
        $this->_uninstall($this->ocode);
    }
}
