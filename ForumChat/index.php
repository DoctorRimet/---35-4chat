<?php
require_once __DIR__ . '/auth/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Topic.php';
require_once __DIR__ . '/classes/Post.php';
require_once __DIR__ . '/classes/User.php';

$db = new Database();
$conn = $db->getConnection();
$topic = new Topic($conn);
$post = new Post($conn);
$user = new User($conn);

$topics_stmt = $topic->getAll();
$topics = $topics_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_topics = count($topics);

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
// Подключаем остальное содержимое из pages/index.php
require_once __DIR__ . '/pages/index.php';



