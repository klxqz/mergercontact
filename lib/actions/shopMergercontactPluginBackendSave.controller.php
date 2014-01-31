<?php

/**
 * @author wa-plugins.ru <support@wa-plugins.ru>
 * @link http://wa-plugins.ru/
 */
class shopMergercontactPluginBackendSaveController extends waJsonController {

    public function execute() {

        $app_settings_model = new waAppSettingsModel();
        $shop_mergercontact = waRequest::post('shop_mergercontact');
        $mergerfields = isset($shop_mergercontact['mergerfields']) ? $shop_mergercontact['mergerfields'] : array();
        unset($shop_mergercontact['mergerfields']);
        foreach ($shop_mergercontact as $name => $val) {
            $app_settings_model->set(array('shop', 'mergercontact'), $name, $val);
        }
        $app_settings_model->set(array('shop', 'mergercontact'), 'mergerfields', json_encode($mergerfields));
    }

}
