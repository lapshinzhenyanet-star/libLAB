<!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Библиотека — система управления</title><link rel="icon" href="assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/style.css"><script src="assets/app.js" defer></script></head>
<body>
<header class="topbar"><div class="header-inner">
    <a href="index.php" class="brand"><span class="brand-icon">📚</span><span><strong>Библиотека</strong><small>Система управления</small></span></a>
    <div class="account">
    <?php if ($user): ?>
        <?php if ($overdue): ?><a class="badge danger" href="index.php?page=my">♧ <?= $overdue ?> просрочено</a><?php endif; ?>
        <a class="user-pill" href="index.php?page=profile"><span class="avatar"><?= is_admin($user) ? 'АД' : 'ЧТ' ?></span><span><?= is_admin($user) ? 'Администратор' : 'Читатель' ?></span></a>
        <form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="logout"><button class="button small outline" type="submit">⇥ <span>Выход</span></button></form>
    <?php else: ?>
        <a class="button small outline" href="index.php?page=login">Вход</a><a class="button small primary" href="index.php?page=register">Регистрация</a>
    <?php endif; ?>
    </div>
</div></header>
<main class="container">
<nav class="tabs" aria-label="Главное меню">
    <a class="<?= $page==='catalog' ? 'active' : '' ?>" href="index.php">▥ <span>Каталог</span></a>
    <a class="<?= $page==='search' ? 'active' : '' ?>" href="index.php?page=search">⌕ <span>Поиск</span></a>
    <?php if ($user): ?><a class="<?= $page==='my' ? 'active' : '' ?>" href="index.php?page=my">▣ <span>Мои книги</span></a><?php endif; ?>
    <?php if (is_admin($user)): ?><a class="<?= $page==='manage' ? 'active' : '' ?>" href="index.php?page=manage">⚙ <span>Управление</span></a><?php endif; ?>
</nav>
<?php if ($notice): ?><div role="status" class="notice <?= e($notice['type']) ?>"><?= e($notice['message']) ?><button class="notice-close" aria-label="Закрыть уведомление">×</button></div><?php endif; ?>
