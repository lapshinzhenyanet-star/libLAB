<?php
require_once __DIR__ . '/bootstrap.php';

// Все операции записи вызываются только POST-запросом после проверки CSRF.
function handle_action(): void {
    check_csrf();
    $return = 'catalog';
    try {
        $action = field('action', 40);
        if ($action === 'register') {
            $return = 'register';
            $name = field('name', 120);
            $email = email_field();
            $password = password_field();
            if ($password !== ($_POST['password_confirm'] ?? '')) throw new RuntimeException('Пароли не совпадают.');
            if (query('SELECT id FROM users WHERE email=?', [$email])->fetch()) throw new RuntimeException('Этот email уже зарегистрирован.');
            query("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,'reader')", [$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)db()->lastInsertId();
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            flash('Регистрация завершена. Добро пожаловать в библиотеку!'); redirect();
        }
        if ($action === 'login') {
            $return = 'login';
            $email = email_field();
            $password = $_POST['password'] ?? '';
            if (!is_string($password) || strlen($password)>72 || $password==='') throw new RuntimeException('Введите пароль.');
            $identity = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'local') . '|' . $email);
            query('DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
            if ((int)query('SELECT COUNT(*) FROM login_attempts WHERE identity_hash=? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)', [$identity])->fetchColumn() >= 10) throw new RuntimeException('Слишком много попыток входа. Попробуйте через 15 минут.');
            $user = query('SELECT * FROM users WHERE email=? AND archived_at IS NULL', [$email])->fetch();
            if (!$user || !password_verify($password, $user['password_hash'])) {
                query('INSERT INTO login_attempts (identity_hash) VALUES (?)', [$identity]);
                throw new RuntimeException('Неверный email или пароль.');
            }
            query('DELETE FROM login_attempts WHERE identity_hash=?', [$identity]);
            session_regenerate_id(true); $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            flash('Вы вошли в систему.'); redirect();
        }
        $user = require_login();
        if ($action === 'logout') {
            $_SESSION = []; session_regenerate_id(true); flash('Вы вышли из системы.'); redirect();
        }
        if ($action === 'request') {
            $id = number('book_id');
            transaction(function () use ($id, $user) {
                // Общий порядок блокировок: книга, пользователь, затем связанные записи.
                $book = query('SELECT * FROM books WHERE id=? AND archived_at IS NULL FOR UPDATE', [$id])->fetch();
                $reader = query('SELECT id FROM users WHERE id=? AND archived_at IS NULL FOR UPDATE', [$user['id']])->fetch();
                if (!$book || !$reader) throw new RuntimeException('Книга или читатель недоступны.');
                if (!book_by_id($id)['available']) throw new RuntimeException('Свободных экземпляров сейчас нет.');
                if (query('SELECT id FROM loans WHERE user_id=? AND book_id=? AND returned_at IS NULL', [$user['id'],$id])->fetch()) throw new RuntimeException('Эта книга уже выдана вам.');
                if (query("SELECT id FROM requests WHERE user_id=? AND book_id=? AND status='pending'", [$user['id'],$id])->fetch()) throw new RuntimeException('Заявка на эту книгу уже отправлена.');
                query('INSERT INTO requests (user_id,book_id) VALUES (?,?)', [$user['id'],$id]);
            });
            flash('Заявка отправлена. Администратор оформит выдачу книги.'); redirect('my');
        }
        if ($action === 'cancel_request') {
            query("UPDATE requests SET status='cancelled',processed_at=NOW() WHERE id=? AND user_id=? AND status='pending'", [number('request_id'),$user['id']]);
            flash('Заявка отменена.'); redirect('my');
        }
        if ($action === 'password') {
            $return = 'profile';
            $oldPassword = $_POST['old_password'] ?? '';
            if (!is_string($oldPassword) || strlen($oldPassword)>72 || !password_verify($oldPassword, $user['password_hash'])) throw new RuntimeException('Текущий пароль указан неверно.');
            $password = password_field();
            if ($password !== ($_POST['password_confirm'] ?? '')) throw new RuntimeException('Пароли не совпадают.');
            query('UPDATE users SET password_hash=? WHERE id=?', [password_hash($password,PASSWORD_DEFAULT),$user['id']]);
            session_regenerate_id(true); flash('Пароль изменён.'); redirect('profile');
        }
        $admin = require_admin();
        $return = 'manage';
        if ($action === 'save_book') {
            $id = number('book_id',0);
            $return = 'book_form' . ($id ? '&id='.$id : '');
            $title = field('title',200); $author = field('author',160); $genre = field('genre',80);
            $isbn = field('isbn',20);
            $digits = str_replace(['-',' '], '', $isbn);
            if (!preg_match('/^(?:[0-9]{13}|[0-9]{9}[0-9Xx])$/', $digits)) throw new RuntimeException('ISBN должен содержать 10 или 13 символов: цифры, дефисы и X для ISBN-10.');
            $isbn = strtoupper($digits);
            $year = number('publication_year',1450,(int)date('Y')+1);
            $total = number('total',1,1000);
            $description = field('description',5000,false); $icon = field('icon',20);
            if (!in_array($icon,['book','stack','magic','red','green','spark','bird','desert'],true)) throw new RuntimeException('Выберите значок из списка.');
            transaction(function () use ($id,$title,$author,$genre,$isbn,$year,$total,$description,$icon) {
                if (query('SELECT id FROM books WHERE isbn=? AND id<>?',[$isbn,$id])->fetch()) throw new RuntimeException('Книга с таким ISBN уже есть в каталоге или архиве.');
                $bookId = $id;
                if ($id) {
                    if (!query('SELECT id FROM books WHERE id=? AND archived_at IS NULL FOR UPDATE',[$id])->fetch()) throw new RuntimeException('Книга не найдена.');
                    query('UPDATE books SET title=?,author=?,genre=?,isbn=?,publication_year=?,description=?,icon=? WHERE id=?',[$title,$author,$genre,$isbn,$year,$description,$icon,$id]);
                } else {
                    query('INSERT INTO books (title,author,genre,isbn,publication_year,description,icon) VALUES (?,?,?,?,?,?,?)',[$title,$author,$genre,$isbn,$year,$description,$icon]);
                    $bookId = (int)db()->lastInsertId();
                }
                $count = (int)query('SELECT COUNT(*) FROM copies WHERE book_id=? AND archived_at IS NULL',[$bookId])->fetchColumn();
                if ($total < $count) {
                    $free = query('SELECT c.id FROM copies c WHERE c.book_id=? AND c.archived_at IS NULL AND NOT EXISTS (SELECT 1 FROM loans l WHERE l.copy_id=c.id AND l.returned_at IS NULL) ORDER BY c.id DESC',[$bookId])->fetchAll();
                    if (count($free) < $count-$total) throw new RuntimeException('Количество экземпляров нельзя уменьшить ниже количества выданных книг.');
                    foreach (array_slice($free,0,$count-$total) as $copy) query('UPDATE copies SET archived_at=NOW() WHERE id=?',[$copy['id']]);
                }
                for ($i=$count; $i<$total; $i++) query('INSERT INTO copies (book_id) VALUES (?)',[$bookId]);
            });
            flash('Книга сохранена.'); redirect();
        }
        if ($action === 'archive_book') {
            $id = number('book_id');
            transaction(function () use ($id) {
                query('SELECT id FROM books WHERE id=? FOR UPDATE',[$id]);
                if (query('SELECT id FROM loans WHERE book_id=? AND returned_at IS NULL',[$id])->fetch()) throw new RuntimeException('Сначала примите все выданные экземпляры этой книги.');
                query('UPDATE books SET archived_at=NOW() WHERE id=?',[$id]);
                query('UPDATE copies SET archived_at=NOW() WHERE book_id=? AND archived_at IS NULL',[$id]);
                query("UPDATE requests SET status='rejected',processed_at=NOW() WHERE book_id=? AND status='pending'",[$id]);
            });
            flash('Книга удалена из каталога. История сохранена.'); redirect();
        }
        if ($action === 'save_reader') {
            $id = number('user_id'); $name = field('name',120); $email = email_field();
            if (query('SELECT id FROM users WHERE email=? AND id<>?',[$email,$id])->fetch()) throw new RuntimeException('Этот email уже используется.');
            query("UPDATE users SET name=?,email=? WHERE id=? AND role='reader' AND archived_at IS NULL",[$name,$email,$id]);
            flash('Данные читателя обновлены.'); redirect('manage');
        }
        if ($action === 'archive_reader') {
            $id = number('user_id');
            transaction(function () use ($id) {
                if (!query("SELECT id FROM users WHERE id=? AND role='reader' AND archived_at IS NULL FOR UPDATE",[$id])->fetch()) throw new RuntimeException('Читатель не найден.');
                if (query('SELECT id FROM loans WHERE user_id=? AND returned_at IS NULL',[$id])->fetch()) throw new RuntimeException('Сначала примите все книги этого читателя.');
                query('UPDATE users SET archived_at=NOW() WHERE id=?',[$id]);
                query("UPDATE requests SET status='cancelled',processed_at=NOW() WHERE user_id=? AND status='pending'",[$id]);
            });
            flash('Читатель архивирован. История сохранена.'); redirect('manage');
        }
        if ($action === 'reject_request') {
            query("UPDATE requests SET status='rejected',processed_at=NOW() WHERE id=? AND status='pending'",[number('request_id')]);
            flash('Заявка отклонена.'); redirect('manage');
        }
        if ($action === 'issue') {
            $bookId = number('book_id'); $userId = number('user_id'); $days = number('days',1,90);
            $requestId = number('request_id',0);
            transaction(function () use ($bookId,$userId,$days,$requestId,$admin) {
                if (!query('SELECT id FROM books WHERE id=? AND archived_at IS NULL FOR UPDATE',[$bookId])->fetch()) throw new RuntimeException('Книга недоступна.');
                if (!query('SELECT id FROM users WHERE id=? AND archived_at IS NULL FOR UPDATE',[$userId])->fetch()) throw new RuntimeException('Читатель недоступен.');
                if (query('SELECT id FROM loans WHERE user_id=? AND book_id=? AND returned_at IS NULL',[$userId,$bookId])->fetch()) throw new RuntimeException('Эта книга уже выдана читателю.');
                $request = query("SELECT * FROM requests WHERE user_id=? AND book_id=? AND status='pending' FOR UPDATE",[$userId,$bookId])->fetch();
                if ($requestId && (!$request || (int)$request['id'] !== $requestId)) throw new RuntimeException('Заявка уже обработана или отменена.');
                $copy = query('SELECT c.id FROM copies c WHERE c.book_id=? AND c.archived_at IS NULL AND NOT EXISTS (SELECT 1 FROM loans l WHERE l.copy_id=c.id AND l.returned_at IS NULL) ORDER BY c.id LIMIT 1 FOR UPDATE',[$bookId])->fetch();
                if (!$copy) throw new RuntimeException('Свободных экземпляров нет. Выдача невозможна.');
                $due = (new DateTimeImmutable('today'))->modify("+$days days")->format('Y-m-d');
                query('INSERT INTO loans (user_id,book_id,copy_id,request_id,issued_by,due_date) VALUES (?,?,?,?,?,?)',[$userId,$bookId,$copy['id'],$request ? $request['id'] : null,$admin['id'],$due]);
                if ($request) query("UPDATE requests SET status='fulfilled',processed_at=NOW() WHERE id=?",[$request['id']]);
            });
            flash('Книга выдана читателю.'); redirect('manage');
        }
        if ($action === 'extend' || $action === 'return') {
            $id = number('loan_id');
            $days = $action === 'extend' ? number('days',1,90) : 0;
            transaction(function () use ($id,$action,$days,$admin) {
                $loan = query('SELECT * FROM loans WHERE id=? AND returned_at IS NULL FOR UPDATE',[$id])->fetch();
                if (!$loan) throw new RuntimeException('Эта выдача уже закрыта или не найдена.');
                if ($action === 'return') query('UPDATE loans SET returned_at=NOW(),returned_by=? WHERE id=?',[$admin['id'],$id]);
                else {
                    $base = max($loan['due_date'], date('Y-m-d'));
                    $due = (new DateTimeImmutable($base))->modify("+$days days")->format('Y-m-d');
                    query('UPDATE loans SET due_date=? WHERE id=?',[$due,$id]);
                    query('INSERT INTO extensions (loan_id,admin_id,old_due_date,new_due_date) VALUES (?,?,?,?)',[$id,$admin['id'],$loan['due_date'],$due]);
                }
            });
            flash($action === 'return' ? 'Возврат зарегистрирован. Экземпляр снова доступен.' : 'Срок чтения продлён.'); redirect('manage');
        }
        throw new RuntimeException('Неизвестное действие.');
    } catch (PDOException $error) {
        error_log('Library database operation: ' . $error->getMessage());
        flash($error->getCode() === '23000' ? 'Запись уже существует или связана с другими данными. Обновите страницу.' : 'Не удалось сохранить данные. Повторите действие.', 'error');
    } catch (RuntimeException $error) { flash($error->getMessage(),'error'); }
    redirect($return);
}
