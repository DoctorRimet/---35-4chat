<?php
session_start();
require_once '../config/database.php';
require_once '../classes/User.php';

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
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg: #0a0a0f;
    --surface: #111118;
    --surface2: #18181f;
    --border: #252530;
    --accent: #7c6aff;
    --accent2: #a855f7;
    --accent-glow: rgba(124,106,255,0.25);
    --text: #f0f0f5;
    --text-muted: #6b6b80;
    --text-dim: #9090a8;
    --success: #22c55e;
    --error: #ef4444;
}

body {
    background: var(--bg);
    color: var(--text);
    font-family: 'DM Sans', sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
}

.bg-orbs {
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 0;
}
.orb {
    position: absolute;
    border-radius: 50%;
    filter: blur(120px);
    opacity: 0.12;
    animation: drift 20s ease-in-out infinite;
}
.orb-1 { width: 600px; height: 600px; background: var(--accent); top: -200px; left: -200px; animation-delay: 0s; }
.orb-2 { width: 500px; height: 500px; background: var(--accent2); bottom: -200px; right: -100px; animation-delay: -7s; }
.orb-3 { width: 300px; height: 300px; background: #06b6d4; top: 50%; left: 50%; animation-delay: -14s; }
@keyframes drift {
    0%, 100% { transform: translate(0, 0) scale(1); }
    33% { transform: translate(30px, -30px) scale(1.05); }
    66% { transform: translate(-20px, 20px) scale(0.95); }
}

.noise {
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 1;
    opacity: 0.025;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
    background-size: 200px;
}

.container {
    position: relative;
    z-index: 10;
    width: 100%;
    max-width: 440px;
    padding: 20px;
    animation: slideUp 0.6s cubic-bezier(0.16,1,0.3,1) both;
}
@keyframes slideUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}

.logo {
    text-align: center;
    margin-bottom: 32px;
}
.logo-mark {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
}
.logo-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}
.logo-text {
    font-family: 'Syne', sans-serif;
    font-size: 22px;
    font-weight: 800;
    color: var(--text);
    letter-spacing: -0.5px;
}
.logo-text span { color: var(--accent); }

.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 36px;
    position: relative;
    overflow: hidden;
}
.card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 1px;
    background: linear-gradient(90deg, transparent, var(--accent), transparent);
    opacity: 0.6;
}

.card-title {
    font-family: 'Syne', sans-serif;
    font-size: 26px;
    font-weight: 700;
    margin-bottom: 6px;
    letter-spacing: -0.5px;
}
.card-sub {
    color: var(--text-muted);
    font-size: 14px;
    margin-bottom: 28px;
}

.alert {
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 20px;
    font-size: 14px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    animation: fadeIn 0.3s ease;
}
@keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }

.alert-error {
    background: rgba(239,68,68,0.08);
    border: 1px solid rgba(239,68,68,0.25);
    color: #fca5a5;
}
.alert-success {
    background: rgba(34,197,94,0.08);
    border: 1px solid rgba(34,197,94,0.25);
    color: #86efac;
}
.alert-icon { font-size: 16px; flex-shrink: 0; margin-top: 1px; }
.alert ul { list-style: none; }
.alert ul li::before { content: '· '; }

.form-group {
    margin-bottom: 18px;
}
.form-label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-dim);
    margin-bottom: 7px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.input-wrap {
    position: relative;
}
.input-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    font-size: 16px;
    pointer-events: none;
    transition: color 0.2s;
}
.form-control {
    width: 100%;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 13px 14px 13px 42px;
    color: var(--text);
    font-size: 15px;
    font-family: 'DM Sans', sans-serif;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.form-control:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-glow);
}
.form-control:focus + .input-icon,
.input-wrap:focus-within .input-icon { color: var(--accent); }
.form-control::placeholder { color: var(--text-muted); }

.hint {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 5px;
}

.pass-toggle {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    font-size: 16px;
    padding: 0;
    transition: color 0.2s;
}
.pass-toggle:hover { color: var(--text); }
.form-control.has-toggle { padding-right: 42px; }

.strength-bar {
    height: 3px;
    background: var(--border);
    border-radius: 2px;
    margin-top: 8px;
    overflow: hidden;
}
.strength-fill {
    height: 100%;
    border-radius: 2px;
    transition: width 0.3s ease, background 0.3s ease;
    width: 0%;
}

.btn-primary {
    width: 100%;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border: none;
    border-radius: 12px;
    padding: 14px;
    color: #fff;
    font-size: 15px;
    font-weight: 600;
    font-family: 'Syne', sans-serif;
    cursor: pointer;
    transition: opacity 0.2s, transform 0.1s, box-shadow 0.2s;
    letter-spacing: 0.3px;
    margin-top: 8px;
    position: relative;
    overflow: hidden;
}
.btn-primary:hover {
    opacity: 0.92;
    box-shadow: 0 8px 30px var(--accent-glow);
}
.btn-primary:active { transform: scale(0.99); }
.btn-primary::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, transparent 30%, rgba(255,255,255,0.15) 50%, transparent 70%);
    transform: translateX(-100%);
    transition: transform 0.5s ease;
}
.btn-primary:hover::after { transform: translateX(100%); }

.divider {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 22px 0;
    color: var(--text-muted);
    font-size: 13px;
}
.divider::before, .divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
}

.btn-ghost {
    width: 100%;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 13px;
    color: var(--text-dim);
    font-size: 14px;
    font-family: 'DM Sans', sans-serif;
    cursor: pointer;
    transition: background 0.2s, border-color 0.2s, color 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.btn-ghost:hover {
    background: var(--border);
    color: var(--text);
}

.footer-link {
    text-align: center;
    margin-top: 22px;
    font-size: 14px;
    color: var(--text-muted);
}
.footer-link a {
    color: var(--accent);
    text-decoration: none;
    font-weight: 500;
    transition: opacity 0.2s;
}
.footer-link a:hover { opacity: 0.8; }

.success-content {
    text-align: center;
    padding: 10px 0;
}
.success-icon {
    width: 64px;
    height: 64px;
    background: rgba(34,197,94,0.12);
    border: 2px solid rgba(34,197,94,0.3);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    margin: 0 auto 20px;
    animation: popIn 0.5s cubic-bezier(0.16,1,0.3,1);
}
@keyframes popIn {
    from { transform: scale(0.5); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
}
.success-title {
    font-family: 'Syne', sans-serif;
    font-size: 22px;
    font-weight: 700;
    margin-bottom: 8px;
}
.success-sub {
    color: var(--text-muted);
    font-size: 14px;
    margin-bottom: 24px;
    line-height: 1.6;
}
</style>
</head>
<body>
<div class="bg-orbs">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
</div>
<div class="noise"></div>

<div class="container">
    <div class="logo">
        <a class="logo-mark" href="#">
            <div class="logo-icon">💬</div>
            <div class="logo-text">Forum<span>Chat</span></div>
        </a>
    </div>

    <div class="card">
        <?php if ($success): ?>
        <div class="success-content">
            <div class="success-icon">✓</div>
            <div class="success-title">Аккаунт создан!</div>
            <p class="success-sub">
                Добро пожаловать, <strong><?= htmlspecialchars($_POST['username']) ?></strong>!<br>
                Ваш аккаунт успешно зарегистрирован.
            </p>
            <a href="login.php" class="btn-primary" style="display:block;text-decoration:none;text-align:center;padding:14px;">
                Войти в аккаунт →
            </a>
        </div>
        <?php else: ?>

        <h1 class="card-title">Создать аккаунт</h1>
        <p class="card-sub">Присоединяйтесь к сообществу</p>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <span class="alert-icon">⚠</span>
            <ul>
                <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" action="" novalidate>
            <div class="form-group">
                <label class="form-label" for="username">Никнейм</label>
                <div class="input-wrap">
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="form-control"
                        placeholder="your_nickname"
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                        maxlength="30"
                        autocomplete="username"
                        required
                    >
                    <span class="input-icon">@</span>
                </div>
                <p class="hint">Максимум 30 символов, только буквы, цифры и _</p>
            </div>

            <div class="form-group">
                <label class="form-label" for="email">Email</label>
                <div class="input-wrap">
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-control"
                        placeholder="you@example.com"
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        autocomplete="email"
                        required
                    >
                    <span class="input-icon">✉</span>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Пароль</label>
                <div class="input-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control has-toggle"
                        placeholder="Минимум 8 символов"
                        autocomplete="new-password"
                        required
                        oninput="checkStrength(this.value)"
                    >
                    <span class="input-icon">🔒</span>
                    <button type="button" class="pass-toggle" onclick="togglePass('password', this)">👁</button>
                </div>
                <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
            </div>

            <div class="form-group">
                <label class="form-label" for="password_confirm">Подтвердите пароль</label>
                <div class="input-wrap">
                    <input
                        type="password"
                        id="password_confirm"
                        name="password_confirm"
                        class="form-control has-toggle"
                        placeholder="Повторите пароль"
                        autocomplete="new-password"
                        required
                    >
                    <span class="input-icon">🔒</span>
                    <button type="button" class="pass-toggle" onclick="togglePass('password_confirm', this)">👁</button>
                </div>
            </div>

            <button type="submit" class="btn-primary">Зарегистрироваться</button>
        </form>

        <div class="divider">или</div>

        <a href="login.php?guest=1" class="btn-ghost">👤 Войти анонимно</a>

        <p class="footer-link">
            Уже есть аккаунт? <a href="login.php">Войти</a>
        </p>

        <?php endif; ?>
    </div>
</div>

<script>
function togglePass(id, btn) {
    const el = document.getElementById(id);
    if (el.type === 'password') {
        el.type = 'text';
        btn.textContent = '🙈';
    } else {
        el.type = 'password';
        btn.textContent = '👁';
    }
}

function checkStrength(val) {
    const fill = document.getElementById('strength-fill');
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^a-zA-Z0-9]/.test(val)) score++;
    const colors = ['#ef4444', '#f97316', '#eab308', '#22c55e'];
    const widths = ['25%', '50%', '75%', '100%'];
    if (val.length === 0) { fill.style.width = '0'; return; }
    fill.style.width = widths[score - 1] || '15%';
    fill.style.background = colors[score - 1] || '#ef4444';
}
</script>
</body>
</html>