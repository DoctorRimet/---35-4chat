<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Topic.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Category.php';

// Только для авторизованных пользователей
if ($is_guest) {
    header('Location: ../auth/login.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();
$topic = new Topic($conn);
$user = new User($conn);
$category = new Category($conn);

// Получить темы пользователя
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$itemsPerPage = 20;
$offset = ($page - 1) * $itemsPerPage;

$stmt = $topic->getByUserId($_SESSION['user_id']);
$all_user_topics = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_topics = count($all_user_topics);

// Пагинация
$user_topics = array_slice($all_user_topics, $offset, $itemsPerPage);
$totalPages = ceil($total_topics / $itemsPerPage);
$totalPages = max(1, $totalPages);

// Информация о текущем пользователе
$currentUserData = $user->getById($_SESSION['user_id']);
$currentUserProfile = $user->getProfile($_SESSION['user_id']);
$currentUserAvatar = $currentUserProfile['avatar_url'] ?? $currentUserData['avatar_url'] ?? '';
$username = htmlspecialchars($_SESSION['username'] ?? 'Пользователь');
$userRole = $_SESSION['user_role'] ?? 'user';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Мои темы — ForumChat</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body { background-color: #f0f2f5; }
.navbar-brand-icon { width: 36px; height: 36px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; }
.navbar { box-shadow: 0 1px 8px rgba(0,0,0,.06); }
.section-title { font-size: 1.1rem; font-weight: 700; color: #1e1e2e; border-left: 3px solid #6366f1; padding-left: .6rem; }
.topic-card { border: none; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,.05); transition: box-shadow .2s, transform .2s; cursor: pointer; }
.topic-card:hover { box-shadow: 0 6px 20px rgba(99,102,241,.15); transform: translateY(-2px); }
.badge-status { font-size: .7rem; padding: .3em .65em; border-radius: 6px; }
.avatar-sm { width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #8b5cf6); display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: .75rem; font-weight: 700; flex-shrink: 0; }
.empty-state { padding: 3rem 1rem; text-align: center; color: #adb5bd; }
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
                    <a class="nav-link" href="index.php">
                        <i class="bi bi-house me-1"></i>Главная
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active fw-semibold" href="my_topics.php">
                        <i class="bi bi-bookmark me-1"></i>Мои темы
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="my_posts.php">
                        <i class="bi bi-chat-dots me-1"></i>Мои ответы
                    </a>
                </li>
            </ul>
            <div class="d-flex align-items-center gap-2">
                <?php if ($currentUserAvatar): ?>
                    <img src="<?= htmlspecialchars($currentUserAvatar) ?>?t=<?= time() ?>" alt="<?= $username ?>" class="avatar-sm" style="object-fit:cover;">
                <?php else: ?>
                    <div class="avatar-sm"><?= mb_strtoupper(mb_substr($username, 0, 1)) ?></div>
                <?php endif; ?>
                <span class="fw-semibold small"><?= $username ?></span>
                <a href="../auth/logout.php" class="btn btn-sm btn-outline-danger rounded-3">
                    <i class="bi bi-box-arrow-right me-1"></i>Выйти
                </a>
            </div>
        </div>
    </div>
</nav>

<div class="container py-4">
    <div class="row">
        <div class="col-lg-8">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="section-title">
                    <i class="bi bi-bookmark me-1"></i>Мои темы (<?= $total_topics ?>)
                </div>
                <a href="../create.php" class="btn btn-primary btn-sm px-3">
                    <i class="bi bi-plus-lg me-1"></i>Новая тема
                </a>
            </div>

            <?php if (empty($user_topics)): ?>
            <div class="card border-0 rounded-3 shadow-sm">
                <div class="empty-state">
                    <i class="bi bi-bookmark display-4 d-block mb-3"></i>
                    <p class="mb-2">У вас пока нет тем</p>
                    <small class="text-muted">Создайте первую тему, чтобы начать обсуждение</small>
                    <br>
                    <a href="../create.php" class="btn btn-primary mt-3">Создать тему</a>
                </div>
            </div>
            <?php else: ?>
            <div class="d-flex flex-column gap-2">
                <?php foreach ($user_topics as $t): ?>
                <?php
                $post_count = 0;
                $status_map = ['open' => ['Открыта','success'], 'closed' => ['Закрыта','danger'], 'archived' => ['Архив','secondary']];
                [$status_label, $status_color] = $status_map[$t['status']] ?? ['Открыта','success'];
                $date = date('d.m.Y', strtotime($t['created_at']));
                
                $topicCategory = null;
                if ($t['category_id']) {
                    $topicCategory = $category->getById($t['category_id']);
                }
                ?>
                <div class="card topic-card" onclick="window.location='topic.php?id=<?= $t['id'] ?>'">
                    <div class="card-body py-3 px-3">
                        <div class="d-flex align-items-start gap-3">
                            <div class="flex-grow-1 min-w-0">
                                <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                    <h6 class="card-title mb-0">
                                        <a href="topic.php?id=<?= $t['id'] ?>" style="color: #1e1e2e; text-decoration: none; font-weight: 600;">
                                            <?= htmlspecialchars($t['title']) ?>
                                        </a>
                                    </h6>
                                    <?php if (!empty($t['is_pinned'])): ?>
                                        <span class="badge bg-warning text-dark" title="Закреплённая тема">Закреплено</span>
                                    <?php endif; ?>
                                    <?php if ($topicCategory): ?>
                                        <a href="index.php?category=<?= $topicCategory['id'] ?>" class="badge bg-info bg-opacity-10 text-info text-decoration-none">
                                            <i class="bi bi-folder me-1"></i><?= htmlspecialchars($topicCategory['name']) ?>
                                        </a>
                                    <?php endif; ?>
                                    <span class="badge badge-status bg-<?= $status_color ?>-subtle text-<?= $status_color ?>"><?= $status_label ?></span>
                                </div>
                                <div class="text-muted small">
                                    <i class="bi bi-calendar3 me-1"></i><?= $date ?>
                                    <?php if (!empty($t['description'])): ?>
                                        <br><small><?= htmlspecialchars(substr($t['description'], 0, 100)) ?>...</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <a href="topic.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary">Открыть</a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Пагинация -->
            <?php if ($totalPages > 1): ?>
            <nav aria-label="Page navigation" class="mt-4">
                <ul class="pagination pagination-sm justify-content-center">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page - 1 ?>"><i class="bi bi-chevron-left"></i></a>
                    </li>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page + 1 ?>"><i class="bi bi-chevron-right"></i></a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 rounded-3 shadow-sm">
                <div class="card-body">
                    <div class="section-title mb-3">Мой профиль</div>
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <?php if ($currentUserAvatar): ?>
                            <img src="<?= htmlspecialchars($currentUserAvatar) ?>?t=<?= time() ?>" alt="<?= $username ?>" style="width:52px;height:52px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                        <?php else: ?>
                            <div style="width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.2rem;font-weight:700;flex-shrink:0;">
                                <?= mb_strtoupper(mb_substr($username, 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <div class="fw-bold"><?= $username ?></div>
                            <?php
                            $sidebarRoleLabel = 'Участник';
                            $sidebarBadgeClass = 'bg-success bg-opacity-10 text-success';
                            if ($userRole === 'moderator') {
                                $sidebarRoleLabel = 'Модератор';
                                $sidebarBadgeClass = 'bg-warning bg-opacity-10 text-warning';
                            } elseif ($userRole === 'admin') {
                                $sidebarRoleLabel = 'Админ';
                                $sidebarBadgeClass = 'bg-primary bg-opacity-10 text-primary';
                            }
                            ?>
                            <span class="badge <?= $sidebarBadgeClass ?>"><?= $sidebarRoleLabel ?></span>
                        </div>
                    </div>
                    <a href="../home/profile.php" class="btn btn-outline-primary w-100 btn-sm">
                        <i class="bi bi-person me-1"></i>Профиль
                    </a>
                </div>
            </div>

            <div class="card border-0 rounded-3 shadow-sm mt-3">
                <div class="card-body">
                    <div class="section-title mb-3">Статистика</div>
                    <div class="list-group list-group-flush">
                        <div class="list-group-item border-0 ps-0 pe-0 py-2">
                            <small class="text-muted">Всего тем:</small>
                            <div class="fw-bold"><?= $total_topics ?></div>
                        </div>
                        <div class="list-group-item border-0 ps-0 pe-0 py-2">
                            <a href="my_posts.php" class="text-decoration-none">
                                <i class="bi bi-chat-dots me-2"></i>Мои ответы
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
