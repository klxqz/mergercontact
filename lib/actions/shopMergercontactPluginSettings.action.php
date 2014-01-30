<?php

/**
 * @author wa-plugins.ru <support@wa-plugins.ru>
 * @link http://wa-plugins.ru/
 */
class shopMergercontactPluginSettingsAction extends waViewAction {

    public function execute() {
        $app_settings_model = new waAppSettingsModel();
        $settings = $app_settings_model->get(array('shop', 'mergercontact'));
        if (isset($settings['mergerfields'])) {
            $settings['mergerfields'] = json_decode($settings['mergerfields'], true);
        } else {
            $settings['mergerfields'] = array(
                'phone' => '0',
                'email' => '0',
                'name' => '0',
                'inn' => '0',
            );
        }
        $fields = array('name' => 'Полное имя', 'email' => 'Email', 'phone' => 'Телефон', 'inn' => 'ИНН');
        $this->view->assign('fields', $fields);
        $this->view->assign('settings', $settings);
    }

}
