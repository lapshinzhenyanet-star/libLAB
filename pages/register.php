<section class="panel auth-panel"><span class="auth-icon">📚</span><h1>Регистрация читателя</h1><p class="muted">Создайте аккаунт и откройте свою следующую книгу</p>
<form method="post" class="form-stack"><?= csrf_input() ?><input type="hidden" name="action" value="register">
<label>Имя и фамилия<input name="name" maxlength="120" autocomplete="name" required></label>
<label>Email<input type="email" name="email" maxlength="190" autocomplete="username" required></label>
<label>Пароль<input type="password" name="password" minlength="10" maxlength="72" autocomplete="new-password" required><small class="muted">От 10 до 72 байт. Рекомендуем латинские буквы, цифры и символы.</small></label>
<label>Повторите пароль<input type="password" name="password_confirm" minlength="10" maxlength="72" autocomplete="new-password" required></label>
<button class="button primary" type="submit">Зарегистрироваться</button></form><p class="auth-footer">Уже есть аккаунт? <a href="index.php?page=login">Войти</a></p></section>
