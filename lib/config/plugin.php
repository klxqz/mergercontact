<?php

/**
 * @author wa-plugins.ru <support@wa-plugins.ru>
 * @link http://wa-plugins.ru/
 */
return array(
    'name' => 'Объединение контактов',
    'description' => 'Автоматическое объединение дублирующихся контактов',
    'vendor' => '985310',
    'version' => '1.0.0',
    'img' => 'img/mergercontact.png',
    'shop_settings' => true,
    'frontend' => false,
    'handlers' => array(
        'order_action.create' => 'orderActionCreate'
    ),
);
//EOF
