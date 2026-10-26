<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../includes/bootstrap.php';
$tag='race_'.bin2hex(random_bytes(5)); $password=bin2hex(random_bytes(10));
$users=[];$jars=[];$bookId=0;$handles=[];$multi=null;$failed=false;
try {
    foreach (['admin1','admin2','reader1','reader2'] as $key) {
        query('INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)',[$tag.' '.$key,$tag.'.'.$key.'@example.test',password_hash($password,PASSWORD_DEFAULT),str_starts_with($key,'admin')?'admin':'reader']);
        $users[$key]=(int)db()->lastInsertId();
    }
    query('INSERT INTO books(title,author,isbn,genre,publication_year,description) VALUES(?,?,?,?,?,?)',[$tag,'Проверка конкуренции','race-'.$tag,'Тест',2026,'Временная книга']);
    $bookId=(int)db()->lastInsertId();query('INSERT INTO copies(book_id) VALUES(?)',[$bookId]);
    $tokens=[];
    foreach (['admin1','admin2'] as $key) {
        $jars[$key]=tempnam(sys_get_temp_dir(),'race_');
        $ch=curl_init('http://localhost/library/index.php?page=login');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$jars[$key],CURLOPT_COOKIEFILE=>$jars[$key],CURLOPT_FOLLOWLOCATION=>true]);
        $html=curl_exec($ch);preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$match);curl_close($ch);
        $ch=curl_init('http://localhost/library/index.php');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$jars[$key],CURLOPT_COOKIEFILE=>$jars[$key],CURLOPT_FOLLOWLOCATION=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['action'=>'login','csrf'=>$match[1],'email'=>$tag.'.'.$key.'@example.test','password'=>$password])]);
        $html=curl_exec($ch);curl_close($ch);preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$match);$tokens[$key]=$match[1];
    }
    $multi=curl_multi_init();
    foreach (['admin1'=>'reader1','admin2'=>'reader2'] as $admin=>$reader) {
        $ch=curl_init('http://localhost/library/index.php');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$jars[$admin],CURLOPT_COOKIEFILE=>$jars[$admin],CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>20,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['action'=>'issue','csrf'=>$tokens[$admin],'book_id'=>$bookId,'user_id'=>$users[$reader],'request_id'=>0,'days'=>14])]);
        $handles[]=$ch;curl_multi_add_handle($multi,$ch);
    }
    do { $status=curl_multi_exec($multi,$running); if ($running) curl_multi_select($multi,1.0); } while ($running && $status===CURLM_OK);
    $success=0;$unavailable=0;
    foreach ($handles as $ch) {
        $html=curl_multi_getcontent($ch);
        if (str_contains($html,'Книга выдана читателю.')) $success++;
        if (str_contains($html,'Свободных экземпляров нет.')) $unavailable++;
    }
    if ($success!==1 || $unavailable!==1 || (int)query('SELECT COUNT(*) FROM loans WHERE book_id=? AND returned_at IS NULL',[$bookId])->fetchColumn()!==1 || (int)book_by_id($bookId)['available']!==0) throw new RuntimeException('Конкурентная выдача завершилась некорректно.');
    echo "OK: два одновременных POST-запроса из разных сессий — одна выдача, один отказ, доступно 0 экземпляров.\n";
    $loan=query('SELECT * FROM loans WHERE book_id=?',[$bookId])->fetch();
    try {
        query('INSERT INTO loans(user_id,book_id,copy_id,issued_by,due_date) VALUES(?,?,?,?,?)',[$users['reader1']===$loan['user_id']?$users['reader2']:$users['reader1'],$bookId,$loan['copy_id'],$users['admin1'],date('Y-m-d')]);
        throw new RuntimeException('База позволила повторную выдачу.');
    } catch (PDOException $error) {
        if ($error->getCode()!=='23000') throw $error;
        echo "OK: уникальное ограничение БД блокирует вторую активную выдачу экземпляра даже при прямом SQL.\n";
    }
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage()."\n");$failed=true; }
finally {
    foreach ($handles as $ch) { if ($multi) curl_multi_remove_handle($multi,$ch);curl_close($ch); }
    if ($multi) curl_multi_close($multi);
    if ($bookId) { query('DELETE FROM loans WHERE book_id=?',[$bookId]);query('DELETE FROM copies WHERE book_id=?',[$bookId]);query('DELETE FROM books WHERE id=?',[$bookId]); }
    foreach ($users as $id) query('DELETE FROM users WHERE id=?',[$id]);
    foreach ($jars as $jar) if(is_file($jar)) unlink($jar);
}
exit($failed?1:0);
