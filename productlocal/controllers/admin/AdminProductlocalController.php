<?php
class AdminProductlocalController extends ModuleAdminController
{
    public function __construct()
    {
        // Name of the module folder
        $this->module = 'productlocal';
        $this->bootstrap = true;
        parent::__construct();
    }

    public function postProcess()
    {
        // Check if action is translateDescription
        if (Tools::isSubmit('action') && Tools::getValue('action') == 'translateDescription') {
            // Get id_product and language_code from ajax request
            $id_product = Tools::getValue('id_product');
            $language_code = Tools::getValue('language_code');
            // Get instance of module
            $module = Module::getInstanceByName('productlocal');

            // Translate description with translateDescription method and return response
            $response = $module->translateDescription($id_product, $language_code);
            die($response);

        }
    }
}
