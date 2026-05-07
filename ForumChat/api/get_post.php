<?php

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Post.php';
require_once __DIR__ . '/../classes/User.php';

header('Content-Type: application/json');

$db = new Database();
$conn = $db->getConnection();

$currentUserId = $_SESSION['user_id'] ?? null;
$currentUserRole = $_SESSION['user_role'] ?? 'user';

$post_id = $_GET['post_id'] ?? 0;

if (!$post_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Post ID required']);
    exit;
}

$postModel = new Post($conn);
$userModel = new User($conn);

$post = $postModel->getById($post_id);

if (!$post) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Post not found']);
    exit;
}

// Проверяем права доступа к скрытому посту
// Доступ имеют: администраторы, модераторы и автор поста
if ($post['hidden']) {
    $canView = false;

    if ($currentUserRole === 'admin' || $currentUserRole === 'moderator') {
        $canView = true;
    } elseif ($currentUserId === $post['author_id']) {
        $canView = true;
    }

    if (!$canView) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied to hidden post']);
        exit;
    }
}

// Получаем автора поста
$author = $userModel->getById($post['author_id']);
if ($author) {
    $authorProfile = $userModel->getProfile($author['id']);
    $author['avatar_url'] = $authorProfile['avatar_url'] ?? $author['avatar_url'] ?? 'https://via.placeholder.com/40';
}

echo json_encode([
    'success' => true,
    'post' => [
        'id' => $post['id'],
        'content' => htmlspecialchars($post['content'], ENT_QUOTES, 'UTF-8'),
        'created_at' => $post['created_at'],
        'hidden' => $post['hidden'],
        'hidden_reason' => $post['hidden_reason'] ?? 'Не указана'
    ],
    'author' => [
        'id' => $author['id'] ?? null,
        'username' => $author['username'] ?? 'Unknown User',
        'avatar_url' => $author['avatar_url'] ?? 'https://via.placeholder.com/40'
    ]
]);
