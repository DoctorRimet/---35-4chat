<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/NotificationManager.php';
require_once __DIR__ . '/../classes/User.php';

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

$notificationManager = new NotificationManager($conn);
$userClass = new User($conn);
$current_user = $userClass->getById($user_id);

// Дополнительные переменные для навигации (как в index.php)
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

// Обработка действий
$action = $_GET['action'] ?? '';

if ($action === 'mark_read') {
    $notification_id = $_POST['notification_id'] ?? 0;
    if ($notification_id) {
        $notificationManager->markAsRead($notification_id);
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
}

if ($action === 'mark_all_read') {
    $notificationManager->markAllAsRead($user_id);
    header('Location: notifications.php');
    exit;
}

if ($action === 'delete') {
    $notification_id = $_POST['notification_id'] ?? 0;
    if ($notification_id) {
        $notificationManager->delete($notification_id);
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
}

if ($action === 'delete_read') {
    $notificationManager->deleteRead($user_id);
    header('Location: notifications.php');
    exit;
}

// Получаем параметры пагинации
$page = $_GET['page'] ?? 1;
$page = max(1, intval($page));
$limit = 15;
$offset = ($page - 1) * $limit;

// Получаем фильтр
$filter = $_GET['filter'] ?? 'all'; // all, unread, read

$notifications = [];
$total_count = 0;

if ($filter === 'unread') {
    $notifications = $notificationManager->getUnread($user_id, 100);
    $total_count = count($notifications);
    $notifications = array_slice($notifications, $offset, $limit);
} elseif ($filter === 'read') {
    $countSql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = :user_id AND read_status = 1";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bindParam(':user_id', $user_id);
    $countStmt->execute();
    $result = $countStmt->fetch(PDO::FETCH_ASSOC);
    $total_count = $result['count'] ?? 0;

    $sql = "SELECT id, user_id, type, message, read_status, created_at FROM notifications 
            WHERE user_id = :user_id AND read_status = 1 
            ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
    $notifStmt = $conn->prepare($sql);
    $notifStmt->bindParam(':user_id', $user_id);
    $notifStmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $notifStmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $notifStmt->execute();
    $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $notifications = $notificationManager->getAll($user_id, $limit, $offset);
    // Получаем общее количество
    $countSql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = :user_id";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bindParam(':user_id', $user_id);
    $countStmt->execute();
    $result = $countStmt->fetch(PDO::FETCH_ASSOC);
    $total_count = $result['count'] ?? 0;
}

$total_pages = ceil($total_count / $limit);
$unread_count = $notificationManager->getUnreadCount($user_id);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Уведомления</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background-color: #f0f2f5; }
        .navbar-brand-icon { width: 36px; height: 36px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; }
        .navbar { box-shadow: 0 1px 8px rgba(0,0,0,.06); }
        .avatar-sm { width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #8b5cf6); display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: .75rem; font-weight: 700; flex-shrink: 0; }
        
        .notification-item {
            transition: background-color 0.3s;
        }
        
        .notification-item:hover {
            background-color: #f8f9fa !important;
        }
        
        .notification-item.unread {
            background-color: #e7f3ff;
            border-left: 4px solid #0d6efd;
        }
        
        .notification-item.read {
            background-color: #f8f9fa;
            border-left: 4px solid #dee2e6;
        }
        
        .badge-notification {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        
        .notification-title {
            font-weight: 500;
            color: #212529;
        }
        
        .notification-message {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .notification-time {
            color: #999;
            font-size: 0.8rem;
        }
        
        .notification-actions {
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .notification-item:hover .notification-actions {
            opacity: 1;
        }
        
        .btn-notification-action {
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #999;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #ddd;
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
                <?php if (!$is_guest) : ?>
                <li class="nav-item">
                    <a class="nav-link active fw-semibold" href="notifications.php">
                        <i class="bi bi-bell me-1"></i>Сообщения
                        <?php if ($unread_count > 0) : ?>
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

<div class="container py-4">
        <div class="row">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">Уведомления</h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="notifications.php?filter=all" 
                           class="list-group-item list-group-item-action <?php echo $filter === 'all' ? 'active' : ''; ?>">
                            <i class="bi bi-inbox me-2"></i>Все уведомления
                            <span class="badge bg-secondary float-end"><?php echo $total_count; ?></span>
                        </a>
                        <a href="notifications.php?filter=unread" 
                           class="list-group-item list-group-item-action <?php echo $filter === 'unread' ? 'active' : ''; ?>">
                            <i class="bi bi-circle-fill me-2"></i>Непрочитанные
                            <span class="badge bg-danger float-end"><?php echo $unread_count; ?></span>
                        </a>
                    </div>
                    
                    <?php if ($unread_count > 0) : ?>
                        <div class="card-body">
                            <form method="GET" action="notifications.php">
                                <input type="hidden" name="action" value="mark_all_read">
                                <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                                    <i class="bi bi-check2-all me-1"></i>Отметить все прочитанными
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                    
                    <div class="card-body">
                        <form method="GET" action="notifications.php">
                            <input type="hidden" name="action" value="delete_read">
                            <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                                <i class="bi bi-trash me-1"></i>Удалить прочитанные
                            </button>
                        </form>
                    </div>
                </div>
                
                <div class="card mt-3">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0">Статистика</h5>
                    </div>
                    <div class="card-body">
                        <?php $stats = $notificationManager->getStats($user_id); ?>
                        <?php if (!empty($stats)) : ?>
                            <div class="small">
                                <?php foreach ($stats as $stat) : ?>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span><?php echo ucfirst(str_replace('_', ' ', $stat['type'])); ?>:</span>
                                        <strong><?php echo $stat['count']; ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <small class="text-muted">Нет данных</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-9">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <?php if ($filter === 'unread') : ?>
                                Непрочитанные уведомления
                            <?php else : ?>
                                Все уведомления
                            <?php endif; ?>
                        </h5>
                    </div>
                    
                    <div class="card-body">
                        <?php if (empty($notifications)) : ?>
                            <div class="empty-state">
                                <i class="bi bi-inbox text-secondary" style="font-size: 3rem;"></i>
                                <p>
                                    <?php if ($filter === 'unread') : ?>
                                        У вас нет непрочитанных уведомлений
                                    <?php else : ?>
                                        У вас нет уведомлений
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php else : ?>
                            <?php foreach ($notifications as $notification) : ?>
                                <div class="notification-item <?php echo $notification['read_status'] ? 'read' : 'unread'; ?> p-3 mb-2 rounded">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <div class="notification-title">
                                                <?php echo getIconHtml($notification['type']); ?>
                                                <?php echo getTypeLabel($notification['type']); ?>
                                            </div>
                                            <div class="notification-message mt-1">
                                                <?php echo htmlspecialchars($notification['message']); ?>
                                            </div>
                                            <div class="notification-time mt-2">
                                                <?php echo getTimeAgo($notification['created_at']); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="notification-actions">
                                            <?php if (!$notification['read_status']) : ?>
                                                <button class="btn btn-sm btn-link" 
                                                        onclick="markAsRead(<?php echo $notification['id']; ?>)"
                                                        title="Отметить как прочитанное">
                                                    <i class="bi bi-check2"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <button class="btn btn-sm btn-link text-danger" 
                                                    onclick="deleteNotification(<?php echo $notification['id']; ?>)"
                                                    title="Удалить">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($total_pages > 1) : ?>
                        <nav aria-label="Page navigation" class="card-footer">
                            <ul class="pagination mb-0">
                                <?php if ($page > 1) : ?>
                                    <li class="page-item">
                                        <a class="page-link" href="notifications.php?page=1&filter=<?php echo $filter; ?>">Первая</a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="notifications.php?page=<?php echo $page - 1; ?>&filter=<?php echo $filter; ?>">Назад</a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++) : ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="notifications.php?page=<?php echo $i; ?>&filter=<?php echo $filter; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages) : ?>
                                    <li class="page-item">
                                        <a class="page-link" href="notifications.php?page=<?php echo $page + 1; ?>&filter=<?php echo $filter; ?>">Далее</a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="notifications.php?page=<?php echo $total_pages; ?>&filter=<?php echo $filter; ?>">Последняя</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function markAsRead(notificationId) {
            fetch('notifications.php?action=mark_read', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'notification_id=' + notificationId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }

        function deleteNotification(notificationId) {
            if (confirm('Удалить это уведомление?')) {
                fetch('notifications.php?action=delete', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'notification_id=' + notificationId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                });
            }
        }
    </script>
</body>
</html>

<?php
// Вспомогательные функции
function getIconHtml($type)
{
    $icons = [
        'new_reply' => '<i class="bi bi-reply text-info"></i>',
        'mention' => '<i class="bi bi-at text-warning"></i>',
        'post_liked' => '<i class="bi bi-heart text-danger"></i>',
        'post_marked_delete' => '<i class="bi bi-exclamation-triangle text-warning"></i>',
        'post_auto_hidden' => '<i class="bi bi-eye-slash text-danger"></i>',
        'post_hidden' => '<i class="bi bi-eye-slash text-warning"></i>',
        'post_deleted' => '<i class="bi bi-trash text-danger"></i>',
        'comment_deleted' => '<i class="bi bi-trash text-danger"></i>',
        'ban' => '<i class="bi bi-ban text-danger"></i>',
        'unban' => '<i class="bi bi-check-circle text-success"></i>',
        'system' => '<i class="bi bi-info-circle text-secondary"></i>'
    ];

    return $icons[$type] ?? '<i class="bi bi-bell text-primary"></i>';
}

function getTypeLabel($type)
{
    $labels = [
        'new_reply' => 'Новый ответ',
        'mention' => 'Упоминание',
        'post_liked' => 'Пост понравился',
        'post_marked_delete' => 'Пост отмечен на удаление',
        'post_auto_hidden' => 'Пост скрыт',
        'post_hidden' => 'Пост скрыт модератором',
        'post_deleted' => 'Пост удален',
        'comment_deleted' => 'Комментарий удален',
        'ban' => 'Блокировка аккаунта',
        'unban' => 'Разблокировка аккаунта',
        'system' => 'Системное уведомление'
    ];

    return $labels[$type] ?? ucfirst($type);
}

function getTimeAgo($datetime)
{
    $now = new DateTime();
    $date = new DateTime($datetime);
    $interval = $now->diff($date);

    if ($interval->d > 0) {
        return $interval->d . ' дн. назад';
    } elseif ($interval->h > 0) {
        return $interval->h . ' ч. назад';
    } elseif ($interval->i > 0) {
        return $interval->i . ' мин. назад';
    } else {
        return 'Только что';
    }
}
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
