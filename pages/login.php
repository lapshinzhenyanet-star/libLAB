<section class="panel auth-panel"><span class="auth-icon">📚</span><h1>Вход в библиотеку</h1><p class="muted">Войдите, чтобы брать книги и следить за сроками возврата</p>
<form method="post" class="form-stack"><?= csrf_input() ?><input type="hidden" name="action" value="login">
<label>Email<input type="email" name="email" maxlength="190" autocomplete="username" required></label>
<label>Пароль<input type="password" name="password" maxlength="72" autocomplete="current-password" required></label>
<button class="button primary" type="submit">Войти</button></form><p class="auth-footer">Ещё нет аккаунта? <a href="index.php?page=register">Зарегистрироваться</a></p></section>
