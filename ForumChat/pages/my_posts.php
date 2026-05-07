<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Post.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Topic.php';

// Только для авторизованных пользователей
if ($is_guest) {
    header('Location: ../auth/login.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();
$post = new Post($conn);
$user = new User($conn);
$topic = new Topic($conn);

// Получить ответы пользователя
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$itemsPerPage = 20;
$offset = ($page - 1) * $itemsPerPage;

$stmt = $post->getByUserId($_SESSION['user_id']);
$all_user_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_posts = count($all_user_posts);

// Пагинация
$user_posts = array_slice($all_user_posts, $offset, $itemsPerPage);
$totalPages = ceil($total_posts / $itemsPerPage);
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
<title>Мои ответы — ForumChat</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body { background-color: #f0f2f5; }
.navbar-brand-icon { width: 36px; height: 36px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; }
.navbar { box-shadow: 0 1px 8px rgba(0,0,0,.06); }
.section-title { font-size: 1.1rem; font-weight: 700; color: #1e1e2e; border-left: 3px solid #6366f1; padding-left: .6rem; }
.post-card { border: none; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,.05); transition: box-shadow .2s, transform .2s; }
.post-card:hover { box-shadow: 0 6px 20px rgba(99,102,241,.15); transform: translateY(-2px); }
.avatar-sm { width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #8b5cf6); display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: .75rem; font-weight: 700; flex-shrink: 0; }
.empty-state { padding: 3rem 1rem; text-align: center; color: #adb5bd; }
.post-excerpt { color: #495057; max-height: 80px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
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
                    <a class="nav-link" href="my_topics.php">
                        <i class="bi bi-bookmark me-1"></i>Мои темы
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active fw-semibold" href="my_posts.php">
                        <i class="bi bi-chat-dots me-1"></i>Мои ответы
                    </a>
                </li>
            </ul>
            <div class="d-flex align-items-center gap-2">
                <?php if ($currentUserAvatar) : ?>
                    <img src="<?= htmlspecialchars($currentUserAvatar) ?>?t=<?= time() ?>" alt="<?= $username ?>" class="avatar-sm" style="object-fit:cover;">
                <?php else : ?>
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
            <div class="section-title mb-3">
                <i class="bi bi-chat-dots me-1"></i>Мои ответы (<?= $total_posts ?>)
            </div>

            <?php if (empty($user_posts)) : ?>
            <div class="card border-0 rounded-3 shadow-sm">
                <div class="empty-state">
                    <i class="bi bi-chat-dots display-4 d-block mb-3"></i>
                    <p class="mb-2">У вас пока нет ответов</p>
                    <small class="text-muted">Участвуйте в обсуждениях, чтобы ваши ответы появились здесь</small>
                    <br>
                    <a href="index.php" class="btn btn-primary mt-3">Найти темы для обсуждения</a>
                </div>
            </div>
            <?php else : ?>
            <div class="d-flex flex-column gap-3">
                <?php foreach ($user_posts as $p) : ?>
                    <?php
                    $date = date('d.m.Y H:i', strtotime($p['created_at']));
                    $topicTitle = htmlspecialchars($p['topic_title'] ?? 'Неизвестная тема');
                    $topicLink = "topic.php?id=" . $p['topic_id'];
                    $excerpt = strip_tags($p['content'] ?? '');
                    $excerpt = substr($excerpt, 0, 150);
                    ?>
                <div class="card post-card">
                    <div class="card-body p-3">
                        <div class="mb-2">
                            <a href="<?= $topicLink ?>" class="text-decoration-none fw-bold" style="color: #6366f1;">
                                <i class="bi bi-box-arrow-up-right me-1"></i><?= $topicTitle ?>
                            </a>
                        </div>
                        <p class="post-excerpt mb-2"><?= htmlspecialchars($excerpt) ?></p>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted">
                                <i class="bi bi-calendar3 me-1"></i><?= $date ?>
                            </small>
                            <a href="<?= $topicLink ?>" class="btn btn-sm btn-outline-primary">Перейти</a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Пагинация -->
                <?php if ($totalPages > 1) : ?>
            <nav aria-label="Page navigation" class="mt-4">
                <ul class="pagination pagination-sm justify-content-center">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page - 1 ?>"><i class="bi bi-chevron-left"></i></a>
                    </li>
                    <?php for ($i = 1; $i <= $totalPages; $i++) : ?>
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
                        <?php if ($currentUserAvatar) : ?>
                            <img src="<?= htmlspecialchars($currentUserAvatar) ?>?t=<?= time() ?>" alt="<?= $username ?>" style="width:52px;height:52px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                        <?php else : ?>
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
                            <small class="text-muted">Всего ответов:</small>
                            <div class="fw-bold"><?= $total_posts ?></div>
                        </div>
                        <div class="list-group-item border-0 ps-0 pe-0 py-2">
                            <a href="my_topics.php" class="text-decoration-none">
                                <i class="bi bi-bookmark me-2"></i>Мои темы
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
