<?php
/**
 * API для работы с модерацией
 * Используется для AJAX запросов
 */

// Явно устанавливаем JSON header перед любым другим выводом
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Обработчики ошибок для правильного вывода JSON при ошибках
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8', true);
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка сервера: ' . $errstr]);
    exit;
});

set_exception_handler(function($e) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8', true);
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка: ' . $e->getMessage()]);
    exit;
});

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/PostModeration.php';
require_once __DIR__ . '/../classes/ForbiddenWords.php';
require_once __DIR__ . '/../classes/AutoModerator.php';
require_once __DIR__ . '/../classes/NotificationManager.php';

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}

$db = new Database();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

$userClass = new User($conn);
$current_user = $userClass->getById($user_id);

$PostModeration = new PostModeration($conn);
$ForbiddenWords = new ForbiddenWords($conn);
$AutoModerator = new AutoModerator($conn, $PostModeration, $ForbiddenWords);
$NotificationManager = new NotificationManager($conn);

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['error' => 'Неизвестное действие'];

switch ($action) {
    case 'check_content':
        // Проверить содержимое на запрещённые слова
        $content = $_POST['content'] ?? '';
        if ($content) {
            $result = $ForbiddenWords->checkContentDetailed($content);
            $response = [
                'has_forbidden' => $result['has_forbidden'],
                'violations' => $result['words_found'],
                'total_occurrences' => $result['total_occurrences']
            ];
        }
        break;

    case 'hide_post':
        // Скрыть пост (только админы/модераторы)
        if ($current_user['user_role'] !== 'admin' && $current_user['user_role'] !== 'moderator') {
            http_response_code(403);
            $response = ['error' => 'Недостаточно прав'];
            echo json_encode($response);
            exit;
        }
        
        $post_id = $_POST['post_id'] ?? 0;
        $reason = $_POST['reason'] ?? 'Скрыто администратором';
        
        if ($PostModeration->hidePost($post_id, $reason, $user_id)) {
            // Получить автора поста и отправить уведомление
            $postStmt = $conn->prepare("SELECT author_id FROM posts WHERE id = :post_id");
            $postStmt->execute([':post_id' => $post_id]);
            $post = $postStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($post && $post['author_id'] != $user_id) {
                // Отправить уведомление автору поста
                $notificationManager = new NotificationManager($conn);
                $notificationManager->create(
                    $post['author_id'],
                    'post_hidden',
                    'Ваш пост был скрыт модератором. Причина: ' . $reason
                );
            }
            
            $response = ['success' => true, 'message' => 'Пост скрыт'];
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode($response);
        } else {
            http_response_code(400);
            $response = ['error' => 'Ошибка при скрытии поста'];
            echo json_encode($response);
        }
        // Перенаправляем назад если это не AJAX
        if (empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            $sql = "SELECT topic_id FROM posts WHERE id = :post_id";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':post_id', $post_id);
            $stmt->execute();
            $post = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($post) {
                header('Location: topic.php?id=' . $post['topic_id']);
                exit;
            }
        }
        exit;

    case 'unhide_post':
        // Восстановить пост (только админы/модераторы)
        if ($current_user['user_role'] !== 'admin' && $current_user['user_role'] !== 'moderator') {
            http_response_code(403);
            $response = ['error' => 'Недостаточно прав'];
            echo json_encode($response);
            exit;
        }
        
        $post_id = $_POST['post_id'] ?? 0;
        
        if ($PostModeration->unhidePost($post_id)) {
            $response = ['success' => true, 'message' => 'Пост восстановлен'];
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode($response);
        } else {
            http_response_code(400);
            $response = ['error' => 'Ошибка при восстановлении поста'];
            echo json_encode($response);
        }
        exit;

    case 'mark_for_deletion':
        // Отметить пост на удаление (только админы/модераторы)
        if ($current_user['user_role'] !== 'admin' && $current_user['user_role'] !== 'moderator') {
            http_response_code(403);
            $response = ['error' => 'Недостаточно прав'];
            echo json_encode($response);
            exit;
        }
        
        $post_id = $_POST['post_id'] ?? 0;
        $hours = intval($_POST['hours'] ?? 0);
        $reason = $_POST['reason'] ?? 'Отмечено на удаление';
        
        // Если часов не указано или чекбокс не отмечен - просто отметить без удаления
        $scheduled_delete_str = null;
        if ($hours > 0) {
            $scheduled_delete = new DateTime();
            $scheduled_delete->add(new DateInterval('PT' . $hours . 'H'));
            $scheduled_delete_str = $scheduled_delete->format('Y-m-d H:i:s');
        }
        
        if ($PostModeration->markForDeletion($post_id, $user_id, $reason, false, $scheduled_delete_str)) {
            $response = ['success' => true, 'message' => 'Пост отмечен на удаление'];
            header('Content-Type: application/json');
            echo json_encode($response);
        } else {
            http_response_code(400);
            $response = ['error' => 'Ошибка при отметке на удаление'];
            echo json_encode($response);
        }
        // Перенаправляем назад если это не AJAX
        if (empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            $sql = "SELECT topic_id FROM posts WHERE id = :post_id";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':post_id', $post_id);
            $stmt->execute();
            $post = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($post) {
                header('Location: topic.php?id=' . $post['topic_id']);
                exit;
            }
        }
        exit;

    case 'delete_post':
        // Удалить пост окончательно (только админы/модераторы)
        if ($current_user['user_role'] !== 'admin' && $current_user['user_role'] !== 'moderator') {
            http_response_code(403);
            $response = ['error' => 'Недостаточно прав'];
            echo json_encode($response);
            exit;
        }
        
        $post_id = $_POST['post_id'] ?? 0;
        
        if ($PostModeration->deletePost($post_id, $user_id)) {
            $response = ['success' => true, 'message' => 'Пост удалён'];
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode($response);
        } else {
            http_response_code(400);
            $response = ['error' => 'Ошибка при удалении поста'];
            echo json_encode($response);
        }
        // Перенаправляем на главную если это не AJAX
        if (empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Location: index.php');
            exit;
        }
        exit;

    case 'delete_post_now':
        // Удалить пост сразу из модерационного окна (при нажатии кнопки "Удалить сейчас")
        if ($current_user['user_role'] !== 'admin' && $current_user['user_role'] !== 'moderator') {
            http_response_code(403);
            $response = ['error' => 'Недостаточно прав'];
            echo json_encode($response);
            exit;
        }
        
        $post_id = $_POST['post_id'] ?? 0;
        
        // Получить тему поста для перенаправления
        $topicStmt = $conn->prepare("SELECT topic_id FROM posts WHERE id = :post_id");
        $topicStmt->execute([':post_id' => $post_id]);
        $post = $topicStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($PostModeration->deletePost($post_id, $user_id)) {
            $response = ['success' => true, 'message' => 'Пост удалён сразу'];
            header('Content-Type: application/json');
            echo json_encode($response);
            
            // Перенаправление происходит на фронте после успешного ответа
            if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $post) {
                header('Location: topic.php?id=' . $post['topic_id']);
                exit;
            }
        } else {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Ошибка при удалении поста']);
        }
        exit;

    case 'get_notifications':
        // Получить непрочитанные уведомления пользователя
        $limit = $_GET['limit'] ?? 10;
        $notifications = $NotificationManager->getUnread($user_id, $limit);
        $response = [
            'notifications' => $notifications,
            'unread_count' => $NotificationManager->getUnreadCount($user_id)
        ];
        break;

    case 'mark_notification_read':
        // Отметить уведомление прочитанным
        $notification_id = $_POST['notification_id'] ?? 0;
        if ($NotificationManager->markAsRead($notification_id)) {
            $response = ['success' => true];
        } else {
            http_response_code(400);
            $response = ['error' => 'Ошибка при отметке уведомления'];
        }
        break;

    case 'delete_notification':
        // Удалить уведомление
        $notification_id = $_POST['notification_id'] ?? 0;
        if ($NotificationManager->delete($notification_id)) {
            $response = ['success' => true];
        } else {
            http_response_code(400);
            $response = ['error' => 'Ошибка при удалении уведомления'];
        }
        break;

    case 'get_notification_bell':
        // Получить HTML для иконки уведомлений
        $unread_count = $NotificationManager->getUnreadCount($user_id);
        $response = [
            'unread_count' => $unread_count,
            'bell_html' => $NotificationManager->getNotificationBellHtml($user_id)
        ];
        break;

    case 'get_moderation_stats':
        // Получить статистику модерации (только админы)
        if ($current_user['user_role'] !== 'admin') {
            http_response_code(403);
            $response = ['error' => 'Недостаточно прав'];
        } else {
            $days = $_GET['days'] ?? 7;
            $stats = $AutoModerator->getModerationStats($days);
            $response = ['stats' => $stats];
        }
        break;

    case 'cleanup_notifications':
        // Очистить старые уведомления (cron job)
        if ($current_user['user_role'] !== 'admin') {
            http_response_code(403);
            $response = ['error' => 'Недостаточно прав'];
        } else {
            $days = $_POST['days'] ?? 30;
            if ($NotificationManager->cleanupOld($days)) {
                $response = ['success' => true, 'message' => 'Уведомления очищены'];
            }
        }
        break;

    default:
        http_response_code(400);
        $response = ['error' => 'Неизвестное действие: ' . $action];
}

// Очищаем буфер вывода и отправляем JSON
ob_end_clean();
echo json_encode($response);
