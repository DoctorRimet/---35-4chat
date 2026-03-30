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
$userInfo = $user->getById($userId);
$profile = null;
$avatarUrl = '';
$fullName = '';
$joinedAt = '';
$userRole = $_SESSION['user_role'] ?? $user->getPrimaryRole($userId);

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

$postsStmt = $conn->prepare(
    'SELECT p.id, p.content, p.created_at, t.title AS topic_title
     FROM posts p
     LEFT JOIN topics t ON t.id = p.topic_id
     WHERE p.author_id = :user_id AND p.deleted = 0
     ORDER BY p.created_at DESC'
);
$postsStmt->bindParam(':user_id', $userId);
$postsStmt->execute();
$userPosts = $postsStmt->fetchAll(PDO::FETCH_ASSOC);

$commentsStmt = $comment->getByUserId($userId);
$userComments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);
$commentCount = count($userComments);

$postCount = count($userPosts);
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

            <div class="mb-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="mb-0">Посты</h5>
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
                    <div class="post-summary">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div>
                                <h6 class="mb-1 text-dark"><?= htmlspecialchars($postItem['topic_title'] ?: 'Без темы') ?></h6>
                                <small class="text-muted"><?= date('d.m.Y H:i', strtotime($postItem['created_at'])) ?></small>
                            </div>
                            <span class="badge bg-secondary bg-opacity-10 text-secondary">Пост</span>
                        </div>
                        <p class="mb-0 text-muted"><?= nl2br(htmlspecialchars(mb_substr($postItem['content'], 0, 260))) ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
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
                            <a href="../topic.php?id=<?= $entry['topic_id'] ?>" class="btn btn-sm btn-outline-primary rounded-3">Перейти</a>
                        </div>
                        <p class="mb-0 text-muted"><?= nl2br(htmlspecialchars(mb_substr($entry['content'], 0, 260))) ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
