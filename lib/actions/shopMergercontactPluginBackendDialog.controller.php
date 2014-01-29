<?php

class shopMergerorderPluginBackendDialogController extends waJsonController {

    protected $tmp_path = 'plugins/mergerorder/templates/mergerorder.html';

    public function execute() {
        $app_settings_model = new waAppSettingsModel();
        $email_notification = $app_settings_model->get(array('shop', 'mergerorder'), 'email_notification');
        $order_id = waRequest::request('order_id');
        $order_model = new shopOrderModel();
        $order = $order_model->getOrder($order_id);
        $order = shopHelper::workupOrders($order, true);

        $orders = $this->getOrders();
        foreach ($orders as $index => $_order) {
            if ($_order['id'] == $order_id) {
                unset($orders[$index]);
            }
        }
        $view = wa()->getView();
        $view->assign('email_notification', $email_notification);
        $view->assign('order', $order);
        $view->assign('orders', $orders);
        $html = $view->fetch('plugins/mergerorder/templates/Dialog.html');
        $this->response = $html;
    }

    public function getOrders($offset = 0, $limit = 50) {

        $app_settings_model = new waAppSettingsModel();
        $settings = $app_settings_model->get(array('shop', 'mergerorder'));
        $settings['states'] = json_decode($settings['states'], true);

        $model = new shopOrderModel();
        $orders = $model->getList("*,items.name,items.type,items.quantity,contact,params", array(
            'offset' => $offset,
            'limit' => $limit,
            'where' => array('state_id' => array_keys($settings['states'])))
        );
        shopHelper::workupOrders($orders);

        return $orders;
    }

}
