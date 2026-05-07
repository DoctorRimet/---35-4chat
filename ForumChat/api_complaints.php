<?php

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Complaint.php';
require_once __DIR__ . '/classes/Post.php';
require_once __DIR__ . '/classes/Topic.php';
require_once __DIR__ . '/classes/User.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Требуется авторизация']);
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$complaint = new Complaint($conn);
$postModel = new Post($conn);
$topicModel = new Topic($conn);
$userModel = new User($conn);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$complainant_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($action) {
        case 'report_post':
            $post_id = $_POST['post_id'] ?? 0;
            $reason = trim($_POST['reason'] ?? '');

            if (!$post_id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Не указано сообщение']);
                exit;
            }

            if (!$reason || mb_strlen($reason) < 5) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Причина должна содержать минимум 5 символов']);
                exit;
            }

            if (mb_strlen($reason) > 500) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Причина не должна превышать 500 символов']);
                exit;
            }

            // Проверяем существование поста
            $post = $postModel->getById($post_id);
            if (!$post) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Сообщение не найдено']);
                exit;
            }

            // Проверяем что пользователь не жаловался на этот пост уже
            $existingStmt = $conn->prepare("SELECT id FROM complaints WHERE post_id = :post_id AND complainant_id = :complainant_id");
            $existingStmt->execute([':post_id' => $post_id, ':complainant_id' => $complainant_id]);
            if ($existingStmt->rowCount() > 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Вы уже пожаловались на это сообщение']);
                exit;
            }

            $complaint->post_id = $post_id;
            $complaint->complainant_id = $complainant_id;
            $complaint->reason = $reason;
            $complaint->status = 'pending';

            if ($complaint->create()) {
                echo json_encode(['success' => true, 'message' => 'Жалоба отправлена модераторам']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Ошибка при отправке жалобы']);
            }
            break;

        case 'report_topic':
            $topic_id = $_POST['topic_id'] ?? 0;
            $reason = trim($_POST['reason'] ?? '');

            if (!$topic_id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Не указана тема']);
                exit;
            }

            if (!$reason || mb_strlen($reason) < 5) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Причина должна содержать минимум 5 символов']);
                exit;
            }

            if (mb_strlen($reason) > 500) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Причина не должна превышать 500 символов']);
                exit;
            }

            // Проверяем существование темы
            $topic = $topicModel->getById($topic_id);
            if (!$topic) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Тема не найдена']);
                exit;
            }

            // Проверяем что пользователь не жаловался на эту тему уже
            $existingStmt = $conn->prepare("SELECT id FROM complaints WHERE topic_id = :topic_id AND complainant_id = :complainant_id");
            $existingStmt->execute([':topic_id' => $topic_id, ':complainant_id' => $complainant_id]);
            if ($existingStmt->rowCount() > 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Вы уже пожаловались на эту тему']);
                exit;
            }

            $complaint->topic_id = $topic_id;
            $complaint->complainant_id = $complainant_id;
            $complaint->reason = $reason;
            $complaint->status = 'pending';

            if ($complaint->create()) {
                echo json_encode(['success' => true, 'message' => 'Жалоба отправлена модераторам']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Ошибка при отправке жалобы']);
            }
            break;

        case 'report_user':
            $user_id = $_POST['user_id'] ?? 0;
            $reason = trim($_POST['reason'] ?? '');

            if (!$user_id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Не указан пользователь']);
                exit;
            }

            if ($user_id == $complainant_id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Вы не можете пожаловаться на себя']);
                exit;
            }

            if (!$reason || mb_strlen($reason) < 5) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Причина должна содержать минимум 5 символов']);
                exit;
            }

            if (mb_strlen($reason) > 500) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Причина не должна превышать 500 символов']);
                exit;
            }

            // Проверяем существование пользователя
            $user = $userModel->getById($user_id);
            if (!$user) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Пользователь не найден']);
                exit;
            }

            // Проверяем что пользователь не жаловался на этого пользователя уже
            $existingStmt = $conn->prepare("SELECT id FROM complaints WHERE user_id = :user_id AND complainant_id = :complainant_id");
            $existingStmt->execute([':user_id' => $user_id, ':complainant_id' => $complainant_id]);
            if ($existingStmt->rowCount() > 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Вы уже пожаловались на этого пользователя']);
                exit;
            }

            $complaint->user_id = $user_id;
            $complaint->complainant_id = $complainant_id;
            $complaint->reason = $reason;
            $complaint->status = 'pending';

            if ($complaint->create()) {
                echo json_encode(['success' => true, 'message' => 'Жалоба отправлена модераторам']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Ошибка при отправке жалобы']);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Неизвестное действие']);
            break;
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
}
