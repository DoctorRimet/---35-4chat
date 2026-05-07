<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';

$db = new Database();
$conn = $db->getConnection();
$user = new User($conn);

$errors = [];
$success = false;
$token = $_GET['token'] ?? '';
$token = trim(rawurldecode($token));

if (!$token) {
    header('Location: password_reset_request.php');
    exit;
}

$request = $user->getPasswordResetRequest($token);
if (!$request) {
    $errors[] = 'Неверный или просроченный токен. Запросите сброс пароля заново.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if (trim($password) === '') {
        $errors[] = 'Введите новый пароль.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Пароль должен содержать не менее 8 символов.';
    } elseif (strlen($password) > 1000) {
        $errors[] = 'Пароль не должен превышать 1000 символов.';
    } elseif ($password !== $password_confirm) {
        $errors[] = 'Пароли не совпадают.';
    }

    if (empty($errors)) {
        if ($user->resetPasswordByToken($token, $password)) {
            $success = true;
        } else {
            $errors[] = 'Не удалось обновить пароль. Попробуйте запросить ссылку заново.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Новый пароль — ForumChat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background-color: #f0f2f5; min-height: 100vh; }
        .auth-wrapper { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; }
        .auth-card { width: 100%; max-width: 460px; border-radius: 16px; border: none; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
        .brand-icon { width: 48px; height: 48px; background: linear-gradient(135deg,#6366f1,#8b5cf6); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: #fff; }
        .brand-name { font-size: 1.4rem; font-weight: 700; color: #1e1e2e; letter-spacing: -.5px; }
        .brand-name span { color: #6366f1; }
        .form-control { border-radius: 10px; padding: .65rem 1rem; border-color: #dee2e6; font-size: .95rem; }
        .form-control:focus { border-color: #6366f1; box-shadow: 0 0 0 .2rem rgba(99,102,241,.15); }
        .btn-primary-custom { background: linear-gradient(135deg,#6366f1,#8b5cf6); border: none; border-radius: 10px; padding: .7rem; font-weight: 600; font-size: .95rem; transition: opacity .2s, box-shadow .2s; }
        .btn-primary-custom:hover { opacity: .9; box-shadow: 0 6px 20px rgba(99,102,241,.35); }
    </style>
</head>
<body>
<div class="auth-wrapper">
    <div class="w-100" style="max-width:460px">
        <div class="d-flex align-items-center justify-content-center gap-2 mb-4">
            <div class="brand-icon">💬</div>
            <div class="brand-name">Forum<span>Chat</span></div>
        </div>
        <div class="card auth-card">
            <div class="card-body p-4">
                <h4 class="fw-bold mb-1">Установите новый пароль</h4>
                <p class="text-muted small mb-4">Введите новый пароль для вашей учетной записи.</p>

                <?php if ($success): ?>
                <div class="alert alert-success">
                    Пароль успешно обновлен. <a href="login.php" class="alert-link">Войдите</a> с новым паролем.
                </div>
                <?php else: ?>
                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <?php if ($request): ?>
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-uppercase text-secondary" for="password">Новый пароль</label>
                            <input type="password" id="password" name="password" class="form-control" placeholder="********" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-uppercase text-secondary" for="password_confirm">Повторите пароль</label>
                            <input type="password" id="password_confirm" name="password_confirm" class="form-control" placeholder="********" required>
                        </div>
                        <button type="submit" class="btn btn-primary-custom btn-primary w-100">Сохранить пароль</button>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="text-center mt-4 small text-muted">
                    <a href="login.php" class="text-decoration-none">Вернуться на страницу входа</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
