<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';

$db = new Database();
$conn = $db->getConnection();
$user = new User($conn);

$token = trim(rawurldecode($_GET['token'] ?? ''));
$success = false;
$error = null;

if (!$token) {
    $error = 'Токен подтверждения отсутствует.';
} else {
    if ($user->confirmEmailToken($token)) {
        $success = true;
    } else {
        $error = 'Неверный или просроченный токен подтверждения. Запросите регистрацию заново.';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подтверждение email — ForumChat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background-color: #f0f2f5; min-height: 100vh; }
        .auth-wrapper { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; }
        .auth-card { width: 100%; max-width: 460px; border-radius: 16px; border: none; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
        .brand-icon { width: 48px; height: 48px; background: linear-gradient(135deg,#6366f1,#8b5cf6); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: #fff; }
        .brand-name { font-size: 1.4rem; font-weight: 700; color: #1e1e2e; letter-spacing: -.5px; }
        .brand-name span { color: #6366f1; }
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
                <?php if ($success): ?>
                    <div class="text-center py-3">
                        <div class="mb-3">
                            <div class="rounded-circle bg-success bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width:64px;height:64px">
                                <i class="bi bi-check-lg text-success fs-2"></i>
                            </div>
                        </div>
                        <h4 class="fw-bold mb-2">Email подтверждён</h4>
                        <p class="text-muted mb-4">Теперь вы можете войти в аккаунт.</p>
                        <a href="login.php" class="btn btn-primary-custom btn-primary w-100">Перейти на страницу входа</a>
                    </div>
                <?php else: ?>
                    <h4 class="fw-bold mb-2">Ошибка подтверждения</h4>
                    <p class="text-muted mb-4"><?= htmlspecialchars($error) ?></p>
                    <div class="d-grid gap-2">
                        <a href="register.php" class="btn btn-outline-primary">Зарегистрироваться заново</a>
                        <a href="login.php" class="btn btn-primary-custom btn-primary">Вернуться на вход</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
