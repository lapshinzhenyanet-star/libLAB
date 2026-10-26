<?php
// Реальные HTTP-запросы к Apache и проверки MySQL. Использует только собственные
// временные записи с уникальным префиксом и удаляет их в finally.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/bootstrap.php';
$base = 'http://localhost/library/';
$prefix = 'qa_'.bin2hex(random_bytes(5));
$password = bin2hex(random_bytes(10));
$adminId=0; $readerIds=[]; $bookId=0; $cookies=[]; $passed=0;
function check(bool $condition,string $message): void {
    global $passed;
    if (!$condition) throw new RuntimeException('FAIL: '.$message);
    $passed++; echo "OK $passed: $message\n";
}
function request_http(string $path,string $jar,?array $post=null): array {
    global $base;
    $ch=curl_init($base.$path);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>20]);
    if ($post!==null) curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
    $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
    if ($body===false) throw new RuntimeException('HTTP: '.$error);
    return [$code,$body];
}
function post_action(string $jar,string $page,array $data): array {
    [, $html]=request_http('index.php?page='.$page,$jar);
    if (!preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$match)) throw new RuntimeException('CSRF token absent: '.$page);
    return request_http('index.php',$jar,array_merge(['csrf'=>$match[1]],$data));
}
try {
    foreach (['admin','reader','second','guest'] as $key) $cookies[$key]=tempnam(sys_get_temp_dir(),'libqa_');
    query("INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,'admin')",[$prefix,$prefix.'@example.test',password_hash($password,PASSWORD_DEFAULT)]);
    $adminId=(int)db()->lastInsertId();
    [$code,$html]=request_http('',$cookies['guest']);
    check($code===200 && str_contains($html,'1984'),'Каталог доступен без входа');
    [$code]=request_http('index.php',$cookies['guest'],['action'=>'register','name'=>'fake']);
    check($code===403,'POST без CSRF отклонён');
    [$code,$html]=post_action($cookies['guest'],'register',['action'=>'register','name'=>'Invalid','email'=>'not-an-email','password'=>$password,'password_confirm'=>$password]);
    check(str_contains($html,'корректный email'),'Некорректный email отклонён сервером');
    foreach (['reader','second'] as $index=>$key) {
        [$code,$html]=post_action($cookies[$key],'register',['action'=>'register','name'=>$prefix.' '.$key,'email'=>$prefix.'.'.$key.'@example.test','password'=>$password,'password_confirm'=>$password,'role'=>'admin']);
        $reader=query('SELECT * FROM users WHERE email=?',[$prefix.'.'.$key.'@example.test'])->fetch();
        check($code===200 && $reader && $reader['role']==='reader','Регистрация '.$key.' создаёт только читателя');
        $readerIds[]=(int)$reader['id'];
        check(password_verify($password,$reader['password_hash']),'Пароль '.$key.' хранится хешем');
    }
    [$code,$html]=post_action($cookies['reader'],'register',['action'=>'register','name'=>'Duplicate','email'=>$prefix.'.reader@example.test','password'=>$password,'password_confirm'=>$password]);
    check(str_contains($html,'уже зарегистрирован'),'Повторная регистрация email запрещена');
    post_action($cookies['reader'],'my',['action'=>'logout']);
    [$code,$html]=post_action($cookies['reader'],'login',['action'=>'login','email'=>$prefix.'.reader@example.test','password'=>'wrong-password']);
    check(str_contains($html,'Неверный email или пароль'),'Неверный пароль отклонён');
    [$code,$html]=post_action($cookies['reader'],'login',['action'=>'login','email'=>$prefix.'.reader@example.test','password'=>$password]);
    check(str_contains($html,'Вы вошли в систему'),'Авторизация читателя');
    [$code]=request_http('index.php?page=manage',$cookies['reader']);
    check($code===403,'Читатель не может открыть управление');
    [$code]=post_action($cookies['reader'],'my',['action'=>'save_book','book_id'=>'0']);
    check($code===403,'Читатель не может вызвать административный POST');
    [$code,$html]=post_action($cookies['admin'],'login',['action'=>'login','email'=>$prefix.'@example.test','password'=>$password]);
    check(str_contains($html,'Администратор'),'Авторизация администратора');
    $bookFields=['action'=>'save_book','book_id'=>0,'title'=>$prefix.' <script>alert(1)</script>','author'=>'Тестовый Автор','isbn'=>'9781234567890','genre'=>'Проверка','publication_year'=>2026,'total'=>1,'description'=>'Описание <b>книги</b>','icon'=>'book'];
    // Уникальный ISBN-13 для повторяемого теста.
    $bookFields['isbn']='979'.str_pad((string)random_int(0,999999999),10,'0',STR_PAD_LEFT);
    post_action($cookies['admin'],'book_form',$bookFields);
    $bookId=(int)query('SELECT id FROM books WHERE isbn=?',[$bookFields['isbn']])->fetchColumn();
    check($bookId>0 && (int)book_by_id($bookId)['available']===1,'Добавление книги и экземпляра');
    $invalidBook=$bookFields;$invalidBook['total']=-1;
    [$code,$html]=post_action($cookies['admin'],'book_form',$invalidBook);
    check(str_contains($html,'Некорректное числовое значение'),'Отрицательное количество экземпляров отклонено');
    [$code,$html]=post_action($cookies['admin'],'book_form',$bookFields);
    check(str_contains($html,'таким ISBN уже есть'),'Повторный ISBN запрещён');
    [$code,$html]=request_http('index.php?q='.urlencode($prefix),$cookies['reader']);
    check(str_contains($html,e($bookFields['title'])) && !str_contains($html,'<script>alert(1)</script>'),'Быстрый поиск и экранирование XSS');
    [$code,$html]=request_http('index.php?page=search&title='.urlencode($prefix).'&author='.urlencode('Тестовый').'&isbn='.$bookFields['isbn'].'&genre='.urlencode('Проверка'),$cookies['reader']);
    check(str_contains($html,e($bookFields['title'])),'Расширенный поиск по четырём полям');
    [$code,$html]=request_http('index.php?page=search&isbn='.urlencode(substr($bookFields['isbn'],0,3).'-'.substr($bookFields['isbn'],3)),$cookies['reader']);
    check(str_contains($html,e($bookFields['title'])),'Поиск ISBN распознаёт дефисы');
    [$code,$html]=request_http('index.php?q='.urlencode("' OR 1=1 --"),$cookies['reader']);
    check($code===200 && str_contains($html,'Книги не найдены'),'SQL-инъекция в поиске не выполняется');
    post_action($cookies['reader'],'catalog',['action'=>'request','book_id'=>$bookId]);
    $requestId=(int)query("SELECT id FROM requests WHERE user_id=? AND book_id=? AND status='pending'",[$readerIds[0],$bookId])->fetchColumn();
    check($requestId>0,'Читатель создал заявку');
    [$code,$html]=post_action($cookies['reader'],'catalog',['action'=>'request','book_id'=>$bookId]);
    check(str_contains($html,'уже отправлена') && (int)query('SELECT COUNT(*) FROM requests WHERE user_id=? AND book_id=?',[$readerIds[0],$bookId])->fetchColumn()===1,'Дублирование заявки запрещено');
    post_action($cookies['admin'],'manage',['action'=>'issue','book_id'=>$bookId,'user_id'=>$readerIds[0],'request_id'=>$requestId,'days'=>14]);
    $loan=query('SELECT * FROM loans WHERE request_id=?',[$requestId])->fetch();
    check((bool)$loan && $loan['due_date']===date('Y-m-d',strtotime('+14 days')),'Выдача создаёт срок возврата');
    check((int)book_by_id($bookId)['available']===0,'После выдачи свободных экземпляров 0');
    check(query('SELECT status FROM requests WHERE id=?',[$requestId])->fetchColumn()==='fulfilled','Заявка отмечена выполненной');
    [$code,$html]=post_action($cookies['admin'],'manage',['action'=>'issue','book_id'=>$bookId,'user_id'=>$readerIds[0],'request_id'=>0,'days'=>14]);
    check(str_contains($html,'уже выдана'),'Повторная активная выдача тому же читателю запрещена');
    [$code,$html]=post_action($cookies['admin'],'manage',['action'=>'issue','book_id'=>$bookId,'user_id'=>$readerIds[1],'request_id'=>0,'days'=>14]);
    check(str_contains($html,'Свободных экземпляров нет') && (int)query('SELECT COUNT(*) FROM loans WHERE book_id=?',[$bookId])->fetchColumn()===1,'Выдача недоступного экземпляра другому читателю запрещена');
    [$code,$html]=post_action($cookies['second'],'catalog',['action'=>'request','book_id'=>$bookId]);
    check(str_contains($html,'Свободных экземпляров сейчас нет'),'Заявка на недоступную книгу запрещена');
    [$code,$html]=post_action($cookies['admin'],'manage',['action'=>'archive_book','book_id'=>$bookId]);
    check(str_contains($html,'Сначала примите') && book_by_id($bookId)!==null,'Нельзя удалить книгу на руках');
    [$code,$html]=post_action($cookies['admin'],'manage',['action'=>'archive_reader','user_id'=>$readerIds[0]]);
    check(str_contains($html,'Сначала примите'),'Нельзя архивировать читателя с книгами');
    query('UPDATE loans SET due_date=DATE_SUB(CURDATE(),INTERVAL 5 DAY) WHERE id=?',[$loan['id']]);
    [$code,$html]=request_http('index.php?page=my',$cookies['reader']);
    check(str_contains($html,'1 просрочено') && str_contains($html,'Просрочено 5 дн.'),'Просрочка автоматически отображается в меню и списке');
    post_action($cookies['admin'],'manage',['action'=>'extend','loan_id'=>$loan['id'],'days'=>14]);
    check(query('SELECT due_date FROM loans WHERE id=?',[$loan['id']])->fetchColumn()===date('Y-m-d',strtotime('+14 days')),'Продление просроченной выдачи от сегодняшней даты');
    check((int)query('SELECT COUNT(*) FROM extensions WHERE loan_id=?',[$loan['id']])->fetchColumn()===1,'История продления сохранена');
    [$code,$html]=post_action($cookies['admin'],'manage',['action'=>'extend','loan_id'=>$loan['id'],'days'=>-1]);
    check(str_contains($html,'Некорректное числовое значение'),'Отрицательный срок отклонён');
    post_action($cookies['admin'],'manage',['action'=>'return','loan_id'=>$loan['id']]);
    check((bool)query('SELECT returned_at FROM loans WHERE id=?',[$loan['id']])->fetchColumn() && (int)book_by_id($bookId)['available']===1,'Возврат записан, экземпляр снова доступен');
    [$code,$html]=post_action($cookies['admin'],'manage',['action'=>'return','loan_id'=>$loan['id']]);
    check(str_contains($html,'уже закрыта') && (int)book_by_id($bookId)['available']===1,'Повторный возврат не увеличивает количество');
    [$code,$html]=request_http('index.php?page=my',$cookies['reader']);
    check(str_contains($html,'История чтения') && !str_contains($html,'1 просрочено'),'Возврат остаётся в истории, уведомление исчезает');
    $bookFields['book_id']=$bookId;$bookFields['total']=2;$bookFields['title']=$prefix.' Изменённая книга';
    post_action($cookies['admin'],'book_form&id='.$bookId,$bookFields);
    check((int)book_by_id($bookId)['available']===2 && book_by_id($bookId)['title']===$bookFields['title'],'Редактирование книги и увеличение экземпляров');
    post_action($cookies['admin'],'manage',['action'=>'issue','book_id'=>$bookId,'user_id'=>$readerIds[0],'request_id'=>0,'days'=>14]);
    post_action($cookies['admin'],'manage',['action'=>'issue','book_id'=>$bookId,'user_id'=>$readerIds[1],'request_id'=>0,'days'=>14]);
    $bookFields['total']=1; [$code,$html]=post_action($cookies['admin'],'book_form&id='.$bookId,$bookFields);
    check(str_contains($html,'нельзя уменьшить') && (int)book_by_id($bookId)['total']===2,'Нельзя уменьшить экземпляры ниже активных выдач');
    foreach (query('SELECT id FROM loans WHERE book_id=? AND returned_at IS NULL',[$bookId])->fetchAll() as $active) post_action($cookies['admin'],'manage',['action'=>'return','loan_id'=>$active['id']]);
    post_action($cookies['reader'],'catalog',['action'=>'request','book_id'=>$bookId]);
    $cancel=(int)query("SELECT id FROM requests WHERE user_id=? AND book_id=? AND status='pending'",[$readerIds[0],$bookId])->fetchColumn();
    post_action($cookies['reader'],'my',['action'=>'cancel_request','request_id'=>$cancel]);
    check(query('SELECT status FROM requests WHERE id=?',[$cancel])->fetchColumn()==='cancelled','Отмена своей заявки');
    post_action($cookies['second'],'catalog',['action'=>'request','book_id'=>$bookId]);
    $reject=(int)query("SELECT id FROM requests WHERE user_id=? AND book_id=? AND status='pending'",[$readerIds[1],$bookId])->fetchColumn();
    post_action($cookies['admin'],'manage',['action'=>'reject_request','request_id'=>$reject]);
    check(query('SELECT status FROM requests WHERE id=?',[$reject])->fetchColumn()==='rejected','Отклонение заявки администратором');
    post_action($cookies['admin'],'manage',['action'=>'save_reader','user_id'=>$readerIds[0],'name'=>$prefix.' Обновлён','email'=>$prefix.'.reader@example.test']);
    check(query('SELECT name FROM users WHERE id=?',[$readerIds[0]])->fetchColumn()===$prefix.' Обновлён','Редактирование читателя');
    $newPassword=' '.$password.' ';
    post_action($cookies['reader'],'profile',['action'=>'password','old_password'=>$password,'password'=>$newPassword,'password_confirm'=>$newPassword]);
    post_action($cookies['reader'],'profile',['action'=>'logout']);
    [$code,$html]=post_action($cookies['reader'],'login',['action'=>'login','email'=>$prefix.'.reader@example.test','password'=>$newPassword]);
    check(str_contains($html,'Вы вошли в систему'),'Смена пароля и вход с пробелами в пароле');
    post_action($cookies['admin'],'manage',['action'=>'archive_book','book_id'=>$bookId]);
    check(book_by_id($bookId)===null && (int)query('SELECT COUNT(*) FROM loans WHERE book_id=?',[$bookId])->fetchColumn()===3,'Архивирование книги сохраняет выдачи');
    post_action($cookies['admin'],'manage',['action'=>'archive_reader','user_id'=>$readerIds[0]]);
    [$code,$html]=request_http('index.php?page=my',$cookies['reader']);
    check(str_contains($html,'Вход в библиотеку'),'Архивированный читатель теряет доступ');
    foreach (['config.php','config.local.php','database/schema.sql','includes/bootstrap.php','cli/admin.php','tests/integration.php','README.md'] as $path) {
        [$code]=request_http($path,$cookies['guest']); check($code===403,'Закрыт веб-доступ к '.$path);
    }
    echo "SUCCESS: $passed проверок\n";
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage()."\n"); $failed=true; }
finally {
    if ($bookId) {
        query('DELETE FROM extensions WHERE loan_id IN (SELECT id FROM loans WHERE book_id=?)',[$bookId]);
        query('DELETE FROM loans WHERE book_id=?',[$bookId]);query('DELETE FROM requests WHERE book_id=?',[$bookId]);
        query('DELETE FROM copies WHERE book_id=?',[$bookId]);query('DELETE FROM books WHERE id=?',[$bookId]);
    }
    foreach (array_merge($readerIds,[$adminId]) as $id) if ($id) query('DELETE FROM users WHERE id=?',[$id]);
    foreach ([$prefix.'.reader@example.test',$prefix.'.second@example.test',$prefix.'@example.test'] as $email) query('DELETE FROM login_attempts WHERE identity_hash=?',[hash('sha256','::1|'.$email)]);
    foreach ($cookies as $jar) if (is_file($jar)) unlink($jar);
}
exit(!empty($failed) ? 1 : 0);
