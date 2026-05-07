<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/AdminManager.php';
require_once __DIR__ . '/../classes/PostModeration.php';
require_once __DIR__ . '/../classes/ForbiddenWords.php';
require_once __DIR__ . '/../classes/AutoModerator.php';
require_once __DIR__ . '/../classes/NotificationManager.php';
require_once __DIR__ . '/../classes/Complaint.php';

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

$userClass = new User($conn);
$current_user = $userClass->getById($user_id);
$notificationManager = new NotificationManager($conn);

// Проверяем права администратора
if ($current_user['user_role'] !== 'admin' && $current_user['user_role'] !== 'moderator') {
    header('Location: index.php');
    exit;
}

$adminManager = new AdminManager($conn);
$postModeration = new PostModeration($conn);
$forbiddenWords = new ForbiddenWords($conn);
$autoModerator = new AutoModerator($conn, $postModeration, $forbiddenWords);
$complaint = new Complaint($conn);

// Получаем активную вкладку
$tab = $_GET['tab'] ?? 'dashboard';

// Получаем данные жалоб для показа на панели
$pendingComplaints = $complaint->countPending();
$postComplaints = $complaint->countByType('post');
$topicComplaints = $complaint->countByType('topic');
$userComplaints = $complaint->countByType('user');

// Обработка действий
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$message = '';
$error = '';

// Проверка параметров успеха из GET (после редиректа)
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'opened') {
        $message = 'Пост открыт';
    } elseif ($_GET['success'] === 'deleted') {
        $message = 'Пост удалён';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action) {
    switch ($action) {
        case 'add_forbidden_word':
            $word = trim($_POST['word'] ?? '');
            if (!$word) {
                $error = 'Введите слово';
            } elseif (mb_strlen($word) > 255) {
                $error = 'Слово не должно превышать 255 символов';
            } elseif ($forbiddenWords->addWord($word, $user_id)) {
                $forbiddenWords->clearCache();
                $message = 'Запрещённое слово добавлено';
            } else {
                $error = 'Ошибка при добавлении слова';
            }
            break;
            
        case 'delete_forbidden_word':
            $word_id = $_POST['word_id'] ?? 0;
            if ($forbiddenWords->removeWord($word_id)) {
                $forbiddenWords->clearCache();
                $message = 'Запрещённое слово удалено';
            } else {
                $error = 'Ошибка при удалении слова';
            }
            break;
            
        case 'hide_post':
            $post_id = $_POST['post_id'] ?? 0;
            if ($postModeration->hidePost($post_id, 'Скрыто администратором')) {
                $message = 'Пост скрыт';
            } else {
                $error = 'Ошибка при скрытии поста';
            }
            break;
            
        case 'unhide_post':
            $post_id = $_POST['post_id'] ?? 0;
            if ($postModeration->unhidePost($post_id, $user_id)) {
                $message = 'Пост восстановлен';
            } else {
                $error = 'Ошибка при восстановлении поста';
            }
            break;
            
        case 'delete_post':
            $post_id = $_POST['post_id'] ?? 0;
            if ($postModeration->deletePost($post_id, $user_id)) {
                $message = 'Пост удалён';
            } else {
                $error = 'Ошибка при удалении поста';
            }
            break;
            
        case 'unhide_from_admin':
            // Убрать скрытие с поста из админ панели
            $post_id = $_POST['post_id'] ?? 0;
            if ($postModeration->unhidePost($post_id, $user_id)) {
                header('Location: admin_panel.php?tab=hidden_posts&success=opened');
                exit;
            } else {
                $error = 'Ошибка при открытии поста';
            }
            break;
            
        case 'delete_from_admin':
            // Удалить пост из админ панели
            $post_id = $_POST['post_id'] ?? 0;
            $delete_reason = trim($_POST['delete_reason'] ?? 'Пост нарушает правила сообщества');
            
            if ($postModeration->deletePost($post_id, $user_id, $delete_reason)) {
                header('Location: admin_panel.php?tab=hidden_posts&success=deleted');
                exit;
            } else {
                $error = 'Ошибка при удалении поста';
            }
            break;
        case 'ban_user':
            $target_user_id = $_POST['user_id'] ?? 0;
            $duration = $_POST['duration'] ?? '';
            $reason = trim($_POST['reason'] ?? '');
            
            if ($target_user_id && $duration) {
                if ($userClass->banUser($target_user_id, $user_id, $duration, $reason)) {
                    $message = 'Пользователь заблокирован';
                } else {
                    // Проверяем причину ошибки
                    $target_user = $userClass->getById($target_user_id);
                    if ($current_user_role !== 'admin' && $target_user && in_array($target_user['user_role'], ['admin', 'moderator'])) {
                        $error = 'Только администраторы могут блокировать администраторов и модераторов';
                    } else {
                        $error = 'Ошибка при блокировке пользователя';
                    }
                }
            } else {
                $error = 'Неверные параметры блокировки';
            }
            break;
            
        case 'unban_user':
            $target_user_id = $_POST['user_id'] ?? 0;
            
            if ($target_user_id) {
                if ($userClass->unbanUser($target_user_id, $user_id)) {
                    $message = 'Пользователь разблокирован';
                } else {
                    $error = 'Ошибка при разблокировке пользователя';
                }
            } else {
                $error = 'Неверный ID пользователя';
            }
            break;

        case 'resolve_complaint':
            $complaint_id = $_POST['complaint_id'] ?? 0;
            if ($complaint->updateStatus($complaint_id, 'resolved')) {
                $message = 'Жалоба отмечена как разрешённая';
            } else {
                $error = 'Ошибка при обновлении статуса жалобы';
            }
            break;

        case 'reject_complaint':
            $complaint_id = $_POST['complaint_id'] ?? 0;
            if ($complaint->updateStatus($complaint_id, 'rejected')) {
                $message = 'Жалоба отклонена';
            } else {
                $error = 'Ошибка при обновлении статуса жалобы';
            }
            break;

        case 'delete_complaint':
            $complaint_id = $_POST['complaint_id'] ?? 0;
            if ($complaint->delete($complaint_id)) {
                $message = 'Жалоба удалена';
            } else {
                $error = 'Ошибка при удалении жалобы';
            }
            break;
    }
}

?>

<?php
// Переменные для навигации
$username = htmlspecialchars($_SESSION['username'] ?? 'Гость');
$is_guest = !isset($_SESSION['user_id']) || $_SESSION['user_id'] == 0;
$userRole = $_SESSION['user_role'] ?? 'user';
$currentUserAvatar = '';

if (!$is_guest && isset($_SESSION['user_id'])) {
    $currentUserData = $userClass->getById($_SESSION['user_id']);
    $currentUserProfile = $userClass->getProfile($_SESSION['user_id']);
    
    // Ensure profile exists
    if (!$currentUserProfile) {
        $userClass->updateProfile($_SESSION['user_id'], null, null, null, null);
        $currentUserProfile = $userClass->getProfile($_SESSION['user_id']);
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
    <title>Админ-панель</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background-color: #f0f2f5; }
        .navbar-brand-icon { width: 36px; height: 36px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; }
        .navbar { box-shadow: 0 1px 8px rgba(0,0,0,.06); }
        .avatar-sm { width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #8b5cf6); display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: .75rem; font-weight: 700; flex-shrink: 0; }
        
        .sidebar {
            background-color: white;
            border-right: 1px solid #ddd;
        }
        
        .sidebar .nav-link {
            color: #333;
            border-left: 3px solid transparent;
            margin-bottom: 0.5rem;
        }
        
        .sidebar .nav-link:hover {
            background-color: #f5f5f5;
        }
        
        .sidebar .nav-link.active {
            background-color: #e7f3ff;
            border-left-color: #0d6efd;
            color: #0d6efd;
        }
        
        .content {
            background-color: white;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-card {
            background-color: white;
            border-left: 4px solid #0d6efd;
            padding: 1.5rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        
        .stat-card.danger {
            border-left-color: #dc3545;
        }
        
        .stat-card.warning {
            border-left-color: #ffc107;
        }
        
        .stat-card.success {
            border-left-color: #28a745;
        }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: bold;
            color: #333;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }
        
        .table-action-btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.85rem;
        }
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
                <?php if (!$is_guest): ?>
                <li class="nav-item">
                    <a class="nav-link" href="../notifications.php">
                        <i class="bi bi-bell me-1"></i>Сообщения
                        <?php if ($unread_count > 0): ?>
                            <span class="badge bg-danger ms-1"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
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
                <?php if (in_array($userRole, ['admin', 'moderator'])): ?>
                <li class="nav-item">
                    <a class="nav-link active text-primary fw-semibold" href="admin_panel.php">
                        <i class="bi bi-shield-check me-1"></i>Модерация
                    </a>
                </li>
                <?php endif; ?>
                <?php endif; ?>
            </ul>

            <div class="d-flex align-items-center gap-2">
                <?php if (!$is_guest && $currentUserAvatar): ?>
                    <img src="<?= htmlspecialchars($currentUserAvatar) ?>?t=<?= time() ?>" alt="<?= $username ?>" class="avatar-sm" style="object-fit:cover;">
                <?php else: ?>
                    <div class="avatar-sm"><?= mb_strtoupper(mb_substr($username, 0, 1)) ?></div>
                <?php endif; ?>
                <span class="fw-semibold small"><?= $username ?></span>
                <?php if ($is_guest): ?>
                    <span class="badge bg-secondary">Гость</span>
                    <a href="../auth/login.php" class="btn btn-sm btn-outline-primary rounded-3">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Войти
                    </a>
                <?php else:
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

<div class="container-fluid py-4">
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Боковая панель -->
            <div class="col-md-3">
                <div class="sidebar">
                    <div class="p-3 border-bottom">
                        <h6 class="mb-0 text-muted">МОДЕРАЦИЯ</h6>
                    </div>
                    <nav class="nav flex-column p-2">
                        <a href="admin_panel.php?tab=dashboard" 
                           class="nav-link <?php echo $tab === 'dashboard' ? 'active' : ''; ?>">
                            <i class="bi bi-graph-up me-2"></i>Панель управления
                        </a>
                        <a href="admin_panel.php?tab=hidden_posts" 
                           class="nav-link <?php echo $tab === 'hidden_posts' ? 'active' : ''; ?>">
                            <i class="bi bi-eye-slash me-2"></i>Скрытые посты
                        </a>
                        <a href="admin_panel.php?tab=deletions" 
                           class="nav-link <?php echo $tab === 'deletions' ? 'active' : ''; ?>">
                            <i class="bi bi-trash me-2"></i>Удаления постов
                        </a>
                        <a href="admin_panel.php?tab=forbidden_words" 
                           class="nav-link <?php echo $tab === 'forbidden_words' ? 'active' : ''; ?>">
                            <i class="bi bi-ban me-2"></i>Запрещённые слова
                        </a>
                        <a href="admin_panel.php?tab=users" 
                           class="nav-link <?php echo $tab === 'users' ? 'active' : ''; ?>">
                            <i class="bi bi-people me-2"></i>Управление пользователями
                        </a>
                        <a href="admin_panel.php?tab=activity" 
                           class="nav-link <?php echo $tab === 'activity' ? 'active' : ''; ?>">
                            <i class="bi bi-clock-history me-2"></i>История действий
                        </a>
                        <a href="admin_panel.php?tab=complaints" 
                           class="nav-link <?php echo $tab === 'complaints' ? 'active' : ''; ?>">
                            <i class="bi bi-exclamation-circle me-2"></i>Жалобы
                            <?php if ($pendingComplaints > 0): ?>
                                <span class="badge bg-danger ms-2"><?= $pendingComplaints ?></span>
                            <?php endif; ?>
                        </a>
                    </nav>
                </div>
            </div>

            <!-- Основной контент -->
            <div class="col-md-9">
                <div class="content p-4">
                    <!-- ТАБЛИЦА УПРАВЛЕНИЯ (DASHBOARD) -->
                    <?php if ($tab === 'dashboard'): ?>
                        <h2 class="mb-4">
                            <i class="fas fa-chart-line"></i> Панель управления
                        </h2>

                        <div class="row mb-4">
                            <?php
                            // Получаем статистику
                            $hiddenPostsCount = $conn->query("SELECT COUNT(*) as count FROM posts WHERE hidden = 1")->fetch(PDO::FETCH_ASSOC)['count'];
                            $markedPostsCount = $conn->query("SELECT COUNT(*) as count FROM post_deletion_marks WHERE deleted_at IS NULL")->fetch(PDO::FETCH_ASSOC)['count'];
                            $forbiddenWordsCount = $forbiddenWords->getTotalCount();
                            $actionsTodayCount = $conn->query("SELECT COUNT(*) as count FROM admin_actions WHERE DATE(created_at) = CURDATE()")->fetch(PDO::FETCH_ASSOC)['count'];
                            ?>

                            <div class="col-md-6 col-lg-3">
                                <div class="stat-card danger">
                                    <div class="stat-value"><?php echo $hiddenPostsCount; ?></div>
                                    <div class="stat-label">Скрытых постов</div>
                                </div>
                            </div>

                            <div class="col-md-6 col-lg-3">
                                <div class="stat-card warning">
                                    <div class="stat-value"><?php echo $markedPostsCount; ?></div>
                                    <div class="stat-label">Отмечено на удаление</div>
                                </div>
                            </div>

                            <div class="col-md-6 col-lg-3">
                                <div class="stat-card">
                                    <div class="stat-value"><?php echo $forbiddenWordsCount; ?></div>
                                    <div class="stat-label">Запрещённых слов</div>
                                </div>
                            </div>

                            <div class="col-md-6 col-lg-3">
                                <div class="stat-card success">
                                    <div class="stat-value"><?php echo $actionsTodayCount; ?></div>
                                    <div class="stat-label">Действий сегодня</div>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <h4 class="mb-3">Активность по типам действий (7 дней)</h4>

                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>Тип действия</th>
                                        <th>Количество</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $stats = $adminManager->getActionStats(7);
                                    if (!empty($stats)):
                                        foreach ($stats as $stat):
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($stat['action_type']); ?></td>
                                            <td><span class="badge bg-primary"><?php echo $stat['count']; ?></span></td>
                                        </tr>
                                    <?php
                                        endforeach;
                                    endif;
                                    ?>
                                </tbody>
                            </table>
                        </div>

                        <hr class="my-4">

                        <h4 class="mb-3">Активность модераторов (7 дней)</h4>

                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>Модератор</th>
                                        <th>Действий</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $adminStats = $adminManager->getAdminStats(7);
                                    if (!empty($adminStats)):
                                        foreach ($adminStats as $admin):
                                    ?>
                                        <tr>
                                            <td>
                                                <i class="fas fa-user"></i>
                                                <?php echo htmlspecialchars($admin['username']); ?>
                                            </td>
                                            <td><span class="badge bg-info"><?php echo $admin['action_count']; ?></span></td>
                                        </tr>
                                    <?php
                                        endforeach;
                                    endif;
                                    ?>
                                </tbody>
                            </table>
                        </div>

                    <!-- ВКЛАДКА СКРЫТЫХ ПОСТОВ -->
                    <?php elseif ($tab === 'hidden_posts'): ?>
                        <h2 class="mb-4">
                            <i class="fas fa-eye-slash"></i> Скрытые посты
                        </h2>

                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Автор</th>
                                        <th>Содержание</th>
                                        <th>Тема</th>
                                        <th>Причина скрытия</th>
                                        <th>Скрыто</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $hiddenStmt = $conn->prepare("
                                        SELECT p.id, p.author_id, p.content, p.topic_id, p.hidden_reason, p.hidden_at, 
                                               u.username, t.title 
                                        FROM posts p 
                                        JOIN users u ON p.author_id = u.id 
                                        JOIN topics t ON p.topic_id = t.id 
                                        WHERE p.hidden = 1 AND p.deleted = 0
                                        ORDER BY p.hidden_at DESC
                                    ");
                                    $hiddenStmt->execute();
                                    $hiddenPosts = $hiddenStmt->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    foreach ($hiddenPosts as $hp):
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($hp['id']) ?></td>
                                        <td><?= htmlspecialchars($hp['username']) ?></td>
                                        <td><?= mb_substr(htmlspecialchars(strip_tags($hp['content'])), 0, 50) ?>...</td>
                                        <td><?= htmlspecialchars($hp['title']) ?></td>
                                        <td><?= htmlspecialchars($hp['hidden_reason'] ?? 'Не указана') ?></td>
                                        <td><?= htmlspecialchars($hp['hidden_at']) ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <!-- Кнопка Открыть (убирает скрытие) -->
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="unhide_from_admin">
                                                    <input type="hidden" name="post_id" value="<?= $hp['id'] ?>">
                                                    <button type="submit" class="btn btn-success btn-sm table-action-btn" title="Открыть пост (убрать скрытие)">
                                                        <i class="fas fa-lock-open"></i> Открыть
                                                    </button>
                                                </form>
                                                
                                                <!-- Кнопка Показать (навигирует к посту) -->
                                                <a href="topic.php?id=<?= $hp['topic_id'] ?>#post-<?= $hp['id'] ?>" class="btn btn-info btn-sm table-action-btn" title="Перейти к посту">
                                                    <i class="fas fa-eye"></i> Показать
                                                </a>
                                                
                                                <!-- Кнопка Удалить с причиной -->
                                                <button type="button" class="btn btn-danger btn-sm table-action-btn" 
                                                        onclick="showDeleteModal(<?= $hp['id'] ?>, '<?= htmlspecialchars($hp['title'] ?? '', ENT_QUOTES) ?>')"
                                                        title="Удалить пост">
                                                    <i class="fas fa-trash"></i> Удалить
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    <!-- ВКЛАДКА УДАЛЕНИЙ -->
                    <?php elseif ($tab === 'deletions'): ?>
                        <h2 class="mb-4">
                            <i class="fas fa-trash"></i> Управление удалениями 
                        </h2>

                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Пост</th>
                                        <th>Автор</th>
                                        <th>Причина</th>
                                        <th>Статус</th>
                                        <th>Метки</th>  
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $deletions = $postModeration->getActiveDeletionMarks(50);
                                    if (!empty($deletions)):
                                        foreach ($deletions as $deletion):
                                    ?>
                                        <tr>
                                            <td><?php echo $deletion['post_id']; ?></td>
                                            <td>
                                                <small><?php echo htmlspecialchars(substr($deletion['content'], 0, 30)) . '...'; ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($deletion['username']); ?></td>
                                            <td><?php echo htmlspecialchars($deletion['reason']); ?></td>
                                            <td>
                                                <?php if ($deletion['hidden']): ?>
                                                    <span class="badge bg-danger">Скрыт</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning">Отмечен</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($deletion['scheduled_delete_at']): ?>
                                                    <small class="text-muted">Запланировано: <?php echo substr($deletion['scheduled_delete_at'], 0, 10); ?></small>
                                                <?php else: ?>
                                                    <small class="text-muted">Ручное удаление</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="unhide_post">
                                                    <input type="hidden" name="post_id" value="<?php echo $deletion['post_id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-success table-action-btn">
                                                        <i class="fas fa-eye"></i> Восстановить
                                                    </button>
                                                </form>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="delete_post">
                                                    <input type="hidden" name="post_id" value="<?php echo $deletion['post_id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger table-action-btn" 
                                                            onclick="return confirm('Окончательно удалить пост?')">
                                                        <i class="fas fa-check"></i> Удалить
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php
                                        endforeach;
                                    else:
                                    ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">
                                                Нет отмеченных или скрытых постов
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        
                        

                    <!-- ВКЛАДКА ЗАПРЕЩЁННЫХ СЛОВ -->
                    <?php elseif ($tab === 'forbidden_words'): ?>
                        <h2 class="mb-4">
                            <i class="fas fa-ban"></i> Управление запрещёнными словами
                        </h2>

                        <div class="card mb-4">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">Добавить новое слово</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="admin_panel.php?tab=forbidden_words&action=add_forbidden_word">
                                    <div class="input-group">
                                        <input type="text" name="word" class="form-control" placeholder="Введите запрещённое слово...">
                                        <button type="submit" class="btn btn-danger">
                                            <i class="fas fa-plus"></i> Добавить
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Слово</th>
                                        <th>Добавлено</th>
                                        <th>Добавил</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $words = $forbiddenWords->getAllWords(50);
                                    if (!empty($words)):
                                        foreach ($words as $word):
                                    ?>
                                        <tr>
                                            <td><?php echo $word['id']; ?></td>
                                            <td>
                                                <code><?php echo htmlspecialchars($word['word']); ?></code>
                                            </td>
                                            <td><?php echo substr($word['created_at'], 0, 10); ?></td>
                                            <td><?php echo htmlspecialchars($word['created_by_name'] ?? 'Неизвестно'); ?></td>
                                            <td>
                                                <form method="POST" action="admin_panel.php?tab=forbidden_words&action=delete_forbidden_word" style="display: inline;">
                                                    <input type="hidden" name="word_id" value="<?php echo $word['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger table-action-btn"
                                                            onclick="return confirm('Удалить это слово?')">
                                                        <i class="fas fa-trash"></i> Удалить
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php
                                        endforeach;
                                    else:
                                    ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-4">
                                                Запрещённые слова не добавлены
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                    <!-- ВКЛАДКА ПОЛЬЗОВАТЕЛЕЙ -->
                    <?php elseif ($tab === 'users'): ?>
                        <h2 class="mb-4">
                            <i class="fas fa-users"></i> Управление пользователями
                        </h2>

                        <div class="mb-3">
                            <form method="GET" action="admin_panel.php" class="row g-3">
                                <input type="hidden" name="tab" value="users">
                                <div class="col-md-4">
                                    <input type="text" name="username" class="form-control" placeholder="Поиск по имени пользователя..." 
                                           value="<?php echo htmlspecialchars($_GET['username'] ?? ''); ?>">
                                </div>
                                <div class="col-md-3">
                                    <select name="status" class="form-select">
                                        <option value="">Все статусы</option>
                                        <option value="active" <?php echo ($_GET['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Активные</option>
                                        <option value="banned" <?php echo ($_GET['status'] ?? '') === 'banned' ? 'selected' : ''; ?>>Заблокированные</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search"></i> Поиск
                                    </button>
                                </div>
                            </form>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Пользователь</th>
                                        <th>Email</th>
                                        <th>Роль</th>
                                        <th>Статус</th>
                                        <th>Дата регистрации</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $filters = [];
                                    if (!empty($_GET['username'])) $filters['username'] = $_GET['username'];
                                    if (!empty($_GET['status'])) $filters['status'] = $_GET['status'];
                                    
                                    $users = $adminManager->getUsersWithBanInfo(50, 0, $filters);
                                    if (!empty($users)):
                                        foreach ($users as $u):
                                            $ban_info = $userClass->getBanInfo($u['id']);
                                    ?>
                                        <tr>
                                            <td><?php echo $u['id']; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($u['username']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($u['email'] ?? ''); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $u['user_role'] === 'admin' ? 'danger' : ($u['user_role'] === 'moderator' ? 'warning' : 'secondary'); ?>">
                                                    <?php echo $u['user_role'] === 'admin' ? 'Админ' : ($u['user_role'] === 'moderator' ? 'Модератор' : 'Пользователь'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($ban_info['banned']): ?>
                                                    <span class="badge bg-danger">
                                                        <?php if ($ban_info['permanent']): ?>
                                                            Заблокирован
                                                        <?php else: ?>
                                                            До <?php echo date('d.m.Y H:i', strtotime($ban_info['ban_until'])); ?>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">Активен</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo substr($u['created_at'], 0, 10); ?></td>
                                            <td>
                                                <?php if ($u['user_role'] !== 'admin' && $current_user['user_role'] === 'admin'): ?>
                                                    <?php if ($ban_info['banned']): ?>
                                                        <button type="button" class="btn btn-success btn-sm" 
                                                                onclick="unbanUser(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username']); ?>')">
                                                            <i class="fas fa-unlock"></i> Разблокировать
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" class="btn btn-warning btn-sm" 
                                                                onclick="showBanModal(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username']); ?>')">
                                                            <i class="fas fa-ban"></i> Заблокировать
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php
                                        endforeach;
                                    else:
                                    ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">
                                                Пользователи не найдены
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Модальное окно для блокировки -->
                        <div class="modal fade" id="banModal" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Заблокировать пользователя</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST" action="admin_panel.php?tab=users&action=ban_user">
                                        <div class="modal-body">
                                            <input type="hidden" name="user_id" id="banUserId">
                                            <p>Заблокировать пользователя: <strong id="banUsername"></strong></p>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Срок блокировки</label>
                                                <select name="duration" class="form-select" required>
                                                    <option value="1h">1 час</option>
                                                    <option value="1d">1 день</option>
                                                    <option value="7d">7 дней</option>
                                                    <option value="30d">30 дней</option>
                                                    <option value="permanent">Навсегда</option>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Причина блокировки</label>
                                                <textarea name="reason" class="form-control" rows="3" placeholder="Укажите причину блокировки..."></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                                            <button type="submit" class="btn btn-danger">Заблокировать</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Модальное окно для разблокировки -->
                        <div class="modal fade" id="unbanModal" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Разблокировать пользователя</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST" action="admin_panel.php?tab=users&action=unban_user">
                                        <div class="modal-body">
                                            <input type="hidden" name="user_id" id="unbanUserId">
                                            <p>Разблокировать пользователя: <strong id="unbanUsername"></strong></p>
                                            <p class="text-muted">Пользователь сможет снова создавать контент и входить в систему.</p>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                                            <button type="submit" class="btn btn-success">Разблокировать</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Модальное окно для удаления поста -->
                        <div class="modal fade" id="deleteModal" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Удалить пост</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST" id="deleteForm" action="admin_panel.php?tab=hidden_posts&action=delete_from_admin">
                                        <div class="modal-body">
                                            <input type="hidden" name="action" value="delete_from_admin">
                                            <input type="hidden" name="post_id" id="deletePostId">
                                            <p>Удалить пост: <strong id="deletePostTitle"></strong></p>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Причина удаления</label>
                                                <textarea name="delete_reason" id="deleteReason" class="form-control" rows="3" placeholder="Укажите причину удаления для уведомления автора..."></textarea>
                                            </div>
                                            
                                            <div class="alert alert-warning">
                                                <i class="fas fa-exclamation-triangle me-2"></i>
                                                Это действие необратимо. Автор получит уведомление с указанной причиной.
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                                            <button type="button" class="btn btn-danger" onclick="deletePost()">Удалить пост</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                    <!-- ВКЛАДКА ИСТОРИИ -->
                    <?php elseif ($tab === 'activity'): ?>
                        <h2 class="mb-4">
                            <i class="fas fa-history"></i> История модерации (последние 90 дней)
                        </h2>

                        <!-- Фильтры -->
                        <div class="card mb-4">
                            <div class="card-body">
                                <form method="GET" class="row g-3">
                                    <input type="hidden" name="tab" value="activity">
                                    
                                    <div class="col-md-3">
                                        <label class="form-label">Модератор</label>
                                        <select name="moderator_id" class="form-select form-select-sm">
                                            <option value="">Все</option>
                                            <?php
                                            $moderators = $conn->query("SELECT id, username FROM users WHERE user_role IN ('admin', 'moderator') ORDER BY username");
                                            while ($mod = $moderators->fetch(PDO::FETCH_ASSOC)) {
                                                $selected = ($_GET['moderator_id'] ?? '') == $mod['id'] ? 'selected' : '';
                                                echo "<option value='{$mod['id']}' {$selected}>{$mod['username']}</option>";
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <label class="form-label">Действие</label>
                                        <select name="action" class="form-select form-select-sm">
                                            <option value="">Все</option>
                                            <option value="ban" <?php echo ($_GET['action'] ?? '') === 'ban' ? 'selected' : ''; ?>>Бан</option>
                                            <option value="unban" <?php echo ($_GET['action'] ?? '') === 'unban' ? 'selected' : ''; ?>>Разбан</option>
                                            <option value="post_hide" <?php echo ($_GET['action'] ?? '') === 'post_hide' ? 'selected' : ''; ?>>Скрыть пост</option>
                                            <option value="post_unhide" <?php echo ($_GET['action'] ?? '') === 'post_unhide' ? 'selected' : ''; ?>>Показать пост</option>
                                            <option value="post_delete" <?php echo ($_GET['action'] ?? '') === 'post_delete' ? 'selected' : ''; ?>>Удалить пост</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <label class="form-label">Тип цели</label>
                                        <select name="target_type" class="form-select form-select-sm">
                                            <option value="">Все</option>
                                            <option value="user" <?php echo ($_GET['target_type'] ?? '') === 'user' ? 'selected' : ''; ?>>Пользователь</option>
                                            <option value="post" <?php echo ($_GET['target_type'] ?? '') === 'post' ? 'selected' : ''; ?>>Пост</option>
                                            <option value="comment" <?php echo ($_GET['target_type'] ?? '') === 'comment' ? 'selected' : ''; ?>>Комментарий</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <label class="form-label">С даты</label>
                                        <input type="date" name="from_date" class="form-control form-control-sm" 
                                               value="<?php echo $_GET['from_date'] ?? ''; ?>">
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <label class="form-label">По дату</label>
                                        <input type="date" name="to_date" class="form-control form-control-sm" 
                                               value="<?php echo $_GET['to_date'] ?? ''; ?>">
                                    </div>
                                    
                                    <div class="col-md-1 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Дата/Время</th>
                                        <th>Модератор</th>
                                        <th>Действие</th>
                                        <th>Цель</th>
                                        <th>Причина</th>
                                        <th>Длительность</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $filters = [];
                                    if (!empty($_GET['moderator_id'])) $filters['moderator_id'] = $_GET['moderator_id'];
                                    if (!empty($_GET['action'])) $filters['action'] = $_GET['action'];
                                    if (!empty($_GET['target_type'])) $filters['target_type'] = $_GET['target_type'];
                                    if (!empty($_GET['from_date'])) $filters['from_date'] = $_GET['from_date'] . ' 00:00:00';
                                    if (!empty($_GET['to_date'])) $filters['to_date'] = $_GET['to_date'] . ' 23:59:59';
                                    
                                    $logs = $adminManager->getModerationLog(100, 0, $filters);
                                    if (!empty($logs)):
                                        foreach ($logs as $log):
                                    ?>
                                        <tr>
                                            <td><small><?php echo date('d.m.Y H:i', strtotime($log['created_at'])); ?></small></td>
                                            <td><?php echo htmlspecialchars($log['moderator_name'] ?? 'Неизвестен'); ?></td>
                                            <td>
                                                <span class="badge 
                                                    <?php 
                                                    $actionClass = 'bg-secondary';
                                                    switch($log['action']) {
                                                        case 'ban': $actionClass = 'bg-danger'; break;
                                                        case 'unban': $actionClass = 'bg-success'; break;
                                                        case 'post_hide': $actionClass = 'bg-warning'; break;
                                                        case 'post_unhide': $actionClass = 'bg-info'; break;
                                                        case 'post_delete': $actionClass = 'bg-dark'; break;
                                                    }
                                                    echo $actionClass;
                                                    ?>">
                                                    <?php 
                                                    $actionLabel = htmlspecialchars($log['action']);
                                                    switch($log['action']) {
                                                        case 'ban': $actionLabel = 'Бан'; break;
                                                        case 'unban': $actionLabel = 'Разбан'; break;
                                                        case 'post_hide': $actionLabel = 'Скрыть пост'; break;
                                                        case 'post_unhide': $actionLabel = 'Показать пост'; break;
                                                        case 'post_delete': $actionLabel = 'Удалить пост'; break;
                                                    }
                                                    echo $actionLabel;
                                                    ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small>
                                                    <?php 
                                                    if ($log['target_type'] === 'user') {
                                                        echo 'Пользователь: ' . htmlspecialchars($log['target_username'] ?? 'ID:' . $log['target_id']);
                                                    } elseif ($log['target_type'] === 'post') {
                                                        echo 'Пост: ' . htmlspecialchars(substr($log['post_title'] ?? '', 0, 30)) . '...';
                                                    } elseif ($log['target_type'] === 'comment') {
                                                        echo 'Комментарий: ' . htmlspecialchars(substr($log['comment_content'] ?? '', 0, 30)) . '...';
                                                    } else {
                                                        echo htmlspecialchars($log['target_type']) . ' #' . $log['target_id'];
                                                    }
                                                    ?>
                                                </small>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars(substr($log['reason'] ?? '', 0, 50)); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($log['duration'] ?? '-'); ?></small>
                                            </td>
                                        </tr>
                                    <?php
                                        endforeach;
                                    else:
                                    ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">
                                                История модерации пуста
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                    <!-- ВКЛАДКА ЖАЛОБ -->
                    <?php elseif ($tab === 'complaints'): ?>
                        <h2 class="mb-4">
                            <i class="bi bi-exclamation-circle me-2"></i>Жалобы
                        </h2>

                        <!-- Фильтры по типам -->
                        <ul class="nav nav-tabs mb-4" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="all-tab" data-bs-toggle="tab" data-bs-target="#all-complaints" type="button">
                                    Все жалобы <?php if ($pendingComplaints > 0): ?><span class="badge bg-danger ms-2"><?= $pendingComplaints ?></span><?php endif; ?>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="post-tab" data-bs-toggle="tab" data-bs-target="#post-complaints" type="button">
                                    На сообщения <?php if ($postComplaints > 0): ?><span class="badge bg-warning ms-2"><?= $postComplaints ?></span><?php endif; ?>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="topic-tab" data-bs-toggle="tab" data-bs-target="#topic-complaints" type="button">
                                    На темы <?php if ($topicComplaints > 0): ?><span class="badge bg-info ms-2"><?= $topicComplaints ?></span><?php endif; ?>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="user-tab" data-bs-toggle="tab" data-bs-target="#user-complaints" type="button">
                                    На пользователей <?php if ($userComplaints > 0): ?><span class="badge bg-danger ms-2"><?= $userComplaints ?></span><?php endif; ?>
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content">
                            <!-- Все жалобы -->
                            <div class="tab-pane fade show active" id="all-complaints" role="tabpanel">
                                <?php
                                $complaintsList = $complaint->getAllPending()->fetchAll(PDO::FETCH_ASSOC);
                                if (!empty($complaintsList)):
                                ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Тип</th>
                                                    <th>Содержание</th>
                                                    <th>Причина</th>
                                                    <th>Жалующийся</th>
                                                    <th>Дата</th>
                                                    <th>Действия</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($complaintsList as $c): ?>
                                                <tr>
                                                    <td>
                                                        <?php if ($c['post_id']): ?>
                                                            <span class="badge bg-warning text-dark">Сообщение</span>
                                                        <?php elseif ($c['topic_id']): ?>
                                                            <span class="badge bg-info text-dark">Тема</span>
                                                        <?php elseif ($c['user_id']): ?>
                                                            <span class="badge bg-danger">Пользователь</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($c['post_id']): ?>
                                                            <small><?= htmlspecialchars(mb_substr($c['reason'], 0, 50)) ?>...</small>
                                                        <?php elseif ($c['topic_id']): ?>
                                                            <a href="../topic.php?id=<?= $c['topic_id'] ?>" target="_blank" class="text-decoration-none"><?= htmlspecialchars($c['topic_title']) ?></a>
                                                        <?php elseif ($c['user_id']): ?>
                                                            <a href="../home/profile.php?id=<?= $c['user_id'] ?>" target="_blank" class="text-decoration-none"><?= htmlspecialchars($c['reported_user']) ?></a>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><small><?= htmlspecialchars($c['reason']) ?></small></td>
                                                    <td><?= htmlspecialchars($c['complainant_username'] ?? 'Анонимно') ?></td>
                                                    <td><small><?= date('d.m.Y H:i', strtotime($c['created_at'])) ?></small></td>
                                                    <td>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="resolve_complaint">
                                                            <input type="hidden" name="complaint_id" value="<?= $c['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-success">Разрешить</button>
                                                        </form>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="reject_complaint">
                                                            <input type="hidden" name="complaint_id" value="<?= $c['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">Отклонить</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info">Нет новых жалоб</div>
                                <?php endif; ?>
                            </div>

                            <!-- Жалобы на сообщения -->
                            <div class="tab-pane fade" id="post-complaints" role="tabpanel">
                                <?php
                                $postComplaintsList = $complaint->getByType('post')->fetchAll(PDO::FETCH_ASSOC);
                                if (!empty($postComplaintsList)):
                                ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Сообщение</th>
                                                    <th>Причина</th>
                                                    <th>Жалующийся</th>
                                                    <th>Дата</th>
                                                    <th>Действия</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($postComplaintsList as $c): ?>
                                                <tr>
                                                    <td><small><?= htmlspecialchars(mb_substr(strip_tags($c['reason']), 0, 50)) ?>...</small></td>
                                                    <td><?= htmlspecialchars($c['reason']) ?></td>
                                                    <td><?= htmlspecialchars($c['complainant_username'] ?? 'Анонимно') ?></td>
                                                    <td><small><?= date('d.m.Y H:i', strtotime($c['created_at'])) ?></small></td>
                                                    <td>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="resolve_complaint">
                                                            <input type="hidden" name="complaint_id" value="<?= $c['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-success">Разрешить</button>
                                                        </form>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="reject_complaint">
                                                            <input type="hidden" name="complaint_id" value="<?= $c['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">Отклонить</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info">Нет жалоб на сообщения</div>
                                <?php endif; ?>
                            </div>

                            <!-- Жалобы на темы -->
                            <div class="tab-pane fade" id="topic-complaints" role="tabpanel">
                                <?php
                                $topicComplaintsList = $complaint->getByType('topic')->fetchAll(PDO::FETCH_ASSOC);
                                if (!empty($topicComplaintsList)):
                                ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Тема</th>
                                                    <th>Причина</th>
                                                    <th>Жалующийся</th>
                                                    <th>Дата</th>
                                                    <th>Действия</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($topicComplaintsList as $c): ?>
                                                <tr>
                                                    <td><a href="../topic.php?id=<?= $c['topic_id'] ?>" target="_blank"><?= htmlspecialchars($c['topic_title']) ?></a></td>
                                                    <td><?= htmlspecialchars($c['reason']) ?></td>
                                                    <td><?= htmlspecialchars($c['complainant_username'] ?? 'Анонимно') ?></td>
                                                    <td><small><?= date('d.m.Y H:i', strtotime($c['created_at'])) ?></small></td>
                                                    <td>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="resolve_complaint">
                                                            <input type="hidden" name="complaint_id" value="<?= $c['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-success">Разрешить</button>
                                                        </form>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="reject_complaint">
                                                            <input type="hidden" name="complaint_id" value="<?= $c['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">Отклонить</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info">Нет жалоб на темы</div>
                                <?php endif; ?>
                            </div>

                            <!-- Жалобы на пользователей -->
                            <div class="tab-pane fade" id="user-complaints" role="tabpanel">
                                <?php
                                $userComplaintsList = $complaint->getByType('user')->fetchAll(PDO::FETCH_ASSOC);
                                if (!empty($userComplaintsList)):
                                ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Пользователь</th>
                                                    <th>Причина</th>
                                                    <th>Жалующийся</th>
                                                    <th>Дата</th>
                                                    <th>Действия</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($userComplaintsList as $c): ?>
                                                <tr>
                                                    <td><a href="../home/profile.php?id=<?= $c['user_id'] ?>" target="_blank"><?= htmlspecialchars($c['reported_user']) ?></a></td>
                                                    <td><?= htmlspecialchars($c['reason']) ?></td>
                                                    <td><?= htmlspecialchars($c['complainant_username'] ?? 'Анонимно') ?></td>
                                                    <td><small><?= date('d.m.Y H:i', strtotime($c['created_at'])) ?></small></td>
                                                    <td>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="resolve_complaint">
                                                            <input type="hidden" name="complaint_id" value="<?= $c['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-success">Разрешить</button>
                                                        </form>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="reject_complaint">
                                                            <input type="hidden" name="complaint_id" value="<?= $c['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">Отклонить</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info">Нет жалоб на пользователей</div>
                                <?php endif; ?>
                            </div>
                        </div>

                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showBanModal(userId, username) {
            document.getElementById('banUserId').value = userId;
            document.getElementById('banUsername').textContent = username;
            new bootstrap.Modal(document.getElementById('banModal')).show();
        }
        
        function unbanUser(userId, username) {
            document.getElementById('unbanUserId').value = userId;
            document.getElementById('unbanUsername').textContent = username;
            new bootstrap.Modal(document.getElementById('unbanModal')).show();
        }
        
        function showDeleteModal(postId, postTitle) {
            document.getElementById('deletePostId').value = postId;
            document.getElementById('deletePostTitle').textContent = postTitle.substring(0, 30);
            document.getElementById('deleteReason').value = 'Пост нарушает правила сообщества';
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
        
        function deletePost() {
            document.getElementById('deleteForm').submit();
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
