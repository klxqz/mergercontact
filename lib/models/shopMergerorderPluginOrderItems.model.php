<?php

class shopMergerorderPluginOrderItemsModel extends shopOrderItemsModel {

    public function update($items, $order_id) {
        $old_items = $this->getByField('order_id', $order_id, 'id');
        $add = array();
        $update = array();
        $sku_stock = array();

        $parent_id = null;
        foreach ($items as $item) {

            // new item insert
            if (empty($item['id']) || empty($old_items[$item['id']])) {

                $item['order_id'] = $order_id;
                if ($item['type'] == 'product') {
                    $parent_id = $this->insert($item);
                } else {
                    $item['parent_id'] = $parent_id;
                    $add[] = $item;
                }

                // stock count
                /*
                if ($item['type'] == 'product') {
                    if (!isset($sku_stock[$item['sku_id']][$item['stock_id']])) {
                        $sku_stock[$item['sku_id']][$item['stock_id']] = 0;
                    }
                    $sku_stock[$item['sku_id']][$item['stock_id']] -= $item['quantity'];
                }
                 * 
                 */
            } else {

                // edit old item
                $item_id = $item['id'];
                $old_item = $old_items[$item_id];
                if ($old_item['type'] == 'product') {
                    $parent_id = $item_id;
                } else {
                    $item['parent_id'] = $parent_id;
                }
                $item['price'] = $this->castValue('float', $item['price']);
                $old_item['price'] = (float) $old_item['price'];
                $diff = array_diff_assoc($item, $old_item);

                // check stock changes
                if ($item['type'] == 'product') {
                    if (isset($diff['stock_id']) || isset($diff['sku_id'])) {
                        if (!isset($sku_stock[$old_item['sku_id']][$old_item['stock_id']])) {
                            $sku_stock[$old_item['sku_id']][$old_item['stock_id']] = 0;
                            $sku_stock[$item['sku_id']][$item['stock_id']] = 0;
                        }
                        $sku_stock[$old_item['sku_id']][$old_item['stock_id']] += $old_item['quantity'];
                        $sku_stock[$item['sku_id']][$item['stock_id']] -= $item['quantity'];
                    } else if (isset($diff['quantity'])) {
                        if (!isset($sku_stock[$item['sku_id']][$item['stock_id']])) {
                            $sku_stock[$item['sku_id']][$item['stock_id']] = 0;
                        }
                        $sku_stock[$item['sku_id']][$item['stock_id']] += $old_item['quantity'] - $item['quantity'];
                    }
                }

                if (!empty($diff)) {
                    $update[$item_id] = $diff;
                }
                unset($old_items[$item_id]);
            }
        }

        foreach ($update as $item_id => $item) {
            $this->updateById($item_id, $item);
        }
        if ($add) {
            $this->multipleInsert($add);
        }
        if ($old_items) {
            foreach ($old_items as $old_item) {
                $sku_stock[$old_item['sku_id']][$old_item['stock_id']] = $old_item['quantity'];
            }
            $this->deleteById(array_keys($old_items));
        }


        // Update stock count, but take into account 'update_stock_count_on_create_order'-setting and order state
        /*
        $order_model = new shopOrderModel();
        $order = $order_model->getById($order_id);
        $app_settings_model = new waAppSettingsModel();
        $update_on_create = $app_settings_model->get('shop', 'update_stock_count_on_create_order');
        if ($order['state_id'] != 'new' || $update_on_create) {
            $this->updateStockCount($sku_stock);
        }
         * 
         */
    }

}
