<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Barnaul');
ini_set('display_errors', '0');
if (PHP_SAPI !== 'cli') {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('Cache-Control: no-store');
}

function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $c = require __DIR__ . '/../config.php';
        $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset=utf8mb4", $c['user'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec("SET time_zone = '+07:00'");
    }
    return $pdo;
}
function query(string $sql, array $params = []): PDOStatement {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $user = query('SELECT * FROM users WHERE id = ? AND archived_at IS NULL', [$_SESSION['user_id']])->fetch();
    return $user ?: null;
}
function is_admin(?array $user): bool { return $user && $user['role'] === 'admin'; }
function require_login(): array {
    $user = current_user();
    if (!$user) { header('Location: index.php?page=login'); exit; }
    return $user;
}
function require_admin(): array {
    $user = require_login();
    if (!is_admin($user)) { http_response_code(403); exit('Доступ запрещён. Эта функция доступна только администратору.'); }
    return $user;
}
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_input(): string { return '<input type="hidden" name="csrf" value="' . e(csrf()) . '">'; }
function check_csrf(): void {
    if (!isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals(csrf(), $_POST['csrf'])) {
        http_response_code(403); exit('Срок действия формы истёк. Обновите страницу и повторите действие.');
    }
}
function flash(string $message, string $type = 'success'): void { $_SESSION['flash'] = compact('message', 'type'); }
function redirect(string $page = 'catalog'): never { header('Location: index.php?page=' . $page); exit; }
function field(string $key, int $max = 200, bool $required = true): string {
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) throw new RuntimeException('Некорректный формат данных.');
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value) > $max) throw new RuntimeException('Заполните обязательные поля и соблюдайте ограничения длины.');
    return $value;
}
function number(string $key, int $min = 1, int $max = PHP_INT_MAX): int {
    $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT);
    if ($value === false || $value === null || $value < $min || $value > $max) throw new RuntimeException('Некорректное числовое значение.');
    return $value;
}
function email_field(): string {
    $email = mb_strtolower(field('email', 190));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Укажите корректный email.');
    return $email;
}
function password_field(): string {
    $password = $_POST['password'] ?? '';
    if (!is_string($password) || strlen($password) < 10 || strlen($password) > 72) throw new RuntimeException('Пароль должен содержать от 10 до 72 байт (не менее 10 латинских символов).');
    return $password;
}
function format_date(?string $date): string { return $date ? date('d.m.Y', strtotime($date)) : '—'; }
function overdue_days(string $date): int { return max(0, (int)(new DateTimeImmutable($date))->diff(new DateTimeImmutable('today'))->format('%r%a')); }
function icon(string $name): string {
    $names = ['book'=>'📖','stack'=>'📚','magic'=>'🪄','red'=>'📕','green'=>'📗','spark'=>'✨','bird'=>'🐦','desert'=>'🌵'];
    return $names[$name] ?? $names['book'];
}
// Доступность вычисляется по активным выдачам, отдельный счётчик не рассинхронизируется.
const BOOK_SELECT = "SELECT b.*, (SELECT COUNT(*) FROM copies c WHERE c.book_id=b.id AND c.archived_at IS NULL) AS total, (SELECT COUNT(*) FROM copies c WHERE c.book_id=b.id AND c.archived_at IS NULL AND NOT EXISTS (SELECT 1 FROM loans l WHERE l.copy_id=c.id AND l.returned_at IS NULL)) AS available FROM books b";
function book_by_id(int $id): ?array { return query(BOOK_SELECT . ' WHERE b.id=? AND b.archived_at IS NULL', [$id])->fetch() ?: null; }
function active_loans(?int $userId = null): array {
    $sql = 'SELECT l.*, b.title,b.author,b.icon,u.name FROM loans l JOIN books b ON b.id=l.book_id JOIN users u ON u.id=l.user_id WHERE l.returned_at IS NULL';
    return query($sql . ($userId ? ' AND l.user_id=?' : '') . ' ORDER BY l.due_date,l.id', $userId ? [$userId] : [])->fetchAll();
}
function transaction(callable $operation): mixed {
    db()->beginTransaction();
    try { $result = $operation(); db()->commit(); return $result; }
    catch (Throwable $error) { if (db()->inTransaction()) db()->rollBack(); throw $error; }
}
