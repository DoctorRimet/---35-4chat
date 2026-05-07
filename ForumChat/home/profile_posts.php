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
$userRole = $_SESSION['user_role'] ?? $user->getPrimaryRole($userId);
$username = htmlspecialchars($userInfo['username'] ?? '');

$postsStmt = $conn->prepare(
    'SELECT p.id, p.content, p.created_at, p.topic_id, t.title AS topic_title
     FROM posts p
     LEFT JOIN topics t ON t.id = p.topic_id
     WHERE p.author_id = :user_id AND p.deleted = 0
     ORDER BY p.created_at DESC'
);
$postsStmt->bindParam(':user_id', $userId);
$postsStmt->execute();
$userPosts = $postsStmt->fetchAll(PDO::FETCH_ASSOC);
$postCount = count($userPosts);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Мои посты — <?= $username ?> | ForumChat</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body { background-color: #f0f2f5; }
.navbar-brand-icon { width: 36px; height: 36px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; }
.navbar { box-shadow: 0 1px 8px rgba(0,0,0,.06); }
.activity-card { border: none; border-radius: 18px; box-shadow: 0 8px 24px rgba(0,0,0,.08); }
.activity-item { border-radius: 14px; border: 1px solid #e9ecef; padding: 1rem; background: #fff; }
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
                <li class="nav-item"><a class="nav-link" href="../index.php"><i class="bi bi-house me-1"></i>Главная</a></li>
                <li class="nav-item"><a class="nav-link" href="profile.php"><i class="bi bi-person me-1"></i>Профиль</a></li>
                <li class="nav-item"><a class="nav-link" href="../create.php"><i class="bi bi-plus-circle me-1"></i>Создать тему</a></li>
            </ul>
            <div class="d-flex align-items-center gap-2">
                <a href="../auth/logout.php" class="btn btn-sm btn-outline-danger rounded-3">
                    <i class="bi bi-box-arrow-right me-1"></i>Выйти
                </a>
            </div>
        </div>
    </div>
</nav>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card activity-card p-4 mb-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div>
                        <h4 class="mb-1">Все посты</h4>
                        <p class="text-muted small mb-0">Показано <?= $postCount ?> публикаций пользователя <?= $username ?>.</p>
                    </div>
                    <a href="profile.php" class="btn btn-sm btn-outline-primary">Назад в профиль</a>
                </div>

                <?php if (empty($userPosts)) : ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-chat-left-text display-4 mb-3"></i>
                    <p class="mb-0">У вас пока нет опубликованных постов.</p>
                </div>
                <?php else : ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($userPosts as $postItem) : ?>
                    <div class="activity-item">
                        <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
                            <div class="min-w-0">
                                <h5 class="mb-1"><?= htmlspecialchars($postItem['topic_title'] ?: 'Без темы') ?></h5>
                                <small class="text-muted d-block mb-2">Опубликовано <?= date('d.m.Y H:i', strtotime($postItem['created_at'])) ?></small>
                                <p class="mb-0 text-muted"><?= nl2br(htmlspecialchars(mb_substr($postItem['content'], 0, 240))) ?></p>
                            </div>
                            <a href="../topic.php?id=<?= $postItem['topic_id'] ?>#post-<?= $postItem['id'] ?>" class="btn btn-sm btn-outline-primary rounded-3">Перейти</a>
                        </div>
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
