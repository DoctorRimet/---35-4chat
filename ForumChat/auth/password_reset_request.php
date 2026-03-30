<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';

$db = new Database();
$conn = $db->getConnection();
$user = new User($conn);

$errors = [];
$success = false;
$resetLink = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email === '') {
        $errors[] = 'Email обязателен.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Некорректный email.';
    } else {
        $token = $user->createPasswordResetTokenByEmail($email);
        $success = true;

        if ($token) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $base = rtrim(dirname($_SERVER['REQUEST_URI']), '/\\');
            $resetLink = "$scheme://$host$base/password_reset.php?token=" . urlencode($token);

            $subject = 'Сброс пароля ForumChat';
            $message = "Здравствуйте,\n\nДля сброса пароля перейдите по ссылке:\n$resetLink\n\nСсылка действительна в течение 1 часа. Если вы не запрашивали сброс пароля, просто проигнорируйте это письмо.\n\nС уважением, команда ForumChat";
            $headers = 'From: no-reply@' . $host . "\r\n" .
                       'MIME-Version: 1.0\r\n' .
                       'Content-Type: text/plain; charset=UTF-8\r\n';

            if (!mail($email, $subject, $message, $headers)) {
                // Почтовая отправка может быть не настроена на сервере.
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Восстановление пароля — ForumChat</title>
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
                <h4 class="fw-bold mb-1">Восстановление пароля</h4>
                <p class="text-muted small mb-4">Введите email, на который зарегистрирован аккаунт.</p>

                <?php if ($success): ?>
                <div class="alert alert-success">
                    Если адрес зарегистрирован, мы отправили ссылку для сброса пароля.
                </div>
                <?php if ($resetLink): ?>
                <div class="alert alert-secondary small">
                    Тестовая ссылка: <a href="<?= htmlspecialchars($resetLink) ?>"><?= htmlspecialchars($resetLink) ?></a>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <form method="post">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-uppercase text-secondary" for="email">Email</label>
                        <input type="email" id="email" name="email" class="form-control" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary-custom btn-primary w-100">Отправить ссылку</button>
                </form>
                <div class="text-center mt-4 small text-muted">
                    <a href="login.php" class="text-decoration-none">Вернуться на страницу входа</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
