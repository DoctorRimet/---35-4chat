<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Topic.php';
require_once __DIR__ . '/../classes/Post.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Category.php';
require_once __DIR__ . '/../classes/NotificationManager.php';

$db = new Database();
$conn = $db->getConnection();
$topic = new Topic($conn);
$post = new Post($conn);
$user = new User($conn);
$category = new Category($conn);
$notificationManager = new NotificationManager($conn);

// Получить выбранную категорию
$selectedCategory = isset($_GET['category']) ? (int)$_GET['category'] : null;
$selectedTag = null;
$categoryData = null;
$breadcrumbs = [];

if ($selectedCategory) {
    $categoryData = $category->getById($selectedCategory);
    if ($categoryData) {
        $breadcrumbs = $category->getBreadcrumb($selectedCategory);
    } else {
        $selectedCategory = null;
    }
} else {
    // Получить тег только если не выбрана категория
    $selectedTag = trim($_GET['tag'] ?? '');
}

// Пагинация
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$itemsPerPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$itemsPerPage = max(20, min(100, $itemsPerPage)); // От 20 до 100

// Получить темы с пагинацией
if ($selectedCategory) {
    $topics_stmt = $topic->getPaginated($page, $itemsPerPage, $selectedCategory);
    $total_topics = $topic->getTotalCount($selectedCategory);
} elseif ($selectedTag) {
    // Для тегов используем старый метод (без пагинации в DB, но это редко)
    $topics_stmt = $topic->getAll($selectedTag);
    $topics = $topics_stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_topics = count($topics);
} else {
    $topics_stmt = $topic->getPaginated($page, $itemsPerPage);
    $total_topics = $topic->getTotalCount();
}

// Если не использовали старый метод для тегов, получить данные
if (!$selectedTag) {
    $topics = $topics_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Вычислить количество страниц
$totalPages = ceil($total_topics / $itemsPerPage);
$totalPages = max(1, $totalPages); // Минимум 1 страница

// Получить недавние обсуждения и популярные темы для боковой панели
$recentDiscussions = $topic->getRecentDiscussions(5)->fetchAll(PDO::FETCH_ASSOC);
$popularTopics = $topic->getPopularTopics(5)->fetchAll(PDO::FETCH_ASSOC);

$allCategories = $category->getTopLevel()->fetchAll(PDO::FETCH_ASSOC);
$popularTags = $topic->getPopularTags(10);

$posts_stmt = $post->getAll();
$all_posts_raw = $posts_stmt->fetchAll(PDO::FETCH_ASSOC);

// Фильтруем посты: скрытые видны только админам, модераторам и авторам
$all_posts = [];
foreach ($all_posts_raw as $p) {
    if (!$p['hidden']) {
        // Обычные видимые посты видят все
        $all_posts[] = $p;
    } elseif ($userRole === 'admin' || $userRole === 'moderator') {
        // Администраторы и модераторы видят все посты
        $all_posts[] = $p;
    } elseif (!$is_guest && $p['author_id'] == $_SESSION['user_id']) {
        // Автор видит свой скрытый пост
        $all_posts[] = $p;
    }
}
$total_posts = count($all_posts);

$users_stmt = $user->getAll();
$total_users = $users_stmt->rowCount();

$post_counts = [];
foreach ($all_posts as $p) {
    $post_counts[$p['topic_id']] = ($post_counts[$p['topic_id']] ?? 0) + 1;
}

$username = htmlspecialchars($_SESSION['username'] ?? 'Гость');
$is_guest = !isset($_SESSION['user_id']) || $_SESSION['user_id'] == 0;
$userRole = $_SESSION['user_role'] ?? 'user';
$currentUserAvatar = '';

if (!$is_guest && isset($_SESSION['user_id'])) {
    $currentUserData = $user->getById($_SESSION['user_id']);
    $currentUserProfile = $user->getProfile($_SESSION['user_id']);

    // Ensure profile exists
    if (!$currentUserProfile) {
        $user->updateProfile($_SESSION['user_id'], null, null, null, null);
        $currentUserProfile = $user->getProfile($_SESSION['user_id']);
    }

    $currentUserAvatar = $currentUserProfile['avatar_url'] ?? $currentUserData['avatar_url'] ?? '';
}

// Получаем количество непрочитанных уведомлений
$unread_count = 0;
if (!$is_guest && isset($_SESSION['user_id'])) {
    $unread_count = $notificationManager->getUnreadCount($_SESSION['user_id']);
}
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

/* Стили для категорий и хлебных крошек */
.breadcrumb { border-bottom: 1px solid #e9ecef; }
.breadcrumb-item a { color: #6366f1; }
.breadcrumb-item a:hover { color: #8b5cf6; text-decoration: underline; }
.breadcrumb-item.active { color: #1e1e2e; font-weight: 600; }
.btn-outline-secondary { color: #6c757d; border-color: #dee2e6; }
.btn-outline-secondary:hover { background-color: #f0f2f5; border-color: #adb5bd; color: #495057; }
.btn.btn-sm.rounded-pill { transition: all .2s; padding: .375rem .75rem; }

/* Стили для list-group в боковой панели */
.list-group-item { padding: 0.5rem 0; color: #495057; }
.list-group-item:hover { background-color: #f8f9fa; }
.list-group-item.active { background-color: #f0f2f5; color: #6366f1; font-weight: 600; border-left: 3px solid #6366f1; padding-left: -3px; }

/* Стили для пагинации */
.pagination { margin-top: 1.5rem; }
.page-link { color: #6366f1; border-color: #dee2e6; }
.page-link:hover { background-color: #f0f2f5; border-color: #adb5bd; }
.page-item.active .page-link { background-color: #6366f1; border-color: #6366f1; }
.page-item.disabled .page-link { color: #adb5bd; cursor: not-allowed; }

/* Контрол выбора количества элементов */
.btn-group-sm .btn { padding: 0.375rem 0.75rem; font-size: 0.875rem; }
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
                <?php if (!$is_guest) : ?>
                <li class="nav-item">
                    <a class="nav-link" href="../notifications.php">
                        <i class="bi bi-bell me-1"></i>Сообщения
                        <?php if ($unread_count > 0) : ?>
                            <span class="badge bg-danger ms-1"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../pages/search_history.php">
                        <i class="bi bi-clock-history me-1"></i>История
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../home/profile.php">
                        <i class="bi bi-person me-1"></i>Профиль
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../create.php">
                        <i class="bi bi-plus-circle me-1"></i>Создать тему
                    </a>
                </li>
                    <?php if (in_array($userRole, ['admin', 'moderator'])) : ?>
                <li class="nav-item">
                    <a class="nav-link text-primary fw-semibold" href="admin_panel.php">
                        <i class="bi bi-shield-check me-1"></i>Модерация
                    </a>
                </li>
                    <?php endif; ?>
                <?php endif; ?>
            </ul>

            <div class="d-flex align-items-center gap-2">
                <?php if (!$is_guest && $currentUserAvatar) : ?>
                    <img src="<?= htmlspecialchars($currentUserAvatar) ?>?t=<?= time() ?>" alt="<?= $username ?>" class="avatar-sm" style="object-fit:cover;">
                <?php else : ?>
                    <div class="avatar-sm"><?= mb_strtoupper(mb_substr($username, 0, 1)) ?></div>
                <?php endif; ?>
                <span class="fw-semibold small"><?= $username ?></span>
                <?php if ($is_guest) : ?>
                    <span class="badge bg-secondary">Гость</span>
                    <a href="../auth/login.php" class="btn btn-sm btn-outline-primary rounded-3">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Войти
                    </a>
                <?php else :
                    $roleLabel = 'Участник';
                    $badgeClass = 'bg-success bg-opacity-10 text-success';
                    if ($userRole === 'moderator') {
                        $roleLabel = 'Модератор';
                        $badgeClass = 'bg-warning bg-opacity-10 text-warning';
                    } elseif ($userRole === 'admin') {
                        $roleLabel = 'Админ';
                        $badgeClass = 'bg-primary bg-opacity-10 text-primary';
                    }
                    ?>
                    <span class="badge <?= $badgeClass ?>"><?= $roleLabel ?></span>
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
        <div style="display: flex; gap: 10px; align-items: center;">
            <form action="/pages/search.php" method="GET" class="d-flex gap-2" style="flex: 1;">
                <div class="input-group" style="max-width:480px">
                    <span class="input-group-text bg-white border-end-0">
                        <i class="bi bi-search text-secondary"></i>
                    </span>
                    <input type="text" name="q" class="form-control border-start-0 ps-0"
                        placeholder="Поиск по темам и постам (минимум 3 символа)..."
                        value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"
                        minlength="3">
                </div>
                <button type="submit" class="btn btn-sm px-3 rounded-3 fw-semibold text-white"
                    style="background:linear-gradient(135deg,#6366f1,#8b5cf6);border:none">
                    Найти
                </button>
            </form>
            <button class="filter-toggle-btn" data-filter-toggle title="Фильтры поиска">
                <i class="bi bi-funnel"></i> Фильтры
            </button>
        </div>
    </div>
</div>

<!-- Модальное окно фильтров -->
<div id="filterModal" class="filter-modal">
    <div class="filter-modal-content">
        <div class="filter-modal-header">
            <h2>⚙️ Фильтры поиска</h2>
            <button class="filter-close-btn" data-filter-close>✕</button>
        </div>

        <div class="filter-group">
            <label for="filterSort">Сортировка</label>
            <select id="filterSort">
                <option value="date_desc">Новые сначала</option>
                <option value="date_asc">Старые сначала</option>
                <option value="replies">По количеству ответов</option>
                <option value="popularity">По популярности</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="filterAuthor">ID Автора (оставьте пусто для всех)</label>
            <input type="number" id="filterAuthor" placeholder="Введите ID автора" min="1">
        </div>

        <div class="filter-group">
            <label>Период создания</label>
            <div class="filter-date-range">
                <input type="date" id="filterDateFrom" placeholder="От">
                <input type="date" id="filterDateTo" placeholder="До">
            </div>
        </div>

        <div class="filter-group">
            <label for="filterMinReplies">Минимум ответов</label>
            <input type="number" id="filterMinReplies" value="0" min="0" placeholder="0">
        </div>

        <div class="filter-actions">
            <button class="filter-btn filter-btn-apply" data-filter-apply>Применить</button>
            <button class="filter-btn filter-btn-reset" data-filter-reset>Сброс</button>
        </div>
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
                <?php if (!$is_guest) : ?>
                <a href="../create.php" class="btn btn-create btn-primary btn-sm px-3">
                    <i class="bi bi-plus-lg me-1"></i>Новая тема
                </a>
                <?php endif; ?>
            </div>

            <!-- Хлебные крошки (breadcrumbs) -->
            <?php if (!empty($breadcrumbs)) : ?>
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb mb-0 bg-light p-2 rounded">
                    <li class="breadcrumb-item">
                        <a href="index.php" class="text-decoration-none">
                            <i class="bi bi-house me-1"></i>Все категории
                        </a>
                    </li>
                    <?php foreach ($breadcrumbs as $bc) : ?>
                    <li class="breadcrumb-item <?= ($bc['id'] === $selectedCategory) ? 'active' : '' ?>">
                        <?php if ($bc['id'] === $selectedCategory) : ?>
                            <strong><?= htmlspecialchars($bc['name']) ?></strong>
                        <?php else : ?>
                            <a href="index.php?category=<?= $bc['id'] ?>" class="text-decoration-none">
                                <?= htmlspecialchars($bc['name']) ?>
                            </a>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ol>
            </nav>
            <?php endif; ?>

            <!-- Панель категорий -->
            <?php if (!empty($allCategories)) : ?>
            <div class="card border-0 rounded-3 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="index.php" class="btn btn-sm <?= !$selectedCategory ? 'btn-primary' : 'btn-outline-secondary' ?> rounded-pill">
                            <i class="bi bi-grid-3x2-gap me-1"></i>Все темы
                        </a>
                        <?php foreach ($allCategories as $cat) : ?>
                        <a href="index.php?category=<?= $cat['id'] ?>" class="btn btn-sm <?= ($selectedCategory === (int)$cat['id']) ? 'btn-primary' : 'btn-outline-secondary' ?> rounded-pill">
                            <i class="bi bi-folder me-1"></i><?= htmlspecialchars($cat['name']) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($selectedTag && !$selectedCategory) : ?>
            <div class="alert alert-info">
                <strong>Фильтр по тегу:</strong> <span class="badge bg-secondary">#<?= htmlspecialchars($selectedTag) ?></span>
                <a href="index.php" class="link-primary ms-2">Сбросить фильтр</a>
            </div>
            <?php endif; ?>

            <?php if (empty($topics)) : ?>
            <div class="card border-0 rounded-3 shadow-sm">
                <div class="empty-state">
                    <i class="bi bi-chat-square-dots display-4 d-block mb-3"></i>
                    <p class="mb-0">Тем пока нет. Будьте первым!</p>
                    <?php if (!$is_guest) : ?>
                    <a href="../create.php" class="btn btn-create btn-primary mt-3">Создать тему</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php else : ?>
            <div class="d-flex flex-column gap-2">
                <?php foreach ($topics as $t) : ?>
                    <?php
                    $post_count = $post_counts[$t['id']] ?? 0;
                    $author = $user->getById($t['author_id']);
                    $author_profile = $user->getProfile($t['author_id']);
                    $author_avatar = $author_profile['avatar_url'] ?? $author['avatar_url'] ?? null;
                    $author_name = $author ? htmlspecialchars($author['username']) : 'Неизвестно';
                    $author_initial = mb_strtoupper(mb_substr($author_name, 0, 1));
                    $status_map = ['open' => ['Открыта','success'], 'closed' => ['Закрыта','danger'], 'archived' => ['Архив','secondary']];
                    [$status_label, $status_color] = $status_map[$t['status']] ?? ['Открыта','success'];
                    $date = date('d.m.Y', strtotime($t['created_at']));

                // Получить информацию о категории темы
                    $topicCategory = null;
                    if ($t['category_id']) {
                        $topicCategory = $category->getById($t['category_id']);
                    }
                    ?>
                <div class="card topic-card" onclick="window.location='topic.php?id=<?= $t['id'] ?>'">
                    <div class="card-body py-3 px-3">
                        <div class="d-flex align-items-start gap-3">
                            <?php if ($author_avatar) : ?>
                                <img src="<?= htmlspecialchars($author_avatar) ?>?t=<?= time() ?>" alt="<?= $author_name ?>" class="avatar-sm mt-1" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">
                            <?php else : ?>
                                <div class="avatar-sm mt-1"><?= $author_initial ?></div>
                            <?php endif; ?>
                            <div class="flex-grow-1 min-w-0">
                                <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                    <h6 class="card-title mb-0">
                                        <a href="../topic.php?id=<?= $t['id'] ?>"><?= htmlspecialchars($t['title']) ?></a>
                                    </h6>
                                    <?php
                                    // Проверяем, есть ли скрытые посты в этой теме
                                    $hidden_count = 0;
                                    foreach ($all_posts as $post_item) {
                                        if ($post_item['topic_id'] == $t['id'] && $post_item['hidden']) {
                                            $hidden_count++;
                                        }
                                    }
                                    ?>
                                    <?php if (!empty($t['is_pinned'])) : ?>
                                        <span class="badge bg-warning text-dark" title="Закреплённая тема">Закреплено</span>
                                    <?php endif; ?>
                                    <?php if ($topicCategory) : ?>
                                        <a href="index.php?category=<?= $topicCategory['id'] ?>" class="badge bg-info bg-opacity-10 text-info text-decoration-none">
                                            <i class="bi bi-folder me-1"></i><?= htmlspecialchars($topicCategory['name']) ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($hidden_count > 0) : ?>
                                        <span class="badge bg-danger" title="<?= $hidden_count ?> скрытых постов">
                                            <i class="bi bi-eye-slash"></i> <?= $hidden_count ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="badge badge-status bg-<?= $status_color ?>-subtle text-<?= $status_color ?>"><?= $status_label ?></span>
                                </div>
                                <div class="text-muted small d-flex align-items-center gap-3 flex-wrap">
                                    <span><i class="bi bi-person me-1"></i><a href="../home/profile.php?id=<?= $t['author_id'] ?>" class="text-decoration-none text-dark" onclick="event.stopPropagation()"><?= $author_name ?></a></span>
                                    <span><i class="bi bi-calendar3 me-1"></i><?= $date ?></span>
                                    <span><i class="bi bi-chat me-1"></i><?= $post_count ?> <?= $post_count === 1 ? 'пост' : ($post_count < 5 ? 'поста' : 'постов') ?></span>
                                    <?php if (!$is_guest && $t['author_id'] !== $_SESSION['user_id']) : ?>
                                    <button type="button" class="btn btn-link btn-sm text-warning p-0" data-bs-toggle="modal" data-bs-target="#reportIndexTopicModal<?= $t['id'] ?>" onclick="event.stopPropagation()" title="Пожаловаться">
                                        <i class="bi bi-exclamation-circle me-1"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <i class="bi bi-chevron-right text-muted mt-2 d-none d-sm-block"></i>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Пагинация -->
                <?php if ($total_topics > 0 && !$selectedTag) : ?>
            <nav aria-label="pagination" class="mt-4 d-flex align-items-center justify-content-between">
                <div class="text-muted small">
                    Показано <?= (($page - 1) * $itemsPerPage) + 1 ?>–<?= min($page * $itemsPerPage, $total_topics) ?> из <?= $total_topics ?> тем
                </div>
                
                <!-- Выбор количества элементов -->
                <div class="btn-group btn-group-sm" role="group">
                    <?php foreach ([20, 50, 100] as $count) : ?>
                        <a href="?page=1&per_page=<?= $count ?><?= $selectedCategory ? '&category=' . $selectedCategory : '' ?><?= $selectedTag ? '&tag=' . urlencode($selectedTag) : '' ?>" 
                           class="btn <?= $itemsPerPage === $count ? 'btn-primary' : 'btn-outline-secondary' ?>">
                            <?= $count ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </nav>

            <!-- Пагинация (навигация) -->
                    <?php if ($totalPages > 1) : ?>
            <nav aria-label="Page navigation" class="mt-3">
                <ul class="pagination pagination-sm justify-content-center">
                    <!-- Кнопка "Предыдущая" -->
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=1&per_page=<?= $itemsPerPage ?><?= $selectedCategory ? '&category=' . $selectedCategory : '' ?><?= $selectedTag ? '&tag=' . urlencode($selectedTag) : '' ?>">
                            <i class="bi bi-chevron-double-left"></i>
                        </a>
                    </li>
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page - 1 ?>&per_page=<?= $itemsPerPage ?><?= $selectedCategory ? '&category=' . $selectedCategory : '' ?><?= $selectedTag ? '&tag=' . urlencode($selectedTag) : '' ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>

                    <!-- Номера страниц -->
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);

                        if ($startPage > 1) : ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=1&per_page=<?= $itemsPerPage ?><?= $selectedCategory ? '&category=' . $selectedCategory : '' ?><?= $selectedTag ? '&tag=' . urlencode($selectedTag) : '' ?>">1</a>
                        </li>
                            <?php if ($startPage > 2) : ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++) : ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>&per_page=<?= $itemsPerPage ?><?= $selectedCategory ? '&category=' . $selectedCategory : '' ?><?= $selectedTag ? '&tag=' . urlencode($selectedTag) : '' ?>">
                                <?= $i ?>
                            </a>
                        </li>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages) : ?>
                            <?php if ($endPage < $totalPages - 1) : ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $totalPages ?>&per_page=<?= $itemsPerPage ?><?= $selectedCategory ? '&category=' . $selectedCategory : '' ?><?= $selectedTag ? '&tag=' . urlencode($selectedTag) : '' ?>"><?= $totalPages ?></a>
                        </li>
                        <?php endif; ?>

                    <!-- Кнопка "Следующая" -->
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page + 1 ?>&per_page=<?= $itemsPerPage ?><?= $selectedCategory ? '&category=' . $selectedCategory : '' ?><?= $selectedTag ? '&tag=' . urlencode($selectedTag) : '' ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $totalPages ?>&per_page=<?= $itemsPerPage ?><?= $selectedCategory ? '&category=' . $selectedCategory : '' ?><?= $selectedTag ? '&tag=' . urlencode($selectedTag) : '' ?>">
                            <i class="bi bi-chevron-double-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 rounded-3 shadow-sm mb-3">
                <div class="card-body">
                    <div class="section-title mb-3">Мой профиль</div>
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <?php if (!$is_guest && $currentUserAvatar) : ?>
                            <img src="<?= htmlspecialchars($currentUserAvatar) ?>?t=<?= time() ?>" alt="<?= $username ?>" style="width:52px;height:52px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                        <?php else : ?>
                            <div style="width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.2rem;font-weight:700;flex-shrink:0;">
                                <?= mb_strtoupper(mb_substr($username, 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <div class="fw-bold"><?= $username ?></div>
                    <?php if ($is_guest) : ?>
                    <span class="badge bg-secondary">Гость</span>
                    <?php else :
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
                    <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($is_guest) : ?>
                    <a href="../auth/login.php" class="btn btn-create btn-primary w-100 btn-sm">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Войти в аккаунт
                    </a>
                    <?php else : ?>
                    <a href="../auth/logout.php" class="btn btn-outline-danger w-100 btn-sm rounded-3">
                        <i class="bi bi-box-arrow-right me-1"></i>Выйти
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$is_guest) : ?>
            <div class="card border-0 rounded-3 shadow-sm mb-3">
                <div class="card-body">
                    <div class="section-title mb-3">Быстрые действия</div>
                    <div class="d-grid gap-2">
                        <a href="../create.php" class="btn btn-create btn-primary btn-sm">
                            <i class="bi bi-plus-lg me-1"></i>Создать тему
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Все категории -->
            <?php if (!empty($allCategories)) : ?>
            <div class="card border-0 rounded-3 shadow-sm mb-3">
                <div class="card-body">
                    <div class="section-title mb-3">Категории</div>
                    <div class="list-group list-group-flush">
                        <a href="index.php" class="list-group-item list-group-item-action border-0 ps-0 pe-0 <?= !$selectedCategory ? 'active' : '' ?>">
                            <i class="bi bi-grid-3x2-gap me-2"></i>Все темы
                        </a>
                        <?php foreach ($allCategories as $cat) : ?>
                        <a href="index.php?category=<?= $cat['id'] ?>" class="list-group-item list-group-item-action border-0 ps-0 pe-0 <?= ($selectedCategory === (int)$cat['id']) ? 'active' : '' ?>">
                            <i class="bi bi-folder me-2"></i><?= htmlspecialchars($cat['name']) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Недавние обсуждения -->
            <?php if (!empty($recentDiscussions)) : ?>
            <div class="card border-0 rounded-3 shadow-sm mb-3">
                <div class="card-body">
                    <div class="section-title mb-3">
                        <i class="bi bi-fire me-1"></i>Недавние обсуждения
                    </div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($recentDiscussions as $t) : ?>
                        <a href="../topic.php?id=<?= $t['id'] ?>" class="list-group-item list-group-item-action border-0 ps-0 pe-0 py-2">
                            <small class="d-block text-truncate" style="max-width: 100%;">
                                <?= htmlspecialchars($t['title']) ?>
                            </small>
                            <small class="text-muted">
                                <i class="bi bi-chat-dots me-1"></i><?= $t['posts_count'] ?? 0 ?> постов
                                <?php if ($t['last_post_date']) : ?>
                                    • <?= date('d.m', strtotime($t['last_post_date'])) ?>
                                <?php endif; ?>
                            </small>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Популярные темы -->
            <?php if (!empty($popularTopics)) : ?>
            <div class="card border-0 rounded-3 shadow-sm mb-3">
                <div class="card-body">
                    <div class="section-title mb-3">
                        <i class="bi bi-star me-1"></i>Популярные темы
                    </div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($popularTopics as $t) : ?>
                        <a href="../topic.php?id=<?= $t['id'] ?>" class="list-group-item list-group-item-action border-0 ps-0 pe-0 py-2">
                            <small class="d-block text-truncate" style="max-width: 100%;">
                                <?= htmlspecialchars($t['title']) ?>
                            </small>
                            <small class="text-muted">
                                <i class="bi bi-chat-dots me-1"></i><?= ($t['posts_count'] ?? 0) + ($t['comments_count'] ?? 0) ?> сообщений
                            </small>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <div class="card border-0 rounded-3 shadow-sm">
                <div class="card-body">
                    <div class="section-title mb-3">Популярные теги</div>
                    <?php if (!empty($popularTags)) : ?>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($popularTags as $tag) : ?>
                                <a href="index.php?tag=<?= urlencode($tag['name']) ?>" class="badge bg-secondary bg-opacity-15 text-dark">#<?= htmlspecialchars($tag['name']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <p class="mb-0 text-muted">Теги пока не добавлены.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="/assets/filter.css">
<script src="/assets/search.js"></script>
<script src="/assets/filter.js"></script>

<!-- Модальные окна жалоб на темы в списке -->
<?php foreach ($topics as $t) : ?>
<div class="modal fade" id="reportIndexTopicModal<?= $t['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning bg-opacity-10">
                <h5 class="modal-title"><i class="bi bi-exclamation-circle me-2"></i>Пожаловаться на тему</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form class="reportIndexTopicForm" method="POST">
                <div class="modal-body">
                    <p class="text-muted small mb-3">Сообщите модераторам, если эта тема нарушает правила сообщества.</p>
                    <input type="hidden" name="topic_id" value="<?= $t['id'] ?>">
                    <div class="mb-3">
                        <label class="form-label">Причина жалобы</label>
                        <textarea name="reason" class="form-control" rows="4" placeholder="Объясните, почему вы хотите пожаловаться на эту тему..." required></textarea>
                        <small class="text-muted">Минимум 5 символов, максимум 500</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-warning">Отправить жалобу</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
document.querySelectorAll('.reportIndexTopicForm').forEach(form => {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const topicId = this.querySelector('input[name="topic_id"]').value;
        const reason = this.querySelector('textarea[name="reason"]').value;
        
        if (reason.length < 5) {
            alert('Причина должна содержать минимум 5 символов');
            return;
        }
        if (reason.length > 500) {
            alert('Причина не должна превышать 500 символов');
            return;
        }
        
        fetch('../api_complaints.php?action=report_topic', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'topic_id=' + encodeURIComponent(topicId) + '&reason=' + encodeURIComponent(reason)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                const modal = bootstrap.Modal.getInstance(document.getElementById('reportIndexTopicModal' + topicId));
                if (modal) modal.hide();
                this.reset();
            } else {
                alert('Ошибка: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка при отправке жалобы');
        });
    });
});
</script>

</body>
</html>
