<?php
$id = max(0,(int)($_GET['id'] ?? 0));
$book = $id ? book_by_id($id) : ['title'=>'','author'=>'','isbn'=>'','genre'=>'','publication_year'=>date('Y'),'total'=>1,'description'=>'','icon'=>'book'];
if (!$book) { http_response_code(404); echo '<section class="panel empty"><h1>Книга не найдена</h1><a href="index.php">Вернуться в каталог</a></section>'; return; }
?>
<section class="panel editor-panel"><h1><?= $id ? 'Редактировать книгу' : 'Добавить книгу' ?></h1><p class="muted subtitle">Сведения о книге и количество экземпляров в библиотеке</p>
<form method="post" class="form-grid"><?= csrf_input() ?><input type="hidden" name="action" value="save_book"><input type="hidden" name="book_id" value="<?= $id ?>">
<?php foreach (['title'=>['Название книги',200],'author'=>['Автор',160],'isbn'=>['ISBN',20],'genre'=>['Жанр',80]] as $key=>$label): ?><label><?= $label[0] ?><input name="<?= $key ?>" maxlength="<?= $label[1] ?>" value="<?= e($book[$key]) ?>" required></label><?php endforeach; ?>
<label>Год издания<input type="number" name="publication_year" min="1450" max="<?= date('Y')+1 ?>" value="<?= $book['publication_year'] ?>" required></label>
<label>Всего экземпляров<input type="number" name="total" min="1" max="1000" value="<?= $book['total'] ?>" required></label>
<label class="full">Значок книги<select name="icon"><?php foreach (['book'=>'Открытая книга','stack'=>'Стопка книг','magic'=>'Волшебная палочка','red'=>'Красная книга','green'=>'Зелёная книга','spark'=>'Звёзды','bird'=>'Птица','desert'=>'Пустыня'] as $key=>$name): ?><option value="<?= $key ?>" <?= $key===$book['icon'] ? 'selected' : '' ?>><?= icon($key).' '.$name ?></option><?php endforeach; ?></select></label>
<label class="full">Описание<textarea name="description" rows="5" maxlength="5000"><?= e($book['description']) ?></textarea></label>
<div class="modal-actions full"><a class="button outline" href="index.php">Отмена</a><button class="button primary" type="submit">Сохранить книгу</button></div></form></section>
