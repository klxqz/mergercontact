<?php

/**
 * @author wa-plugins.ru <support@wa-plugins.ru>
 * @link http://wa-plugins.ru/
 */
class shopMergercontactPlugin extends shopPlugin {

    protected function log($result, $merged_field, $merged_value) {
        $vals = array();
        foreach ($result as $field => $value) {
            $vals[] = "$field: $value;";
        }
        $message = "\r\n" .
                "merged by: " . $merged_field . ";\r\n" .
                "value: " . $merged_value . ";\r\n" .
                implode("\r\n", $vals);
        waLog::log($message, 'shop/plugins/mergercontact.log');
    }

    public function orderActionCreate($params) {
        $settings = $this->getSettings();
        if (!$settings['status'] || !isset($settings['mergerfields'])) {
            return;
        }
        $contact_id = $params['contact_id'];
        $contact = new waContact($contact_id);
        foreach ($settings['mergerfields'] as $field => $cheched) {
            if ($cheched) {
                $value = $contact->get($field, "default");
                $h = "search/" . $field . "=" . $value;
                $collection = new waContactsCollection($h);
                $contacts = $collection->getContacts('*');
                unset($contacts[$contact_id]);
                if ($contacts) {
                    if ($settings['master'] == 'new') {
                        $master_id = $contact_id;
                        $ids = array_keys($contacts);
                    } else {
                        $master_contact = array_pop($contacts);
                        $master_id = $master_contact['id'];
                        $ids = array_keys($contacts);
                        $ids[] = $contact_id;
                    }
                    $result = $this->merge($ids, $master_id);
                    $this->log($result, $field, $value);
                    break;
                }
            }
        }
    }

    /**
     * Merge given contacts into master contact, save, send merge event, then delete slaves.
     *
     * !!! Probably should move it into something like contactsHelper
     *
     * @param array $merge_ids list of contact ids
     * @param int $master_id contact id to merge others into
     * @return array
     */
    public function merge($merge_ids, $master_id) {
        $merge_ids[] = $master_id;

        // List of contacts to merge
        $collection = new waContactsCollection('id/' . implode(',', $merge_ids));
        $contacts_data = $collection->getContacts('*');

        // Master contact data
        if (!$master_id || !isset($contacts_data[$master_id])) {
            throw new waException('No contact to merge into.');
        }
        $master_data = $contacts_data[$master_id];
        unset($contacts_data[$master_id]);
        $master = new waContact($master_id);

        $result = array(
            'total_requested' => count($contacts_data) + 1,
            'total_merged' => 0,
            'error' => '',
            'users' => 0,
        );

        // Merge all data into $master
        $data_fields = waContactFields::getAll('enabled');
        $check_duplicates = array(); // field_id => true
        foreach ($contacts_data as $id => $info) {
            if ($info['is_user']) {
                $result['users'] ++;
                unset($contacts_data[$id]);
                continue;
            }

            foreach ($data_fields as $f => $field) {
                if (!empty($info[$f])) {
                    if ($field->isMulti()) {
                        $master->add($f, $info[$f]);
                        $check_duplicates[$f] = true;
                    } else {
                        // Field does not allow multiple values.
                        // Set value if no value yet.
                        if (empty($master_data[$f])) {
                            $master[$f] = $master_data[$f] = $info[$f];
                        }
                    }
                }
            }
        }

        // Remove duplicates
        foreach (array_keys($check_duplicates) as $f) {
            $values = $master[$f];
            if (!is_array($values) || count($values) <= 1) {
                continue;
            }

            $unique_values = array(); // md5 => true
            foreach ($values as $k => $v) {
                if (is_array($v)) {
                    ksort($v);
                    $v = serialize($v);
                }
                $hash = md5(mb_strtolower($v));
                if (!empty($unique_values[$hash])) {
                    unset($values[$k]);
                    continue;
                }
                $unique_values[$hash] = true;
            }
            $master[$f] = array_values($values);
        }

        // Save master contact
        $errors = $master->save(array(), 42); // 42 == do not validate anything at all
        if ($errors) {
            $errormsg = array();
            foreach ($errors as $field => $err) {
                if (!is_array($err)) {
                    $err = array($err);
                }
                foreach ($err as $str) {
                    $errormsg[] = $field . ': ' . $str;
                }
            }

            $result['error'] = implode("\n<br>", $errormsg);
            return $result;
        }

        // Merge categories
        $category_ids = array();
        $ccm = new waContactCategoriesModel();
        foreach ($ccm->getContactsCategories($merge_ids) as $cid => $cats) {
            $category_ids += array_flip($cats);
        }
        $category_ids = array_keys($category_ids);
        $ccm->add($master_id, $category_ids);

        $result['total_merged'] = count($contacts_data) + 1;

        // Merge event
        $params = array('contacts' => array_keys($contacts_data), 'id' => $master_data['id']);
        $this->shopMergeHandler($params);

        // Delete all merged contacts
        $contact_model = new waContactModel();
        $contact_model->delete(array_keys($contacts_data), false); // false == do not trigger event

        return $result;
    }

    protected function shopMergeHandler(&$params) {
        $master_id = $params['id'];
        $merge_ids = $params['contacts'];
        $all_ids = array_merge($merge_ids, array($master_id));

        $m = new waModel();

        //
        // All the simple cases: update contact_id in tables
        //
        foreach (array(
    array('shop_cart_items', 'contact_id'),
    array('shop_checkout_flow', 'contact_id'),
    array('shop_order', 'contact_id'),
    array('shop_order_log', 'contact_id'),
    array('shop_product', 'contact_id'),
    array('shop_product_reviews', 'contact_id'),
    array('shop_affiliate_transaction', 'contact_id'), // also see below
        // No need to do this since users are never merged into other contacts
        //array('shop_coupon', 'create_contact_id'),
        //array('shop_page', 'create_contact_id'),
        //array('shop_product_pages', 'create_contact_id'),
        ) as $pair) {
            list($table, $field) = $pair;
            $sql = "UPDATE $table SET $field = :master WHERE $field in (:ids)";
            $m->exec($sql, array('master' => $master_id, 'ids' => $merge_ids));
        }

        //
        // shop_affiliate_transaction
        //
        $balance = 0.0;
        $sql = "SELECT * FROM shop_affiliate_transaction WHERE contact_id=? ORDER BY id";
        foreach ($m->query($sql, $master_id) as $row) {
            $balance += $row['amount'];
            if ($row['balance'] != $balance) {
                $m->exec("UPDATE shop_affiliate_transaction SET balance=? WHERE id=?", $balance, $row['id']);
            }
        }
        $affiliate_bonus = $balance;

        //
        // shop_customer
        //

        // Make sure it exists
        $cm = new shopCustomerModel();
        $cm->createFromContact($master_id);

        $sql = "SELECT SUM(number_of_orders) FROM shop_customer WHERE contact_id IN (:ids)";
        $number_of_orders = $m->query($sql, array('ids' => $all_ids))->fetchField();

        $sql = "SELECT MAX(last_order_id) FROM shop_customer WHERE contact_id IN (:ids)";
        $last_order_id = $m->query($sql, array('ids' => $all_ids))->fetchField();

        $sql = "UPDATE shop_customer SET number_of_orders=?, last_order_id=?, affiliate_bonus=? WHERE contact_id=?";
        $m->exec($sql, ifempty($number_of_orders, 0), ifempty($last_order_id, null), ifempty($affiliate_bonus, 0), $master_id);

        if ($number_of_orders) {
            shopCustomers::recalculateTotalSpent($master_id);
        }

        return null;
    }

}
