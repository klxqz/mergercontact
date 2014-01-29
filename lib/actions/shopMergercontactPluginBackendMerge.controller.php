<?php

class shopMergerorderPluginBackendMergeController extends waJsonController {

    protected $workflow;

    public function execute() {

        try {
            $order_model = new shopOrderModel();
            $log_model = new shopOrderLogModel();
            $order_id = waRequest::post('order_id', null, waRequest::TYPE_INT);
            $order_id_str = shopHelper::encodeOrderId($order_id);
            $order = $order_model->getOrder($order_id);

            $orders = waRequest::post('orders');
            if (!$orders) {
                $this->errors = 'Необходимо выбрать заказы для объединения';
                return false;
            }
            $orders_str = array();
            foreach (array_keys($orders) as $_order_id) {
                $orders_str[] = shopHelper::encodeOrderId($_order_id);
            }

            $merged_orders = implode(', ', $orders_str);

            foreach (array_keys($orders) as $_order_id) {
                $_order = $order_model->getOrder($_order_id);
                $items = $_order['items'];
                foreach ($items as &$item) {
                    unset($item['id']);
                    unset($item['order_id']);
                    $item['price'] = shop_currency($item['price'], $_order['currency'], $order['currency'], false);
                }
                $order['items'] = array_merge($order['items'], $items);
                $order['discount'] += shop_currency($_order['discount'], $_order['currency'], $order['currency'], false);

                $data = array(
                    'action_id' => 'comment',
                    'order_id' => $_order_id,
                    'before_state_id' => $order['state_id'],
                    'after_state_id' => 'delete',
                    'text' => "Удаление после объединения. Заказ $order_id_str объединен с " . $merged_orders
                );
                $log_model->add($data);
                $order_model->delete($_order_id);
            }

            $order['total'] = $this->calcTotal($order);
            $plugin_order_model = new shopMergerorderPluginOrderModel();
            $plugin_order_model->update($order, $order_id);

            $log = $log_model->getLog($order_id);

            $data = array(
                'action_id' => 'comment',
                'order_id' => $order_id,
                'before_state_id' => $order['state_id'],
                'after_state_id' => $order['state_id'],
                'text' => "Объединение заказов. Заказ $order_id_str объединен с " . $merged_orders
            );
            $log_model->add($data);

            if (waRequest::post('email_notification')) {
                $this->sendNotification($order_id, $merged_orders);
            }
            $this->response = '<h1>Заказы успешно объединены</h1>';
        } catch (Exception $e) {
            $this->errors = $e->getMessage();
        }
    }

    public function calcTotal($data) {
        $total = 0;
        foreach ($data['items'] as $item) {
            $total += $item['price'] * (int) $item['quantity'];
        }
        if ($total == 0) {
            return $total;
        }
        return $total - $data['discount'] + $data['shipping'];
    }

    public function sendNotification($order_id, $merged_orders) {
        $app_settings_model = new waAppSettingsModel();
        $order_model = new shopOrderModel();
        $order_id_str = shopHelper::encodeOrderId($order_id);
        $notification = array(
            'name' => 'Объединение заказов',
            'to' => 'customer',
            'sms' => '',
            'subject' => "Заказ $order_id_str объединен с $merged_orders",
            'body' => $app_settings_model->get(array('shop', 'mergerorder'), 'body'),
        );

        $order = $order_model->getById($order_id);

        $action_data = array();
        $action_data['order_id'] = $order_id;
        $action_data['action_id'] = 'comment';
        $action_data['before_state_id'] = $order['state_id'];
        $action_data['after_state_id'] = $order['state_id'];

        $data = array(
            'order' => $order,
            'customer' => new waContact($order['contact_id']),
            'status' => $this->getWorkflow()->getStateById($action_data['after_state_id'])->getName(),
            'action_data' => $action_data,
            'merged_orders' => $merged_orders,
        );


        $params_model = new shopOrderParamsModel();
        $data['order']['params'] = $params_model->get($data['order']['id']);
        $items_model = new shopOrderItemsModel();
        $data['order']['items'] = $items_model->getItems($data['order']['id']);
        foreach ($data['order']['items'] as &$i) {
            if (!empty($i['file_name'])) {
                $i['download_link'] = wa()->getRouteUrl('/frontend/myOrderDownload', array('id' => $data['order']['id'], 'code' => $data['order']['params']['auth_code'], 'item' => $i['id']), true);
            }
        }
        unset($i);
        if (!empty($data['order']['params']['shipping_id'])) {
            try {
                $data['shipping_plugin'] = shopShipping::getPlugin($data['order']['params']['shipping_plugin'], $data['order']['params']['shipping_id']);
            } catch (waException $e) {
                
            }
        }


        $this->sendEmail($notification, $data);
    }

    protected function sendEmail($n, $data) {
        $general = wa('shop')->getConfig()->getGeneralSettings();
        /**
         * @var waContact $customer
         */
        $customer = $data['customer'];
        if ($n['to'] == 'customer') {
            $email = $customer->get('email', 'default');
            if (!$email) {
                return;
            }
            $to = array($email);
            $log = sprintf(_w("Notification <strong>%s</strong> sent to customer."), $n['name']);
        } elseif ($n['to'] == 'admin') {
            if (!$general['email']) {
                return;
            }
            $to = array($general['email']);
            $log = sprintf(_w("Notification <strong>%s</strong> sent to store admin."), $n['name']);
        } else {
            $to = explode(',', $n['to']);
            $log = sprintf(_w("Notification <strong>%s</strong> sent to %s."), $n['name'], $n['to']);
        }

        $view = wa()->getView();

        foreach (array('shipping', 'billing') as $k) {
            $address = shopHelper::getOrderAddress($data['order']['params'], $k);
            $formatter = new waContactAddressOneLineFormatter(array('image' => false));
            $address = $formatter->format(array('data' => $address));
            $view->assign($k . '_address', $address['value']);
        }
        $order_id = $data['order']['id'];
        $data['order']['id'] = shopHelper::encodeOrderId($order_id);
        $view->assign('order_url', wa()->getRouteUrl('/frontend/myOrderByCode', array('id' => $order_id, 'code' => $data['order']['params']['auth_code']), true));
        $view->assign($data);
        $subject = $view->fetch('string:' . $n['subject']);
        $body = $view->fetch('string:' . $n['body']);

        $message = new waMailMessage($subject, $body);
        $message->setTo($to);
        if ($general['email']) {
            $message->setFrom($general['email'], $general['name']);
        }
        if ($message->send()) {
            $order_log_model = new shopOrderLogModel();
            $order_log_model->add(array(
                'order_id' => $order_id,
                'contact_id' => null,
                'action_id' => '',
                'text' => '<i class="icon16 email"></i> ' . $log,
                'before_state_id' => $data['order']['state_id'],
                'after_state_id' => $data['order']['state_id'],
            ));
        }
    }

    public function getWorkflow() {
        if (!$this->workflow) {
            $this->workflow = new shopWorkflow();
        }
        return $this->workflow;
    }

}
