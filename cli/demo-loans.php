<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/bootstrap.php';
// Не перезаписывает реальные выдачи. Только первые демонстрационные записи.
try {
    $admin = query("SELECT id FROM users WHERE role='admin' AND archived_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    if (!$admin) throw new RuntimeException('Сначала создайте администратора: php cli/admin.php');
    transaction(function () use ($admin) {
        foreach ([['ivan@example.test','Мастер и Маргарита',-19,-5],['maria@example.test','Три товарища',-4,10],['alexey@example.test','Дюна',-3,11]] as [$email,$title,$issued,$due]) {
            $reader=query('SELECT id FROM users WHERE email=?',[$email])->fetchColumn();
            $book=query('SELECT id FROM books WHERE title=? AND archived_at IS NULL',[$title])->fetchColumn();
            if (!$reader || !$book || query('SELECT id FROM loans WHERE user_id=? AND book_id=?',[$reader,$book])->fetch()) continue;
            query('SELECT id FROM books WHERE id=? FOR UPDATE',[$book]);
            $copy=query('SELECT c.id FROM copies c WHERE c.book_id=? AND c.archived_at IS NULL AND NOT EXISTS(SELECT 1 FROM loans l WHERE l.copy_id=c.id AND l.returned_at IS NULL) LIMIT 1',[$book])->fetchColumn();
            if (!$copy) continue;
            query('INSERT INTO loans(user_id,book_id,copy_id,issued_by,issued_at,due_date) VALUES(?,?,?,?,?,?)',[$reader,$book,$copy,$admin,date('Y-m-d H:i:s',strtotime("$issued days")),date('Y-m-d',strtotime("$due days"))]);
        }
    });
    echo "Демонстрационные выдачи подготовлены (одна просрочена).\n";
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage()."\n"); exit(1); }
