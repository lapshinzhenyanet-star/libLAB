<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/actions.php';
try {
    db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') handle_action();
    $user = current_user();
    $page = $_GET['page'] ?? 'catalog';
    if (!is_string($page)) $page = 'catalog';
    $pages = ['catalog','search','my','manage','book_form','login','register','profile'];
    if (!in_array($page,$pages,true)) { http_response_code(404); $page = 'not_found'; }
    if (in_array($page,['manage','book_form'],true)) $user = require_admin();
    if (in_array($page,['my','profile'],true)) $user = require_login();
    $overdue = $user ? (int)query('SELECT COUNT(*) FROM loans WHERE user_id=? AND returned_at IS NULL AND due_date<CURDATE()',[$user['id']])->fetchColumn() : 0;
    $notice = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    require __DIR__ . '/includes/header.php';
    if ($page === 'not_found') echo '<section class="panel empty"><h1>Страница не найдена</h1><a class="button primary" href="index.php">Открыть каталог</a></section>';
    else require __DIR__ . '/pages/' . $page . '.php';
    require __DIR__ . '/includes/footer.php';
} catch (Throwable $error) {
    error_log('Library: ' . $error->getMessage());
    http_response_code(503);
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Библиотека</title><link rel="stylesheet" href="assets/style.css"><main class="container"><section class="panel"><h1>Библиотека временно недоступна</h1><p>Проверьте, что MySQL запущен, база импортирована и настройки подключения верны.</p><p>Инструкция по установке находится в README.md в папке проекта.</p><a href="index.php">Повторить подключение</a></section></main></html>';
}
