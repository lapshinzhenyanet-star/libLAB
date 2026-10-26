<?php
$advanced = $page === 'search';
$filters = [];
foreach (['q','title','author','isbn','genre'] as $key) {
    $filters[$key] = isset($_GET[$key]) && is_string($_GET[$key]) ? mb_substr(trim($_GET[$key]),0,200) : '';
}
$where = ['b.archived_at IS NULL']; $params = [];
if ($advanced) {
    foreach (['title','author','isbn','genre'] as $key) if ($filters[$key] !== '') {
        $where[] = "b.$key LIKE ?";
        $params[] = '%'.($key === 'isbn' ? str_replace(['-',' '], '', $filters[$key]) : $filters[$key]).'%';
    }
} elseif ($filters['q'] !== '') {
    $where[] = '(b.title LIKE ? OR b.author LIKE ? OR b.genre LIKE ?)';
    $params = array_fill(0,3,'%'.$filters['q'].'%');
}
$count = (int)query('SELECT COUNT(*) FROM books b WHERE '.implode(' AND ',$where),$params)->fetchColumn();
$pagesCount = max(1,(int)ceil($count/24));
$currentPage = max(1,min($pagesCount,(int)($_GET['p'] ?? 1))); $offset = ($currentPage-1)*24;
$books = query(BOOK_SELECT.' WHERE '.implode(' AND ',$where)." ORDER BY b.id LIMIT 24 OFFSET $offset",$params)->fetchAll();
?>
<?php if ($advanced): ?>
<section class="panel search-panel"><h1><span class="purple">⌕</span> Расширенный поиск</h1><p class="muted subtitle">Найдите нужную книгу по различным критериям</p>
<form method="get" class="form-grid"><input type="hidden" name="page" value="search">
<?php foreach (['title'=>['Название книги','Например: 1984'],'author'=>['Автор','Например: Оруэлл'],'isbn'=>['ISBN','9785170988255'],'genre'=>['Жанр','Например: Фэнтези']] as $key=>$label): ?>
<label><?= $label[0] ?><input name="<?= $key ?>" maxlength="200" value="<?= e($filters[$key]) ?>" placeholder="<?= $label[1] ?>"></label>
<?php endforeach; ?><button class="button primary full" type="submit">⌕ Найти книги</button></form></section>
<?php else: ?>
<div class="catalog-toolbar"><form method="get" class="quick-search"><span aria-hidden="true">⌕</span><input type="search" name="q" maxlength="200" value="<?= e($filters['q']) ?>" aria-label="Быстрый поиск по названию, автору или жанру" placeholder="Найти книгу, автора или жанр..."><button class="button small outline" type="submit">Найти</button></form>
<?php if (is_admin($user)): ?><a class="button primary" href="index.php?page=book_form">+ Добавить книгу</a><?php endif; ?></div>
<?php endif; ?>
<?php $searched = $advanced ? count(array_filter(array_intersect_key($filters,array_flip(['title','author','isbn','genre'])))) : $filters['q'] !== ''; ?>
<?php if (!$advanced || $searched): ?>
<?php if ($searched): ?><div class="result-line"><span>Найдено книг: <?= $count ?></span><a href="index.php<?= $advanced ? '?page=search' : '' ?>">Сбросить поиск</a></div><?php endif; ?>
<div class="book-grid">
<?php foreach ($books as $book): ?>
<button class="book-card" type="button" data-open="book-<?= $book['id'] ?>">
<span class="card-top"><span class="book-icon"><?= icon($book['icon']) ?></span><span class="badge <?= $book['available'] ? 'available' : 'issued' ?>"><?= $book['available'] ? 'Доступна' : 'Выдана' ?></span></span>
<strong><?= e($book['title']) ?></strong><span class="muted author"><?= e($book['author']) ?></span><span class="genre"><?= e($book['genre']) ?></span>
</button>
<?php endforeach; ?>
</div>
<?php if (!$books): ?><section class="panel empty"><span class="empty-icon">⌕</span><h2>Книги не найдены</h2><p class="muted">Попробуйте изменить условия поиска.</p></section><?php endif; ?>
<?php if ($pagesCount>1): ?><nav class="pagination" aria-label="Страницы каталога"><?php for ($i=max(1,$currentPage-3);$i<=min($pagesCount,$currentPage+3);$i++): ?><a class="button small <?= $i===$currentPage ? 'primary' : 'outline' ?>" href="?<?= e(http_build_query(array_merge($filters,['page'=>$page,'p'=>$i]))) ?>"><?= $i ?></a><?php endfor; ?></nav><?php endif; ?>
<?php foreach ($books as $book): ?>
<dialog id="book-<?= $book['id'] ?>" class="modal book-modal" aria-labelledby="book-title-<?= $book['id'] ?>"><button class="close" data-close aria-label="Закрыть">×</button>
<div class="book-heading"><span class="book-icon"><?= icon($book['icon']) ?></span><div><h2 id="book-title-<?= $book['id'] ?>"><?= e($book['title']) ?></h2><p class="muted"><?= e($book['author']) ?></p></div></div>
<div class="inline"><span class="badge <?= $book['available'] ? 'available' : 'issued' ?>"><?= $book['available'] ? 'Доступна' : 'Выдана' ?></span><span class="genre"><?= e($book['genre']) ?></span></div>
<p class="muted">ISBN: <?= e($book['isbn']) ?> · <?= $book['publication_year'] ?> г.</p><p class="description"><?= nl2br(e($book['description'])) ?></p><p>Доступно экземпляров: <strong><?= $book['available'] ?> из <?= $book['total'] ?></strong></p>
<div class="modal-actions"><button class="button outline" data-close>Закрыть</button>
<?php if ($user && $book['available']): ?><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="request"><input type="hidden" name="book_id" value="<?= $book['id'] ?>"><button class="button primary" type="submit">▣ Взять книгу</button></form>
<?php elseif (!$user): ?><a class="button primary" href="index.php?page=login">Войти, чтобы взять</a><?php else: ?><button class="button" disabled>Нет экземпляров</button><?php endif; ?></div>
<?php if (is_admin($user)): ?><div class="admin-actions"><a class="button small outline" href="index.php?page=book_form&amp;id=<?= $book['id'] ?>">Редактировать</a><button class="button small text-danger" data-open="archive-book-<?= $book['id'] ?>">Удалить</button></div><?php endif; ?></dialog>
<?php if (is_admin($user)): ?><dialog id="archive-book-<?= $book['id'] ?>" class="modal"><h2>Удалить книгу?</h2><p>«<?= e($book['title']) ?>» будет убрана из каталога. История выдач сохранится. Удалить книгу можно после возврата всех экземпляров.</p><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="archive_book"><input type="hidden" name="book_id" value="<?= $book['id'] ?>"><div class="modal-actions"><button type="button" class="button outline" data-close>Отмена</button><button class="button danger-button">Удалить</button></div></form></dialog><?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>
