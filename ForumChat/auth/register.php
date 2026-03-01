<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';

$db = new Database();
$conn = $db->getConnection();
$user = new User($conn);

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if (empty($username)) {
        $errors[] = 'Никнейм обязателен';
    } elseif (strlen($username) > User::MAX_USERNAME_LENGTH) {
        $errors[] = 'Никнейм не может быть длиннее ' . User::MAX_USERNAME_LENGTH . ' символов';
    } elseif (!preg_match('/^[a-zA-Z0-9_а-яёА-ЯЁ]+$/u', $username)) {
        $errors[] = 'Никнейм содержит недопустимые символы';
    } elseif ($user->usernameExists($username)) {
        $errors[] = 'Этот никнейм уже занят';
    }

    if (empty($email)) {
        $errors[] = 'Email обязателен';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Некорректный email';
    } elseif ($user->emailExists($email)) {
        $errors[] = 'Этот email уже зарегистрирован';
    }

    if (strlen($password) < User::MIN_PASSWORD_LENGTH) {
        $errors[] = 'Пароль должен быть не менее ' . User::MIN_PASSWORD_LENGTH . ' символов';
    }

    if ($password !== $password_confirm) {
        $errors[] = 'Пароли не совпадают';
    }

    if (empty($errors)) {
        $user->username = $username;
        $user->email = $email;
        $user->password_hash = password_hash($password, PASSWORD_DEFAULT);

        if ($user->create()) {
            $success = true;
        } else {
            $errors[] = 'Ошибка при создании аккаунта. Попробуйте снова.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Регистрация — ForumChat</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body { background-color: #f0f2f5; min-height: 100vh; }
.auth-wrapper {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1rem;
}
.auth-card {
    width: 100%;
    max-width: 460px;
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
.brand-name { font-size: 1.4rem; font-weight: 700; color: #1e1e2e; letter-spacing: -.5px; }
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
.input-group .form-control { border-radius: 0; }
.input-group .input-group-text { border-color: #dee2e6; }
.input-group:not(.has-toggle) .form-control { border-radius: 0 10px 10px 0; }
.input-group .form-control:last-child { border-radius: 0 10px 10px 0; }
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
.strength-bar {
    height: 4px;
    background: #e9ecef;
    border-radius: 2px;
    overflow: hidden;
    margin-top: 6px;
}
.strength-fill {
    height: 100%;
    border-radius: 2px;
    transition: width .3s, background .3s;
    width: 0;
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
                    <h4 class="fw-bold mb-2">Аккаунт создан!</h4>
                    <p class="text-muted mb-4">
                        Добро пожаловать, <strong><?= htmlspecialchars($_POST['username']) ?></strong>!<br>
                        Ваш аккаунт успешно зарегистрирован.
                    </p>
                    <a href="login.php" class="btn btn-primary-custom btn-primary w-100">Войти в аккаунт →</a>
                </div>

                <?php else: ?>

                <h4 class="fw-bold mb-1">Создать аккаунт</h4>
                <p class="text-muted small mb-4">Присоединяйтесь к сообществу</p>

                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger d-flex align-items-start gap-2 py-2">
                    <i class="bi bi-exclamation-triangle mt-1 flex-shrink-0"></i>
                    <ul class="mb-0 ps-0" style="list-style:none">
                        <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <form method="POST" novalidate>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-uppercase text-secondary" for="username">Никнейм</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0">
                                <i class="bi bi-at text-secondary"></i>
                            </span>
                            <input type="text" id="username" name="username"
                                class="form-control border-start-0"
                                placeholder="your_nickname"
                                value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                                maxlength="30" autocomplete="username" required>
                        </div>
                        <div class="form-text">Максимум 30 символов, только буквы, цифры и _</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-uppercase text-secondary" for="email">Email</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0">
                                <i class="bi bi-envelope text-secondary"></i>
                            </span>
                            <input type="email" id="email" name="email"
                                class="form-control border-start-0"
                                placeholder="you@example.com"
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                autocomplete="email" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-uppercase text-secondary" for="password">Пароль</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0">
                                <i class="bi bi-lock text-secondary"></i>
                            </span>
                            <input type="password" id="password" name="password"
                                class="form-control border-start-0 border-end-0"
                                placeholder="Минимум 8 символов"
                                autocomplete="new-password" required
                                oninput="checkStrength(this.value)">
                            <button type="button" class="btn btn-outline-secondary border-start-0"
                                onclick="togglePass('password',this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="strength-bar">
                            <div class="strength-fill" id="strength-fill"></div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold small text-uppercase text-secondary" for="password_confirm">Подтвердите пароль</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0">
                                <i class="bi bi-lock text-secondary"></i>
                            </span>
                            <input type="password" id="password_confirm" name="password_confirm"
                                class="form-control border-start-0 border-end-0"
                                placeholder="Повторите пароль"
                                autocomplete="new-password" required>
                            <button type="button" class="btn btn-outline-secondary border-start-0"
                                onclick="togglePass('password_confirm',this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary-custom btn-primary w-100">Зарегистрироваться</button>
                </form>

                <div class="divider my-3">или</div>

                <a href="login.php?guest=1" class="btn btn-outline-secondary w-100 rounded-3">
                    <i class="bi bi-person me-1"></i> Войти анонимно
                </a>

                <p class="text-center text-muted small mt-4 mb-0">
                    Уже есть аккаунт? <a href="login.php" class="text-decoration-none fw-semibold" style="color:#6366f1">Войти</a>
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

function checkStrength(val) {
    const fill = document.getElementById('strength-fill');
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^a-zA-Z0-9]/.test(val)) score++;
    const colors = ['#ef4444','#f97316','#eab308','#22c55e'];
    const widths = ['25%','50%','75%','100%'];
    if (val.length === 0) { fill.style.width = '0'; return; }
    fill.style.width = widths[score - 1] || '15%';
    fill.style.background = colors[score - 1] || '#ef4444';
}
</script>
</body>
</html>
