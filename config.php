<?php
// Локальные настройки можно переопределить в config.local.php (доступ закрыт Apache).
$config = ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'library_is', 'user' => 'root', 'password' => ''];
if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace($config, require __DIR__ . '/config.local.php');
}
return $config;
