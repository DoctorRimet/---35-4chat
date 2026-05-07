<?php

require_once __DIR__ . '/config/database.php';

$db = new Database();
$conn = $db->getConnection();

echo "<h2>📊 Проверка данных в БД ForumChat</h2>";

// ==================== ЗАДАНИЕ 2: ПОИСК (WHERE, LIKE, ORDER BY) ====================

echo "<h3>1️⃣ Количество данных в таблицах</h3>";

$tables = ['topics', 'posts', 'users', 'comments', 'tags', 'topic_tags'];
foreach ($tables as $table) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM $table");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p><strong>$table:</strong> " . $result['count'] . " записей</p>";
}

echo "<hr>";

// ==================== ЗАПРОСЫ ДЛЯ ЗАДАНИЯ 2 ====================

echo "<h3>2️⃣ ЗАДАНИЕ 2: Поиск по контенту постов</h3>";

// Получить примеры слов для поиска
echo "<p><strong>Примеры содержимого в таблице posts:</strong></p>";
$stmt = $conn->prepare("SELECT id, content FROM posts WHERE deleted = 0 LIMIT 5");
$stmt->execute();
$samples = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($samples)) {
    echo "<ul>";
    foreach ($samples as $sample) {
        $preview = mb_substr($sample['content'], 0, 50) . "...";
        echo "<li>" . htmlspecialchars($preview) . "</li>";
    }
    echo "</ul>";
} else {
    echo "<p style='color:red'>⚠️ НЕТ ДАННЫХ В posts!</p>";
}

echo "<hr>";

// РАБОЧИЙ ЗАПРОС 1: Все видимые посты (без фильтра по скрытости)
echo "<p><strong>РАБОЧИЙ ЗАПРОС 1: Все посты (видимые и скрытые)</strong></p>";
echo "<pre>SELECT p.id, p.content, u.username, p.created_at, p.hidden
FROM posts p
LEFT JOIN users u ON p.author_id = u.id
WHERE p.deleted = 0
ORDER BY p.created_at DESC
LIMIT 10;</pre>";

$stmt = $conn->prepare("
    SELECT p.id, p.content, u.username, p.created_at, p.hidden
    FROM posts p
    LEFT JOIN users u ON p.author_id = u.id
    WHERE p.deleted = 0
    ORDER BY p.created_at DESC
    LIMIT 10
");
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Результат: <strong>" . count($results) . " постов</strong></p>";
if (!empty($results)) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Контент</th><th>Автор</th><th>Дата</th><th>Скрыт</th></tr>";
    foreach ($results as $row) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars(mb_substr($row['content'], 0, 30)) . "...</td>";
        echo "<td>" . ($row['username'] ?? 'Unknown') . "</td>";
        echo "<td>" . $row['created_at'] . "</td>";
        echo "<td>" . ($row['hidden'] ? '🔒 Да' : '✅ Нет') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr>";

// РАБОЧИЙ ЗАПРОС 2: Поиск по содержимому с LIKE
echo "<p><strong>РАБОЧИЙ ЗАПРОС 2: Поиск по слову 'тест'</strong></p>";
echo "<pre>SELECT p.id, p.content, u.username, p.created_at
FROM posts p
LEFT JOIN users u ON p.author_id = u.id
WHERE p.content LIKE '%тест%'
  AND p.deleted = 0
ORDER BY p.created_at DESC
LIMIT 10;</pre>";

$stmt = $conn->prepare("
    SELECT p.id, p.content, u.username, p.created_at
    FROM posts p
    LEFT JOIN users u ON p.author_id = u.id
    WHERE p.content LIKE ?
      AND p.deleted = 0
    ORDER BY p.created_at DESC
    LIMIT 10
");
$searchTerm = '%тест%';
$stmt->bindParam(1, $searchTerm);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Результат: <strong>" . count($results) . " постов с 'тест'</strong></p>";
if (!empty($results)) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Контент</th><th>Автор</th><th>Дата</th></tr>";
    foreach ($results as $row) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars(mb_substr($row['content'], 0, 40)) . "...</td>";
        echo "<td>" . ($row['username'] ?? 'Unknown') . "</td>";
        echo "<td>" . $row['created_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:orange'>⚠️ Постов с 'тест' не найдено</p>";
}

echo "<hr>";

// РАБОЧИЙ ЗАПРОС 3: Поиск по именам пользователей
echo "<p><strong>РАБОЧИЙ ЗАПРОС 3: Поиск постов по никнейму автора</strong></p>";
echo "<pre>SELECT p.id, p.content, u.username, p.created_at
FROM posts p
LEFT JOIN users u ON p.author_id = u.id
WHERE u.username LIKE '%дис%'
  AND p.deleted = 0
ORDER BY p.created_at DESC
LIMIT 10;</pre>";

$stmt = $conn->prepare("
    SELECT p.id, p.content, u.username, p.created_at
    FROM posts p
    LEFT JOIN users u ON p.author_id = u.id
    WHERE u.username LIKE ?
      AND p.deleted = 0
    ORDER BY p.created_at DESC
    LIMIT 10
");
$userSearchTerm = '%дис%';
$stmt->bindParam(1, $userSearchTerm);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Результат: <strong>" . count($results) . " постов от пользователей с 'дис'</strong></p>";
if (!empty($results)) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Контент</th><th>Автор</th><th>Дата</th></tr>";
    foreach ($results as $row) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars(mb_substr($row['content'], 0, 40)) . "...</td>";
        echo "<td>" . ($row['username'] ?? 'Unknown') . "</td>";
        echo "<td>" . $row['created_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr>";

// ==================== ЗАДАНИЕ 3: ПАГИНАЦИЯ (LIMIT, OFFSET) ====================

echo "<h3>3️⃣ ЗАДАНИЕ 3: Пагинация</h3>";

// Узнаём общее количество тем
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM topics WHERE status != 'draft'");
$stmt->execute();
$totalTopics = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

echo "<p>Всего тем (не в черновике): <strong>$totalTopics</strong></p>";

$limit = 5; // Для примера показываем по 5 на странице
$totalPages = ceil($totalTopics / $limit);

echo "<p>При <strong>$limit</strong> тем на странице: <strong>$totalPages страниц</strong></p>";

echo "<hr>";

// СТРАНИЦА 1
echo "<p><strong>СТРАНИЦА 1 (OFFSET 0, LIMIT 5)</strong></p>";
echo "<pre>SELECT id, title, author_id, created_at FROM topics
WHERE status != 'draft'
ORDER BY is_pinned DESC, created_at DESC
LIMIT 5 OFFSET 0;</pre>";

$stmt = $conn->prepare("
    SELECT id, title, author_id, created_at FROM topics
    WHERE status != 'draft'
    ORDER BY is_pinned DESC, created_at DESC
    LIMIT 5 OFFSET 0
");
$stmt->execute();
$page1 = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Результат: <strong>" . count($page1) . " тем</strong></p>";
if (!empty($page1)) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Заголовок</th><th>Author ID</th><th>Дата</th></tr>";
    foreach ($page1 as $row) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars(mb_substr($row['title'], 0, 40)) . "</td>";
        echo "<td>" . $row['author_id'] . "</td>";
        echo "<td>" . $row['created_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red'>⚠️ НЕТ ТЕМ НА СТРАНИЦЕ 1!</p>";
}

echo "<hr>";

// СТРАНИЦА 2
echo "<p><strong>СТРАНИЦА 2 (OFFSET 5, LIMIT 5)</strong></p>";
echo "<pre>SELECT id, title, author_id, created_at FROM topics
WHERE status != 'draft'
ORDER BY is_pinned DESC, created_at DESC
LIMIT 5 OFFSET 5;</pre>";

$stmt = $conn->prepare("
    SELECT id, title, author_id, created_at FROM topics
    WHERE status != 'draft'
    ORDER BY is_pinned DESC, created_at DESC
    LIMIT 5 OFFSET 5
");
$stmt->execute();
$page2 = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Результат: <strong>" . count($page2) . " тем</strong></p>";
if (!empty($page2)) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Заголовок</th><th>Author ID</th><th>Дата</th></tr>";
    foreach ($page2 as $row) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars(mb_substr($row['title'], 0, 40)) . "</td>";
        echo "<td>" . $row['author_id'] . "</td>";
        echo "<td>" . $row['created_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    if ($totalPages <= 1) {
        echo "<p style='color:orange'>ℹ️ Только 1 страница (всего " . count($page1) . " тем)</p>";
    } else {
        echo "<p style='color:red'>⚠️ НЕТ ТЕМ НА СТРАНИЦЕ 2!</p>";
    }
}

echo "<hr>";

// ==================== ЗАДАНИЕ 1: JOIN ====================

echo "<h3>1️⃣ ЗАДАНИЕ 1: SELECT с JOIN</h3>";

echo "<p><strong>JOIN: Topics + Tags</strong></p>";
echo "<pre>SELECT t.id, t.title, GROUP_CONCAT(tg.name SEPARATOR ', ') as tags
FROM topics t
LEFT JOIN topic_tags tt ON tt.topic_id = t.id
LEFT JOIN tags tg ON tg.id = tt.tag_id
WHERE t.status != 'draft'
GROUP BY t.id
LIMIT 10;</pre>";

$stmt = $conn->prepare("
    SELECT t.id, t.title, GROUP_CONCAT(tg.name SEPARATOR ', ') as tags
    FROM topics t
    LEFT JOIN topic_tags tt ON tt.topic_id = t.id
    LEFT JOIN tags tg ON tg.id = tt.tag_id
    WHERE t.status != 'draft'
    GROUP BY t.id
    LIMIT 10
");
$stmt->execute();
$joinResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Результат: <strong>" . count($joinResults) . " тем с тегами</strong></p>";
if (!empty($joinResults)) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Заголовок</th><th>Теги</th></tr>";
    foreach ($joinResults as $row) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['title']) . "</td>";
        echo "<td>" . htmlspecialchars($row['tags'] ?? 'Нет тегов') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr>";

echo "<p style='text-align:center; color:green; font-size:16px;'><strong>✅ Проверка завершена!</strong></p>";
