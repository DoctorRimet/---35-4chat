<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Topic.php';
require_once __DIR__ . '/../classes/Post.php';
require_once __DIR__ . '/../classes/User.php';

$db = new Database();
$conn = $db->getConnection();
$topic = new Topic($conn);
$post = new Post($conn);
$user = new User($conn);

$topics_stmt = $topic->getAll();
$topics = $topics_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_topics = count($topics);

$posts_stmt = $post->getAll();
$all_posts = $posts_stmt->fetchAll(PDO::FETCH_ASSOC);
$total_posts = count($all_posts);

$users_stmt = $user->getAll();
$total_users = $users_stmt->rowCount();

$post_counts = [];
foreach ($all_posts as $p) {
    $post_counts[$p['topic_id']] = ($post_counts[$p['topic_id']] ?? 0) + 1;
}

$username = htmlspecialchars($_SESSION['username'] ?? 'Гость');
$is_guest = !isset($_SESSION['user_id']) || $_SESSION['user_id'] == 0;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ForumChat — Главная</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body { background-color: #f0f2f5; }
.navbar-brand-icon { width: 36px; height: 36px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; }
.navbar { box-shadow: 0 1px 8px rgba(0,0,0,.06); }
.stat-card { border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,.06); transition: transform .2s; }
.stat-card:hover { transform: translateY(-2px); }
.stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; }
.topic-card { border: none; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,.05); transition: box-shadow .2s, transform .2s; cursor: pointer; }
.topic-card:hover { box-shadow: 0 6px 20px rgba(99,102,241,.15); transform: translateY(-2px); }
.topic-card .card-title a { color: #1e1e2e; text-decoration: none; font-weight: 600; }
.topic-card .card-title a:hover { color: #6366f1; }
.badge-status { font-size: .7rem; padding: .3em .65em; border-radius: 6px; }
.avatar-sm { width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #8b5cf6); display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: .75rem; font-weight: 700; flex-shrink: 0; }
.btn-create { background: linear-gradient(135deg, #6366f1, #8b5cf6); border: none; border-radius: 10px; font-weight: 600; transition: opacity .2s, box-shadow .2s; }
.btn-create:hover { opacity: .9; box-shadow: 0 6px 20px rgba(99,102,241,.35); }
.section-title { font-size: 1.1rem; font-weight: 700; color: #1e1e2e; border-left: 3px solid #6366f1; padding-left: .6rem; }
.empty-state { padding: 3rem 1rem; text-align: center; color: #adb5bd; }
.search-bar { background: #fff; border-bottom: 1px solid #e9ecef; padding: .5rem 0; }
</style>
</head>
<body>

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
                    <a class="nav-link active fw-semibold" href="index.php">
                        <i class="bi bi-house me-1"></i>Главная
                    </a>
                </li>
                <?php if (!$is_guest): ?>
                <li class="nav-item">
                    <a class="nav-link" href="create.php">
                        <i class="bi bi-plus-circle me-1"></i>Создать тему
                    </a>
                </li>
                <?php endif; ?>
            </ul>

            <div class="d-flex align-items-center gap-2">
                <div class="avatar-sm"><?= mb_strtoupper(mb_substr($username, 0, 1)) ?></div>
                <span class="fw-semibold small"><?= $username ?></span>
                <?php if ($is_guest): ?>
                    <span class="badge bg-secondary">Гость</span>
                    <a href="../auth/login.php" class="btn btn-sm btn-outline-primary rounded-3">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Войти
                    </a>
                <?php else: ?>
                    <span class="badge bg-success bg-opacity-10 text-success">Участник</span>
                    <a href="../auth/logout.php" class="btn btn-sm btn-outline-danger rounded-3">
                        <i class="bi bi-box-arrow-right me-1"></i>Выйти
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<div class="search-bar">
    <div class="container">
        <form action="search.php" method="GET" class="d-flex gap-2">
            <div class="input-group" style="max-width:480px">
                <span class="input-group-text bg-white border-end-0">
                    <i class="bi bi-search text-secondary"></i>
                </span>
                <input type="text" name="q" class="form-control border-start-0 ps-0"
                    placeholder="Поиск по темам..."
                    value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
            </div>
            <button type="submit" class="btn btn-sm px-3 rounded-3 fw-semibold text-white"
                style="background:linear-gradient(135deg,#6366f1,#8b5cf6);border:none">
                Найти
            </button>
        </form>
    </div>
</div>

<div class="container py-4">

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card stat-card p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-primary bg-opacity-10"><i class="bi bi-chat-dots text-primary"></i></div>
                    <div><div class="fs-4 fw-bold lh-1"><?= $total_topics ?></div><div class="text-muted small">Тем</div></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stat-card p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-success bg-opacity-10"><i class="bi bi-chat-text text-success"></i></div>
                    <div><div class="fs-4 fw-bold lh-1"><?= $total_posts ?></div><div class="text-muted small">Постов</div></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stat-card p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-warning bg-opacity-10"><i class="bi bi-people text-warning"></i></div>
                    <div><div class="fs-4 fw-bold lh-1"><?= $total_users ?></div><div class="text-muted small">Участников</div></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stat-card p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-info bg-opacity-10"><i class="bi bi-circle-fill text-info" style="font-size:10px"></i></div>
                    <div><div class="fs-4 fw-bold lh-1 text-success">●</div><div class="text-muted small">Онлайн</div></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="section-title">Темы форума</div>
                <?php if (!$is_guest): ?>
                <a href="create.php" class="btn btn-create btn-primary btn-sm px-3">
                    <i class="bi bi-plus-lg me-1"></i>Новая тема
                </a>
                <?php endif; ?>
            </div>

            <?php if (empty($topics)): ?>
            <div class="card border-0 rounded-3 shadow-sm">
                <div class="empty-state">
                    <i class="bi bi-chat-square-dots display-4 d-block mb-3"></i>
                    <p class="mb-0">Тем пока нет. Будьте первым!</p>
                    <?php if (!$is_guest): ?>
                    <a href="create.php" class="btn btn-create btn-primary mt-3">Создать тему</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="d-flex flex-column gap-2">
                <?php foreach ($topics as $t): ?>
                <?php
                $post_count = $post_counts[$t['id']] ?? 0;
                $author = $user->getById($t['author_id']);
                $author_name = $author ? htmlspecialchars($author['username']) : 'Неизвестно';
                $author_initial = mb_strtoupper(mb_substr($author_name, 0, 1));
                $status_map = ['open' => ['Открыта','success'], 'closed' => ['Закрыта','danger'], 'archived' => ['Архив','secondary']];
                [$status_label, $status_color] = $status_map[$t['status']] ?? ['Открыта','success'];
                $date = date('d.m.Y', strtotime($t['created_at']));
                ?>
                <div class="card topic-card" onclick="window.location='topic.php?id=<?= $t['id'] ?>'">
                    <div class="card-body py-3 px-3">
                        <div class="d-flex align-items-start gap-3">
                            <div class="avatar-sm mt-1"><?= $author_initial ?></div>
                            <div class="flex-grow-1 min-w-0">
                                <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                    <h6 class="card-title mb-0">
                                        <a href="topic.php?id=<?= $t['id'] ?>"><?= htmlspecialchars($t['title']) ?></a>
                                    </h6>
                                    <span class="badge badge-status bg-<?= $status_color ?>-subtle text-<?= $status_color ?>"><?= $status_label ?></span>
                                </div>
                                <div class="text-muted small d-flex align-items-center gap-3 flex-wrap">
                                    <span><i class="bi bi-person me-1"></i><?= $author_name ?></span>
                                    <span><i class="bi bi-calendar3 me-1"></i><?= $date ?></span>
                                    <span><i class="bi bi-chat me-1"></i><?= $post_count ?> <?= $post_count === 1 ? 'пост' : ($post_count < 5 ? 'поста' : 'постов') ?></span>
                                </div>
                            </div>
                            <i class="bi bi-chevron-right text-muted mt-2 d-none d-sm-block"></i>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 rounded-3 shadow-sm mb-3">
                <div class="card-body">
                    <div class="section-title mb-3">Мой профиль</div>
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div style="width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.2rem;font-weight:700">
                            <?= mb_strtoupper(mb_substr($username, 0, 1)) ?>
                        </div>
                        <div>
                            <div class="fw-bold"><?= $username ?></div>
                            <?php if ($is_guest): ?>
                            <span class="badge bg-secondary">Гость</span>
                            <?php else: ?>
                            <span class="badge bg-success bg-opacity-10 text-success">Участник</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($is_guest): ?>
                    <a href="../auth/login.php" class="btn btn-create btn-primary w-100 btn-sm">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Войти в аккаунт
                    </a>
                    <?php else: ?>
                    <a href="../auth/logout.php" class="btn btn-outline-danger w-100 btn-sm rounded-3">
                        <i class="bi bi-box-arrow-right me-1"></i>Выйти
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$is_guest): ?>
            <div class="card border-0 rounded-3 shadow-sm">
                <div class="card-body">
                    <div class="section-title mb-3">Быстрые действия</div>
                    <div class="d-grid gap-2">
                        <a href="create.php" class="btn btn-create btn-primary btn-sm">
                            <i class="bi bi-plus-lg me-1"></i>Создать тему
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
