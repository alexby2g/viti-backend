<?php
return [
 'name'=>env('APP_NAME','VITI'),
 'env'=>env('APP_ENV','production'),
 'debug'=>(bool)env('APP_DEBUG',false),
 'url'=>env('APP_URL','http://localhost'),
 'timezone'=>'America/La_Paz',
 'setup_secret'=>env('VITI_SETUP_SECRET'),
 'admin_secret'=>env('VITI_ADMIN_SECRET', env('VITI_SETUP_SECRET')),
 'locale'=>'es',
 'fallback_locale'=>'es',
 'faker_locale'=>'es_ES',
 'cipher'=>'AES-256-CBC',
 'key'=>env('APP_KEY'),
 'previous_keys'=>array_filter(explode(',',env('APP_PREVIOUS_KEYS',''))),
 'maintenance'=>['driver'=>'file','store'=>'database'],
];
