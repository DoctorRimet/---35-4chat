<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/SearchHistory.php';

// Только для авторизованных пользователей
if ($is_guest) {
    header('Location: ../auth/login.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();
$searchHistory = new SearchHistory($conn);

// Получить историю поисков
$stmt = $searchHistory->getHistory($_SESSION['user_id'], 50);
$searches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получить топ поисков
$stmt = $searchHistory->getTopSearches($_SESSION['user_id'], 20);
$topSearches = $stmt->fetchAll(PDO::FETCH_ASSOC);

$username = htmlspecialchars($_SESSION['username'] ?? 'Пользователь');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>История поисков — ForumChat</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body { background-color: #f0f2f5; }
.navbar-brand-icon { width: 36px; height: 36px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; }
.navbar { box-shadow: 0 1px 8px rgba(0,0,0,.06); }
.section-title { font-size: 1.1rem; font-weight: 700; color: #1e1e2e; border-left: 3px solid #6366f1; padding-left: .6rem; }
.search-item { border: none; border-radius: 12px; background: white; padding: 1rem; transition: all .2s; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
.search-item:hover { box-shadow: 0 4px 16px rgba(99,102,241,.1); }
.search-item a { text-decoration: none; color: #6366f1; font-weight: 500; }
.empty-state { padding: 3rem 1rem; text-align: center; color: #adb5bd; }
.badge-count { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; font-weight: 600; padding: 0.3em 0.8em; border-radius: 20px; }
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
                    <a class="nav-link active fw-semibold" href="search_history.php">
                        <i class="bi bi-clock-history me-1"></i>История поисков
                    </a>
                </li>
            </ul>
            <a href="../auth/logout.php" class="btn btn-sm btn-outline-danger rounded-3">
                <i class="bi bi-box-arrow-right me-1"></i>Выйти
            </a>
        </div>
    </div>
</nav>

<div class="container py-4">
    <div class="row">
        <div class="col-lg-8">
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div class="section-title">
                    <i class="bi bi-clock-history me-1"></i>История поисков (последние 30 дней)
                </div>
                <?php if (!empty($searches)): ?>
                <button class="btn btn-sm btn-outline-danger" onclick="if(confirm('Вы уверены?')) { window.location='?action=clear'; }">
                    <i class="bi bi-trash me-1"></i>Очистить
                </button>
                <?php endif; ?>
            </div>

            <?php if (empty($searches)): ?>
            <div class="card border-0 rounded-3 shadow-sm">
                <div class="empty-state">
                    <i class="bi bi-clock-history display-4 d-block mb-3"></i>
                    <p class="mb-2">История поисков пуста</p>
                    <small class="text-muted">Ваши поиски будут сохраняться здесь на 30 дней</small>
                    <br>
                    <a href="search.php" class="btn btn-primary mt-3">Начать поиск</a>
                </div>
            </div>
            <?php else: ?>
            <div class="d-flex flex-column gap-2">
                <?php foreach ($searches as $search): ?>
                <div class="search-item">
                    <div>
                        <a href="search.php?q=<?= urlencode($search['query']) ?>">
                            <i class="bi bi-search me-2"></i><?= htmlspecialchars($search['query']) ?>
                        </a>
                        <br>
                        <small class="text-muted">
                            <i class="bi bi-calendar3 me-1"></i><?= date('d.m.Y H:i', strtotime($search['last_search'])) ?>
                        </small>
                    </div>
                    <div>
                        <a href="search.php?q=<?= urlencode($search['query']) ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <?php if (!empty($topSearches)): ?>
            <div class="card border-0 rounded-3 shadow-sm">
                <div class="card-body">
                    <div class="section-title mb-3">Топ поисков</div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($topSearches as $search): ?>
                        <div class="list-group-item border-0 d-flex justify-content-between align-items-center px-0 py-2">
                            <a href="search.php?q=<?= urlencode($search['query']) ?>" class="text-decoration-none fw-500">
                                <?= htmlspecialchars($search['query']) ?>
                            </a>
                            <span class="badge-count"><?= $search['search_count'] ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card border-0 rounded-3 shadow-sm mt-3">
                <div class="card-body">
                    <div class="section-title mb-3">Информация</div>
                    <div class="list-group list-group-flush">
                        <div class="list-group-item border-0 ps-0 pe-0 py-2">
                            <small class="text-muted">История хранится:</small>
                            <div class="fw-bold">30 дней</div>
                        </div>
                        <div class="list-group-item border-0 ps-0 pe-0 py-2">
                            <small class="text-muted">Всего поисков:</small>
                            <div class="fw-bold"><?= count($searches) ?></div>
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
