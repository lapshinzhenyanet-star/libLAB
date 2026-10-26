<?php
// Запускать только из терминала. Веб-установщика с правами root нет.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/bootstrap.php';
try {
    $config = require __DIR__ . '/../config.php';
    if ($config['database'] !== 'library_is') throw new RuntimeException('Установщик работает только с базой library_is, чтобы не затронуть другие проекты.');
    $server = new PDO("mysql:host={$config['host']};port={$config['port']};charset=utf8mb4",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $server->exec(file_get_contents(__DIR__.'/../database/schema.sql'));
    if ((int)query('SELECT COUNT(*) FROM books')->fetchColumn() === 0 && (int)query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        transaction(function () { db()->exec(file_get_contents(__DIR__.'/../database/seed.sql')); });
        echo "Демонстрационные книги и читатели добавлены.\n";
    }
    echo "База library_is готова. Для администратора: php cli/admin.php admin@example.test\n";
} catch (Throwable $error) { fwrite(STDERR,'Ошибка установки: '.$error->getMessage()."\n"); exit(1); }
