<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';

$db = new Database();
$conn = $db->getConnection();
$user = new User($conn);

$errors = [];
$success = false;

if (isset($_GET['guest'])) {
    $_SESSION['guest'] = true;
    $_SESSION['user_id'] = 0;
    $_SESSION['username'] = 'Гость_' . rand(1000, 9999);
    header('Location: ../home/index.php');
    exit;
}

if (isset($_GET['logout']) && isset($_COOKIE['auth_token'])) {
    $user->deleteSession($_COOKIE['auth_token']);
    setcookie('auth_token', '', time() - 3600, '/');
    session_destroy();
    header('Location: login.php?loggedout=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $errors[] = 'Введите email и пароль';
    } else {
        $userData = $user->getByEmail($email);

        if (!$userData) {
            $errors[] = 'Неверный email или пароль';
        } elseif ($user->isLocked($userData)) {
            $mins = $user->getLockRemainingMinutes($userData);
            $errors[] = "Аккаунт заблокирован. Попробуйте через {$mins} мин.";
        } elseif ($userData['status'] === 'blocked') {
            $errors[] = 'Ваш аккаунт заблокирован администратором';
        } elseif (!password_verify($password, $userData['password_hash'])) {
            $user->incrementFailedAttempts($userData['id']);
            $attemptsLeft = User::MAX_FAILED_ATTEMPTS - ($userData['failed_attempts'] + 1);
            if ($attemptsLeft <= 0) {
                $errors[] = 'Аккаунт заблокирован на ' . User::LOCK_DURATION_MINUTES . ' минут';
            } else {
                $errors[] = "Неверный пароль. Осталось попыток: {$attemptsLeft}";
            }
        } else {
            if ($user->countActiveSessions($userData['id']) >= User::MAX_SESSIONS) {
                $errors[] = 'Превышено максимальное количество активных сессий';
            } else {
                $user->resetFailedAttempts($userData['id']);
                $token = $user->createSession($userData['id']);
                setcookie('auth_token', $token, time() + 30 * 24 * 3600, '/', '', false, true);
                $_SESSION['user_id'] = $userData['id'];
                $_SESSION['username'] = $userData['username'];
                $success = true;
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
<title>Вход — ForumChat</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body {
    background-color: #f0f2f5;
    min-height: 100vh;
}
.auth-wrapper {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1rem;
}
.auth-card {
    width: 100%;
    max-width: 440px;
    border-radius: 16px;
    border: none;
    box-shadow: 0 4px 24px rgba(0,0,0,.08);
}
.brand-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #fff;
}
.brand-name {
    font-size: 1.4rem;
    font-weight: 700;
    color: #1e1e2e;
    letter-spacing: -.5px;
}
.brand-name span { color: #6366f1; }
.form-control {
    border-radius: 10px;
    padding: .65rem 1rem;
    border-color: #dee2e6;
    font-size: .95rem;
}
.form-control:focus {
    border-color: #6366f1;
    box-shadow: 0 0 0 .2rem rgba(99,102,241,.15);
}
.input-group .form-control { border-radius: 10px 0 0 10px; }
.input-group .btn-outline-secondary {
    border-color: #dee2e6;
    border-radius: 0 10px 10px 0;
    color: #6c757d;
}
.input-group .btn-outline-secondary:hover { background: #f8f9fa; }
.btn-primary-custom {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    border: none;
    border-radius: 10px;
    padding: .7rem;
    font-weight: 600;
    font-size: .95rem;
    transition: opacity .2s, box-shadow .2s;
}
.btn-primary-custom:hover {
    opacity: .9;
    box-shadow: 0 6px 20px rgba(99,102,241,.35);
}
.divider {
    display: flex;
    align-items: center;
    gap: .75rem;
    color: #adb5bd;
    font-size: .85rem;
}
.divider::before, .divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #e9ecef;
}
</style>
</head>
<body>
<div class="auth-wrapper">
    <div class="w-100" style="max-width:440px">

        <div class="d-flex align-items-center justify-content-center gap-2 mb-4">
            <div class="brand-icon">💬</div>
            <div class="brand-name">Forum<span>Chat</span></div>
        </div>

        <div class="card auth-card">
            <div class="card-body p-4">

                <?php if ($success): ?>
                <div class="text-center py-3">
                    <div class="mb-3">
                        <span class="display-4 text-success">✓</span>
                    </div>
                    <h4 class="fw-bold mb-2">Добро пожаловать!</h4>
                    <p class="text-muted mb-4">Вы вошли как <strong><?= htmlspecialchars($_SESSION['username']) ?></strong></p>
                    <a href="../home/index.php" class="btn btn-primary-custom btn-primary w-100">Перейти на форум →</a>
                </div>

                <?php else: ?>

                <h4 class="fw-bold mb-1">Добро пожаловать</h4>
                <p class="text-muted small mb-4">Войдите в свой аккаунт</p>

                <?php if (isset($_GET['loggedout'])): ?>
                <div class="alert alert-info d-flex align-items-center gap-2 py-2">
                    <i class="bi bi-info-circle"></i>
                    <span>Вы вышли из аккаунта</span>
                </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger d-flex align-items-start gap-2 py-2">
                    <i class="bi bi-exclamation-triangle mt-1"></i>
                    <div>
                        <?php foreach ($errors as $err): ?>
                        <div><?= htmlspecialchars($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <form method="POST" novalidate>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-uppercase text-secondary" for="email">Email</label>
                        <div class="input-group">
                            <span class="input-group-text border-end-0 bg-white">
                                <i class="bi bi-envelope text-secondary"></i>
                            </span>
                            <input type="email" id="email" name="email" class="form-control border-start-0 ps-0"
                                placeholder="you@example.com"
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                autocomplete="email" required>
                        </div>
                    </div>

                    <div class="mb-1">
                        <label class="form-label fw-semibold small text-uppercase text-secondary" for="password">Пароль</label>
                        <div class="input-group">
                            <span class="input-group-text border-end-0 bg-white">
                                <i class="bi bi-lock text-secondary"></i>
                            </span>
                            <input type="password" id="password" name="password"
                                class="form-control border-start-0 border-end-0 ps-0"
                                placeholder="Ваш пароль"
                                autocomplete="current-password" required>
                            <button type="button" class="btn btn-outline-secondary border-start-0"
                                onclick="togglePass('password',this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="text-end mb-3">
                        <a href="#" class="small text-decoration-none" style="color:#6366f1">Забыли пароль?</a>
                    </div>

                    <button type="submit" class="btn btn-primary-custom btn-primary w-100">Войти</button>
                </form>

                <div class="divider my-3">или</div>

                <a href="?guest=1" class="btn btn-outline-secondary w-100 rounded-3">
                    <i class="bi bi-person me-1"></i> Войти анонимно
                </a>

                <p class="text-center text-muted small mt-4 mb-0">
                    Нет аккаунта? <a href="register.php" class="text-decoration-none fw-semibold" style="color:#6366f1">Зарегистрироваться</a>
                </p>

                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePass(id, btn) {
    const el = document.getElementById(id);
    const icon = btn.querySelector('i');
    if (el.type === 'password') {
        el.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        el.type = 'password';
        icon.className = 'bi bi-eye';
    }
}
</script>
</body>
</html>