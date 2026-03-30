<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Topic.php';
require_once __DIR__ . '/classes/Post.php';
require_once __DIR__ . '/classes/Comment.php';
require_once __DIR__ . '/classes/User.php';

$db = new Database();
$conn = $db->getConnection();

$topicModel = new Topic($conn);
$postModel = new Post($conn);
$commentModel = new Comment($conn);
$userModel = new User($conn);

$currentUserId = $_SESSION['user_id'] ?? null;
$loggedIn = !empty($currentUserId);

$topicId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($topicId <= 0) {
    header('Location: index.php');
    exit;
}

$topic = $topicModel->getById($topicId);
if (!$topic) {
    header('Location: index.php');
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_text'], $_POST['post_id'])) {
    if (!$loggedIn) {
        $errors[] = 'Только авторизованные пользователи могут оставлять комментарии.';
    } else {
        $postId = (int) $_POST['post_id'];
        $content = trim($_POST['comment_text']);

        if ($postId <= 0 || mb_strlen($content) < 3) {
            $errors[] = 'Комментарий должен содержать минимум 3 символа.';
        } else {
            $commentModel->post_id = $postId;
            $commentModel->author_id = $currentUserId;
            $commentModel->content = $content;

            if ($commentModel->create()) {
                header('Location: topic.php?id=' . $topicId . '#post-' . $postId);
                exit;
            }
            $errors[] = 'Не удалось сохранить комментарий. Попробуйте ещё раз.';
        }
    }
}

$postsStmt = $postModel->getByTopicId($topicId);
$posts = $postsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($topic['title']) ?> — Тема</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        .post-card { border: 1px solid #dee2e6; border-radius: .75rem; padding: 1.25rem; }
        .comment-card { background: #f8f9fa; border-radius: .75rem; padding: 1rem; margin-top: .75rem; }
        .comment-form textarea { min-height: 120px; }
        .topic-badge { font-size: .85rem; }
    </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-light bg-white sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="index.php">
            <div class="navbar-brand-icon">💬</div>
            <span>Forum<span style="color:#6366f1">Chat</span></span>
        </a>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link" href="index.php">
                        <i class="bi bi-house me-1"></i>Главная
                    </a>
                </li>
                <?php if ($loggedIn): ?>
                <li class="nav-item">
                    <a class="nav-link" href="home/profile.php">
                        <i class="bi bi-person me-1"></i>Профиль
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="create.php">
                        <i class="bi bi-plus-circle me-1"></i>Создать тему
                    </a>
                </li>
                <?php endif; ?>
            </ul>
            <div class="d-flex align-items-center gap-2">
                <?php if ($loggedIn): ?>
                    <a href="auth/logout.php" class="btn btn-sm btn-outline-danger rounded-3">
                        <i class="bi bi-box-arrow-right me-1"></i>Выйти
                    </a>
                <?php else: ?>
                    <a href="auth/login.php" class="btn btn-sm btn-outline-primary rounded-3">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Войти
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<div class="container py-5">
    <div class="row">
        <div class="col-lg-9 mx-auto">
            <div class="card mb-4">
                <div class="card-body">
                    <h1 class="card-title mb-1"><?= htmlspecialchars($topic['title']) ?></h1>
                    <p class="text-muted mb-0">Тема ID <?= $topic['id'] ?> · Создана <?= htmlspecialchars($topic['created_at']) ?></p>
                    <p class="mt-3 mb-0"><?= nl2br(htmlspecialchars($topic['description'] ?? '')) ?></p>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (empty($posts)): ?>
                <div class="alert alert-warning">В этой теме пока нет сообщений.</div>
            <?php endif; ?>

            <?php foreach ($posts as $post): ?>
                <?php $comments = $commentModel->getByPostId($post['id'])->fetchAll(PDO::FETCH_ASSOC); ?>
                <div id="post-<?= $post['id'] ?>" class="post-card mb-4">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div>
                            <h5 class="mb-1">Сообщение #<?= $post['id'] ?></h5>
                            <small class="text-muted">Автор ID <?= $post['author_id'] ?> · <?= htmlspecialchars($post['created_at']) ?></small>
                        </div>
                        <span class="badge bg-secondary topic-badge">Комментариев: <?= count($comments) ?></span>
                    </div>
                    <p class="mb-4 text-body-secondary"><?= nl2br(htmlspecialchars($post['content'])) ?></p>

                    <?php if (!empty($comments)): ?>
                        <div class="mb-4">
                            <h6 class="mb-3">Комментарии</h6>
                            <?php foreach ($comments as $commentItem): ?>
                                <div class="comment-card">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <div>
                                            <strong><?= htmlspecialchars($commentItem['username']) ?></strong>
                                            <small class="text-muted">· <?= htmlspecialchars($commentItem['created_at']) ?></small>
                                        </div>
                                    </div>
                                    <div class="comment-content text-muted"><?= nl2br(htmlspecialchars($commentItem['content'])) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($loggedIn): ?>
                        <form method="post" class="comment-form">
                            <div class="mb-3">
                                <label for="comment_text_<?= $post['id'] ?>" class="form-label">Добавить комментарий</label>
                                <textarea id="comment_text_<?= $post['id'] ?>" name="comment_text" class="form-control" rows="3" required></textarea>
                            </div>
                            <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                            <button type="submit" class="btn btn-primary">Отправить</button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-secondary mb-0">Чтобы оставить комментарий, <a href="auth/login.php">войдите</a> или <a href="auth/register.php">зарегистрируйтесь</a>.</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
