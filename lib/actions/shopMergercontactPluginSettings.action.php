<?php

class shopMergercontactPluginSettingsAction extends waViewAction {

    public function execute() {
        $app_settings_model = new waAppSettingsModel();
        $settings = $app_settings_model->get(array('shop', 'mergercontact'));
        if (isset($settings['mergerfields'])) {
            $settings['mergerfields'] = json_decode($settings['mergerfields'], true);
        }
        $fields = array('name' => 'Полное имя', 'email' => 'Email', 'phone' => 'Телефон','inn' => 'ИНН');
        $this->view->assign('fields', $fields);
        $this->view->assign('settings', $settings);
    }

}
