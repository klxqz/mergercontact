<?php

class shopMergercontactPlugin extends shopPlugin {

    public function orderActionCreate($params) {
        //$settings = $this->getSettings();
        //print_r($settings);
        //print_r($params);exit;
        $contact = new waContact($params['contact_id']);
        
        
        $contact_data = $contact->load();
echo $contact->get('email', "default");
        //print_r($contact_data);exit;

        $h = 'search/inn=111222333';
        $collection = new waContactsCollection($h);
        $contacts = $collection->getContacts('*');
        print_r($contacts);exit;
                
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
        $collection = new contactsCollection('id/' . implode(',', $merge_ids));
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
        wa()->event('merge', $params);

        // Delete all merged contacts
        $contact_model = new waContactModel();
        $contact_model->delete(array_keys($contacts_data), false); // false == do not trigger event

        return $result;
    }

}
