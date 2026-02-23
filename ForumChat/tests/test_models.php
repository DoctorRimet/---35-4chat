<?php

require_once '../config/database.php';
require_once '../classes/Post.php';
require_once '../classes/User.php';
require_once '../classes/Topic.php';
require_once '../classes/Category.php';
require_once '../classes/Notification.php';

$db = new Database();
$conn = $db->getConnection();

echo "=============================" . PHP_EOL;
echo "ТЕСТИРОВАНИЕ МОДЕЛЕЙ" . PHP_EOL;
echo "=============================" . PHP_EOL;


echo PHP_EOL . "=== TEST: User ===" . PHP_EOL;

$user = new User($conn);
$user->username = "test_user_" . rand(1,9999);
$user->email = "test" . rand(1,9999) . "@mail.com";
$user->password_hash = password_hash("123456", PASSWORD_DEFAULT);

if ($user->create()) {
    echo "CREATE: OK" . PHP_EOL;
} else {
    echo "CREATE: ERROR" . PHP_EOL;
}

$userData = $user->getById($conn->lastInsertId());
echo $userData ? "READ: OK" . PHP_EOL : "READ: ERROR" . PHP_EOL;

$user->id = $conn->lastInsertId();
$user->status = "active";
echo $user->update() ? "UPDATE: OK" . PHP_EOL : "UPDATE: ERROR" . PHP_EOL;

$stmt = $user->getAll();
echo "GET ALL: OK (".$stmt->rowCount().")" . PHP_EOL;

echo $user->delete($user->id) ? "DELETE: OK" . PHP_EOL : "DELETE: ERROR" . PHP_EOL;




echo PHP_EOL . "=== TEST: Topic ===" . PHP_EOL;

$topic = new Topic($conn);
$topic->title = "Test Topic";
$topic->author_id = 1; // должен существовать user с id=1

echo $topic->create() ? "CREATE: OK" . PHP_EOL : "CREATE: ERROR" . PHP_EOL;

$topicId = $conn->lastInsertId();

echo $topic->getById($topicId) ? "READ: OK" . PHP_EOL : "READ: ERROR" . PHP_EOL;

$topic->id = $topicId;
$topic->status = "closed";
echo $topic->update() ? "UPDATE: OK" . PHP_EOL : "UPDATE: ERROR" . PHP_EOL;

$stmt = $topic->getAll();
echo "GET ALL: OK (".$stmt->rowCount().")" . PHP_EOL;

echo $topic->delete($topicId) ? "DELETE: OK" . PHP_EOL : "DELETE: ERROR" . PHP_EOL;




echo PHP_EOL . "=== TEST: Category ===" . PHP_EOL;

$cat = new Category($conn);
$cat->name = "Test Category";
$cat->parent_id = null;

echo $cat->create() ? "CREATE: OK" . PHP_EOL : "CREATE: ERROR" . PHP_EOL;

$catId = $conn->lastInsertId();

echo $cat->getById($catId) ? "READ: OK" . PHP_EOL : "READ: ERROR" . PHP_EOL;

$cat->id = $catId;
$cat->name = "Updated Category";
echo $cat->update() ? "UPDATE: OK" . PHP_EOL : "UPDATE: ERROR" . PHP_EOL;

$stmt = $cat->getAll();
echo "GET ALL: OK (".$stmt->rowCount().")" . PHP_EOL;

echo $cat->delete($catId) ? "DELETE: OK" . PHP_EOL : "DELETE: ERROR" . PHP_EOL;




echo PHP_EOL . "=== TEST: Post ===" . PHP_EOL;

$post = new Post($conn);
$post->topic_id = 1;   // должен существовать topic id=1
$post->author_id = 1;  // должен существовать user id=1
$post->content = "Test post content";

echo $post->create() ? "CREATE: OK" . PHP_EOL : "CREATE: ERROR" . PHP_EOL;

$postId = $post->id;

echo $post->getById($postId) ? "READ: OK" . PHP_EOL : "READ: ERROR" . PHP_EOL;

$post->content = "Updated content";
echo $post->update() ? "UPDATE: OK" . PHP_EOL : "UPDATE: ERROR" . PHP_EOL;

$stmt = $post->getAll();
echo "GET ALL: OK (".$stmt->rowCount().")" . PHP_EOL;

echo $post->delete($postId) ? "DELETE: OK" . PHP_EOL : "DELETE: ERROR" . PHP_EOL;




echo PHP_EOL . "=== TEST: Notification ===" . PHP_EOL;

$notif = new Notification($conn);
$notif->user_id = 1; // должен существовать user
$notif->message = "Test notification";
$notif->type = "info";

echo $notif->create() ? "CREATE: OK" . PHP_EOL : "CREATE: ERROR" . PHP_EOL;

$notifId = $conn->lastInsertId();

echo $notif->getById($notifId) ? "READ: OK" . PHP_EOL : "READ: ERROR" . PHP_EOL;

$notif->id = $notifId;
$notif->read_status = 1;
echo $notif->update() ? "UPDATE: OK" . PHP_EOL : "UPDATE: ERROR" . PHP_EOL;

$stmt = $notif->getAll();
echo "GET ALL: OK (".$stmt->rowCount().")" . PHP_EOL;

echo $notif->delete($notifId) ? "DELETE: OK" . PHP_EOL : "DELETE: ERROR" . PHP_EOL;


echo PHP_EOL . "=== ВСЕ ТЕСТЫ ЗАВЕРШЕНЫ ===" . PHP_EOL;