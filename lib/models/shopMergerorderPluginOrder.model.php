<?php

class shopMergerorderPluginOrderModel extends shopOrderModel {

    public function update($data, $id) {
        if (!$id && !empty($data['id'])) {
            $id = $data['id'];
        }
        if (isset($data['id'])) {
            unset($data['id']);
        }

        $items_model = new shopMergerorderPluginOrderItemsModel();

        if ($id) {
            $items_model->update($data['items'], $id);
            unset($data['items']);
            $diff = array_diff_assoc($data, $this->getById($id));
            if ($diff) {
                $this->updateById($id, $diff);
            }
        }
        return $id;
    }

}
