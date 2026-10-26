<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/bootstrap.php';
// Пароль генерируется случайно и выводится один раз, в файлы не записывается.
// Повторный запуск для того же администратора назначит новый пароль.
$email = mb_strtolower($argv[1] ?? 'admin@example.test');
if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>190) { fwrite(STDERR,"Укажите корректный email.\n"); exit(1); }
try {
    $existing = query('SELECT * FROM users WHERE email=?',[$email])->fetch();
    if ($existing && $existing['role'] !== 'admin') throw new RuntimeException('Этот email уже занят читателем. Выберите другой email.');
    $password = bin2hex(random_bytes(12));
    if ($existing) query('UPDATE users SET password_hash=?,archived_at=NULL WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$existing['id']]);
    else query("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,'admin')",['Администратор',$email,password_hash($password,PASSWORD_DEFAULT)]);
    echo "Администратор готов.\nEmail: $email\nОдноразово показанный пароль: $password\nВойдите и измените пароль в профиле.\n";
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage()."\n");exit(1); }
