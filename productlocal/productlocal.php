<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class productlocal extends Module
{

    const PRODUCT_LOCAL_DESCRIPTION = 'description of product';
    const PRODUCT_LOCAL_SHORT_DESCRIPTION = 'short description of product'; 
    const PRODUCT_LOCAL_NAME = 'name of product';  

    public function __construct()
    {
        $this->name = 'productlocal';
        $this->tab = 'administration';
        $this->version = '0.1';
        $this->author = 'sinenko';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.8.6', 'max' => '1.7.8.6'];
        $this->bootstrap = true;
        $this->controllerAdmin = 'AdminProductlocal';

        $this->api_key = Configuration::get('GPT4INTEGRATION_API_KEY'); // API key
        $this->model = Configuration::get('GPT4INTEGRATION_MODEL'); // GPT model

        parent::__construct();

        $this->displayName = 'ChatGPT products description translation';
        $this->description = 'Translates product descriptions by ChatGPT';

        $this->confirmUninstall = 'Are you sure you want to uninstall?';
    }

    public function install()
    {
        return parent::install() && $this->installTab() &&
            $this->registerHook('displayAdminProductsExtra');
    }

    public function uninstall()
    {
        return parent::uninstall() && $this->uninstallTab();
    }

    public function installTab()
    {
        $tab = new Tab();
        $tab->class_name = $this->controllerAdmin;
        $tab->active = 0;
        $tab->name = array();
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = $this->name;
        }
        $tab->id_parent = -1;
        $tab->module = $this->name;

        return $tab->add();
    }

    public function uninstallTab()
    {
        $id_tab = (int)Tab::getIdFromClassName($this->controllerAdmin);
        $tab = new Tab($id_tab);
        if (Validate::isLoadedObject($tab)) {
            return $tab->delete();
        }
    }

    public function hookDisplayAdminProductsExtra($params)
    {
        $this->context->smarty->assign(array(
            'controller_url' => $this->context->link->getAdminLink($this->controllerAdmin), //Give the url for ajax query
            'id_product' => $params['id_product'],//give the url for ajax query
        ));

        return $this->display(__FILE__, 'translate_button.tpl');
    }

   

    public function translateDescription($id_product, $language_code)
    {
        $product = new Product((int)$id_product);
        $selectedLanguage = null;
        $languages = Language::getLanguages(true, $this->context->shop->id); // Get all enabled languages for this shop
        foreach($languages as $language) {
            if ($language['iso_code'] == $language_code) {
                $selectedLanguage = $language;
            }
        }

        $default_lang_id = (int)Configuration::get('PS_LANG_DEFAULT'); // Get default language

        // Get product description and translate it, if an error occurs, return the error message
        $description = $product->description[$default_lang_id];
        $description = $this->requestTranslateToChatGPT($description, $selectedLanguage['name'], self::PRODUCT_LOCAL_DESCRIPTION);
        
        if (isset($description['error'])) {
            return 'Description ERROR: '.$description['error'];
        }
        $product->description[$selectedLanguage['id_lang']] = $description['content'];
       

        //Get product short description and translate it, if an error occurs, return the error message
        $description_short = $product->description_short[$default_lang_id];
        $description_short = $this->requestTranslateToChatGPT($description_short, $selectedLanguage['name'], self::PRODUCT_LOCAL_SHORT_DESCRIPTION);
        if (isset($description_short['error'])) {
            return 'Short description ERROR: '.$description_short['error'];
        }
        $product->description_short[$selectedLanguage['id_lang']] = $description_short['content'];

        //Get product name and translate it, if an error occurs, return the error message
        $name = $product->name[$default_lang_id];
        $name = $this->requestTranslateToChatGPT($name, $selectedLanguage['name'], self::PRODUCT_LOCAL_NAME);
        if (isset($name['error'])) {
            return 'Name ERROR: '.$name['error'];
        }
        $product->name[$selectedLanguage['id_lang']] = $name['content'];

        $product->update();

        return 'Product successfully translated';

    }
    


    
    public function requestTranslateToChatGPT($description, $language, $type = self::PRODUCT_LOCAL_DESCRIPTION){
        $api_key =  $this->api_key;
        $model = $this->model;

        if (!$api_key || empty($api_key) || !$model || empty($model)) {
            return null;
        }

        $url = 'https://api.openai.com/v1/chat/completions'; // URL GPT-4 API

        // Prompt for ChatGPT, if type of translation is name of product, then prompt for ChatGPT is different, withot HTML tags and most shorter
        if($type == self::PRODUCT_LOCAL_NAME){
            $prompt = 'Messages with '.$type.' of online will be sent to this chat. They need to be translated into '.$language.'. If the language is unknown, simply respond with: {not-found}. If the message is already in '.$language.', the response will be {already-done}';
        } else {
            $prompt = 'Messages with '.$type.' of online in HTML format will be sent to this chat. They need to be translated into '.$language.', keeping the HTML formatting in its original form. If there are no HTML tags in the text, then they are not needed. If the language is unknown, simply respond with: {not-found}. If the message is already in '.$language.', the response will be {already-done}';
        }
        $data = [
            'model' => $model, // Selected GPT model
            'messages' => [
                [
                  'role' => 'system',
                  'content' => $prompt // Text of the request
                ],
                [
                  'role' => 'user',
                  'content' => $description // Text for translate
                ],
            ],
            'max_tokens' => 2000, // Maximum number of tokens in the response
            'n' => 1, // Number of text variants
            'temperature' => 0 // Temperature (creative component)
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        $response_data = json_decode($response, true);

        $result=null;

        if(isset($response_data['error'])){
            $result['error'] = $response_data['error']['message'];
        }

        if (isset($response_data['choices'][0]['message'])) {
            // If the language is unknown, simply respond with: {not-found}
            if ($response_data['choices'][0]['message']['content'] !== '{not-found}') {
                // If the message is already in the selected language, the response will be {already-done}
                if ($response_data['choices'][0]['message']['content'] !== '{already-done}') {
                    $result['content'] = $response_data['choices'][0]['message']['content'];
                } else {
                    if(self::PRODUCT_LOCAL_NAME == $type){
                        $result['content'] = $description;
                    }
                    
                }
            } else {
                $result['error'] = 'Language not found';
            }
        }

        return $result;
    }



    

    
    public function getContent()
    {
        $output = null;

        if (Tools::isSubmit('submit'.$this->name)) {

            $api_key = strval(Tools::getValue('GPT4INTEGRATION_API_KEY'));
            if (!$api_key || empty($api_key)) {
                $output .= $this->displayError($this->l('Invalid API key'));
            } else {
                Configuration::updateValue('GPT4INTEGRATION_API_KEY', $api_key);
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }

            if (!Tools::getValue('GPT4INTEGRATION_MODEL')) {
                $output .= $this->displayError($this->l('Invalid model'));
            } else {
                Configuration::updateValue('GPT4INTEGRATION_MODEL', strval(Tools::getValue('GPT4INTEGRATION_MODEL')));
            }

        }

        return $output.$this->displayForm();
    }

    public function displayForm()
    {
        // Get default language
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        // Form fields
        $fields_form[0]['form'] = [
            'legend' => [
                'title' => $this->l('Settings'),
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('API key'),
                    'name' => 'GPT4INTEGRATION_API_KEY',
                    'size' => 64,
                    'required' => true
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Model'),
                    'name' => 'GPT4INTEGRATION_MODEL',
                    'required' => true,
                    'options' => [
                        'query' => [
                            ['id_option' => 'gpt-4', 'name' => 'gpt-4'],
                            ['id_option' => 'gpt-3.5-turbo', 'name' => 'gpt-3.5-turbo'],
                        ],
                        'id' => 'id_option',
                        'name' => 'name'
                    ],
                ],
            ],
            'submit' => [
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            ],
        ];

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;

        // Language
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;

        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;      
        $helper->toolbar_scroll = true;    
        $helper->submit_action = 'submit'.$this->name;
        $helper->toolbar_btn = [
            'save' => [
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.
                '&token='.Tools::getAdminTokenLite('AdminModules'),
            ],
            'back' => [
                'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            ]
        ];

        // Load current value
        $helper->fields_value['GPT4INTEGRATION_API_KEY'] = Configuration::get('GPT4INTEGRATION_API_KEY');

        // Load current value
        $helper->fields_value['GPT4INTEGRATION_MODEL'] = Configuration::get('GPT4INTEGRATION_MODEL');

        return $helper->generateForm($fields_form);
    }
}
