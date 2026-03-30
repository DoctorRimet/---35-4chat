<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Post.php';
require_once __DIR__ . '/../classes/Comment.php';
require_once __DIR__ . '/../classes/User.php';

$db = new Database();
$conn = $db->getConnection();
$user = new User($conn);
$post = new Post($conn);
$comment = new Comment($conn);

$userId = $_SESSION['user_id'];
$is_guest = empty($userId);
$userInfo = $user->getById($userId);
$profile = null;
$avatarUrl = '';
$fullName = '';
$joinedAt = '';
$userRole = $_SESSION['user_role'] ?? $user->getPrimaryRole($userId);

$errors = [];
$success = '';
$usernameInput = $userInfo['username'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameInput = trim($_POST['username'] ?? $userInfo['username'] ?? '');
    $email = trim($_POST['email'] ?? $userInfo['email'] ?? '');
    $avatarUrl = trim($_POST['avatar_url'] ?? '');
    $currentPassword = trim($_POST['current_password'] ?? '');
    $newPassword = trim($_POST['new_password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    if ($usernameInput === '') {
        $errors[] = 'Никнейм не может быть пустым.';
    } elseif (mb_strlen($usernameInput) > User::MAX_USERNAME_LENGTH) {
        $errors[] = 'Никнейм должен быть не длиннее ' . User::MAX_USERNAME_LENGTH . ' символов.';
    } elseif ($usernameInput !== ($userInfo['username'] ?? '') && $user->isUsernameTakenByOther($usernameInput, $userId)) {
        $errors[] = 'Этот никнейм уже занят другим пользователем.';
    }

    if ($email === '') {
        $errors[] = 'Email не может быть пустым.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Укажите корректный email.';
    } elseif ($email !== ($userInfo['email'] ?? '') && $user->isEmailTakenByOther($email, $userId)) {
        $errors[] = 'Этот email уже используется другим пользователем.';
    }

    if ($avatarUrl !== '' && !filter_var($avatarUrl, FILTER_VALIDATE_URL)) {
        $errors[] = 'Аватар должен быть допустимой ссылкой.';
    }

    if ($newPassword !== '' || $confirmPassword !== '') {
        if ($currentPassword === '') {
            $errors[] = 'Для смены пароля введите текущий пароль.';
        } elseif (!$user->verifyPassword($currentPassword, $userInfo['password_hash'])) {
            $errors[] = 'Текущий пароль указан неверно.';
        }
        if (mb_strlen($newPassword) < User::MIN_PASSWORD_LENGTH) {
            $errors[] = 'Новый пароль должен содержать не менее ' . User::MIN_PASSWORD_LENGTH . ' символов.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'Новый пароль и подтверждение не совпадают.';
        }
    }

    if (empty($errors)) {
        if ($usernameInput !== ($userInfo['username'] ?? '')) {
            $user->updateUsername($userId, $usernameInput);
            $_SESSION['username'] = $usernameInput;
        }

        if ($email !== ($userInfo['email'] ?? '')) {
            $user->updateEmail($userId, $email);
        }

        if ($newPassword !== '') {
            $user->updatePassword($userId, $newPassword);
        }

        $user->updateProfile(
            $userId,
            $avatarUrl !== '' ? $avatarUrl : null,
            is_array($profile) ? $profile['bio'] : null,
            is_array($profile) ? $profile['first_name'] : null,
            is_array($profile) ? $profile['last_name'] : null
        );

        $success = 'Профиль успешно обновлён.';
        $userInfo = $user->getById($userId);
        $profile = $user->getProfile($userId);
        $avatarUrl = $profile['avatar_url'] ?? '';
    }
}

if ($userInfo) {
    $joinedAt = date('d.m.Y', strtotime($userInfo['created_at'] ?? 'now'));
    $profileStmt = $conn->prepare(
        'SELECT first_name, last_name, avatar_url, bio
         FROM user_profiles
         WHERE user_id = :user_id LIMIT 1'
    );
    $profileStmt->bindParam(':user_id', $userId);
    $profileStmt->execute();
    $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($profile['avatar_url'])) {
        $avatarUrl = $profile['avatar_url'];
    }
    $fullName = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')) ?: $userInfo['username'];
}

$postCountStmt = $conn->prepare(
    'SELECT COUNT(*) AS total FROM posts WHERE author_id = :user_id AND deleted = 0'
);
$postCountStmt->bindParam(':user_id', $userId);
$postCountStmt->execute();
$postCount = (int) ($postCountStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

$postsStmt = $conn->prepare(
    'SELECT p.id, p.content, p.created_at, t.title AS topic_title, t.id AS topic_id
     FROM posts p
     LEFT JOIN topics t ON t.id = p.topic_id
     WHERE p.author_id = :user_id AND p.deleted = 0
     ORDER BY p.created_at DESC
     LIMIT 3'
);
$postsStmt->bindParam(':user_id', $userId);
$postsStmt->execute();
$userPosts = $postsStmt->fetchAll(PDO::FETCH_ASSOC);

$commentCount = $comment->countByUserId($userId);
$userComments = $comment->getRecentByUserId($userId, 3)->fetchAll(PDO::FETCH_ASSOC);

$profileTitle = htmlspecialchars($userInfo['username'] ?? 'Пользователь');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Профиль — <?= $profileTitle ?> | ForumChat</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body { background-color: #f0f2f5; }
.navbar-brand-icon { width: 36px; height: 36px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; }
.navbar { box-shadow: 0 1px 8px rgba(0,0,0,.06); }
.profile-card, .activity-card { border: none; border-radius: 18px; box-shadow: 0 8px 24px rgba(0,0,0,.08); }
.profile-avatar { width: 96px; height: 96px; border-radius: 24px; background: linear-gradient(135deg, #6366f1, #8b5cf6); display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 36px; font-weight: 700; }
.avatar-image { width: 96px; height: 96px; border-radius: 24px; object-fit: cover; }
.badge-status { font-size: .75rem; padding: .4em .75em; border-radius: 10px; }
.activity-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.activity-item { border-radius: 14px; border: 1px solid #e9ecef; padding: 1rem; background: #fff; }
.activity-item small { color: #6c757d; }
.section-title { font-size: 1.1rem; font-weight: 700; color: #1e1e2e; border-left: 3px solid #6366f1; padding-left: .6rem; }
.post-summary { border-radius: 14px; border: 1px solid #e9ecef; padding: 1rem; background: #fff; transition: transform .2s; }
.post-summary:hover { transform: translateY(-1px); }
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light bg-white sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="../index.php">
            <div class="navbar-brand-icon">💬</div>
            <span>Forum<span style="color:#6366f1">Chat</span></span>
        </a>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link" href="../index.php">
                        <i class="bi bi-house me-1"></i>Главная
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active fw-semibold" href="profile.php">
                        <i class="bi bi-person me-1"></i>Профиль
                    </a>
                </li>
                <?php if (!$is_guest): ?>
                <li class="nav-item">
                    <a class="nav-link" href="../create.php">
                        <i class="bi bi-plus-circle me-1"></i>Создать тему
                    </a>
                </li>
                <?php endif; ?>
            </ul>
            <div class="d-flex align-items-center gap-2">
                <div class="profile-avatar">
                    <?= htmlspecialchars(mb_strtoupper(mb_substr($profileTitle, 0, 1))) ?>
                </div>
                <div class="d-flex flex-column">
                    <span class="fw-semibold small"><?= htmlspecialchars($profileTitle) ?></span>
                    <span class="text-muted small"><?= $userRole ?></span>
                </div>
                <a href="../auth/logout.php" class="btn btn-sm btn-outline-danger rounded-3">
                    <i class="bi bi-box-arrow-right me-1"></i>Выйти
                </a>
            </div>
        </div>
    </div>
</nav>

<div class="container py-4">
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card profile-card p-4">
                <div class="text-center mb-4">
                    <?php if ($avatarUrl): ?>
                        <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="Аватар пользователя" class="avatar-image mb-3">
                    <?php else: ?>
                        <div class="profile-avatar mb-3"><?= htmlspecialchars(mb_strtoupper(mb_substr($profileTitle, 0, 1))) ?></div>
                    <?php endif; ?>
                    <h4 class="fw-bold mb-1"><?= htmlspecialchars($profileTitle) ?></h4>
                    <p class="text-muted small mb-2">Регистрация: <?= htmlspecialchars($joinedAt) ?></p>
                    <span class="badge badge-status bg-primary bg-opacity-10 text-primary">Роль: <?= htmlspecialchars($userRole) ?></span>
                </div>
                <?php if (!empty($profile['bio'])): ?>
                <div class="mb-3">
                    <h6 class="fw-semibold">О себе</h6>
                    <p class="text-muted small mb-0"><?= nl2br(htmlspecialchars($profile['bio'])) ?></p>
                </div>
                <?php endif; ?>
                <div>
                    <h6 class="fw-semibold">Статистика</h6>
                    <ul class="list-unstyled mb-0">
                        <li class="mb-2"><strong><?= $postCount ?></strong> <?= $postCount === 1 ? 'пост' : 'постов' ?></li>
                        <li class="mb-0"><strong><?= $commentCount ?></strong> <?= $commentCount === 1 ? 'комментарий' : 'комментариев' ?></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="mb-4">
                <div class="section-title">История активности</div>
                <p class="text-muted small mb-0">Список ваших последних публикаций и общий обзор поведения на форуме.</p>
            </div>

            <div class="card activity-card p-4 mb-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div>
                        <h5 class="mb-1">Редактирование профиля</h5>
                        <small class="text-muted">Измените никнейм, email, аватар или пароль.</small>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-info bg-opacity-10 text-info">Только вы</span>
                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#profileEditBlock" aria-expanded="false" aria-controls="profileEditBlock">
                            Редактировать
                        </button>
                    </div>
                </div>
                <div class="collapse <?= empty($_POST) ? '' : 'show' ?>" id="profileEditBlock">
                    <?php if ($success): ?>
                    <div class="alert alert-success mb-3"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger mb-3">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <form method="post">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Никнейм</label>
                                <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($usernameInput) ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($userInfo['email'] ?? '') ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">URL аватара</label>
                                <input type="url" name="avatar_url" class="form-control" value="<?= htmlspecialchars($avatarUrl) ?>">
                                <div class="form-text">Ссылка на изображение, которое будет показано в профиле.</div>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Текущий пароль</label>
                                <input type="password" name="current_password" class="form-control" autocomplete="current-password">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Новый пароль</label>
                                <input type="password" name="new_password" class="form-control" autocomplete="new-password">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Подтверждение</label>
                                <input type="password" name="confirm_password" class="form-control" autocomplete="new-password">
                            </div>
                        </div>
                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">Сохранить изменения</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="mb-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div>
                        <h5 class="mb-0">Посты</h5>
                        <small class="text-muted">Последние публикации и быстрые переходы к теме.</small>
                    </div>
                    <span class="text-muted small">Всего: <?= $postCount ?></span>
                </div>

                <?php if (empty($userPosts)): ?>
                <div class="card activity-card p-4 text-center text-muted">
                    <i class="bi bi-chat-left-text display-4 mb-3"></i>
                    <p class="mb-0">У вас пока нет опубликованных постов.</p>
                </div>
                <?php else: ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($userPosts as $postItem): ?>
                    <div class="activity-item">
                        <div class="d-flex align-items-start justify-content-between gap-3">
                            <div class="min-w-0">
                                <h6 class="mb-1 text-dark"><?= htmlspecialchars($postItem['topic_title'] ?: 'Без темы') ?></h6>
                                <small class="text-muted d-block mb-2"><?= date('d.m.Y H:i', strtotime($postItem['created_at'])) ?></small>
                                <p class="mb-0 text-muted"><?= nl2br(htmlspecialchars(mb_substr($postItem['content'], 0, 180))) ?></p>
                            </div>
                            <a href="../topic.php?id=<?= $postItem['topic_id'] ?>#post-<?= $postItem['id'] ?>" class="btn btn-sm btn-outline-primary rounded-3 align-self-start">Перейти</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($postCount > 3): ?>
                <div class="mt-3 text-end">
                    <a href="profile_posts.php" class="btn btn-sm btn-outline-primary">Посмотреть все посты</a>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="card activity-card p-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div>
                        <h5 class="mb-1">Комментарии</h5>
                        <small class="text-muted">Последние комментарии, которые вы оставили.</small>
                    </div>
                    <span class="badge bg-secondary bg-opacity-10 text-secondary">Всего: <?= $commentCount ?></span>
                </div>
                <?php if (empty($userComments)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-chat-left-text display-4 mb-3"></i>
                    <p class="mb-0">Вы ещё не оставили комментариев.</p>
                </div>
                <?php else: ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($userComments as $entry): ?>
                    <div class="activity-item">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div>
                                <strong><?= htmlspecialchars($entry['topic_title']) ?></strong><br>
                                <small class="text-muted">Пост ID <?= $entry['post_id'] ?> · <?= date('d.m.Y H:i', strtotime($entry['created_at'])) ?></small>
                            </div>
                            <a href="../topic.php?id=<?= $entry['topic_id'] ?>#post-<?= $entry['post_id'] ?>" class="btn btn-sm btn-outline-primary rounded-3">Перейти</a>
                        </div>
                        <p class="mb-0 text-muted"><?= nl2br(htmlspecialchars(mb_substr($entry['content'], 0, 260))) ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($commentCount > 3): ?>
                <div class="mt-3 text-end">
                    <a href="profile_comments.php" class="btn btn-sm btn-outline-primary">Посмотреть все комментарии</a>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
