<?php

return [

    /*
    | Modelo de clientes del proyecto (se usa para detectar documentos duplicados).
    */
    'customer_model' => \App\Models\Customer::class,

    /*
    | Página oficial donde la DNIT publica el listado de RUC (ruc0.zip … ruc9.zip).
    */
    'listado_url' => 'https://www.dnit.gov.py/web/portal-institucional/listado-de-ruc-con-sus-equivalencias',

    /*
    | Actualización automática mensual con el scheduler de Laravel.
    | Solo corre si el servidor ejecuta "php artisan schedule:run" cada minuto.
    */
    'schedule' => [
        'enabled' => true,
        'day' => 5,
        'time' => '03:00',
    ],

];
