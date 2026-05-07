<?php
/**
 * Тестирование API поиска
 */
echo "=== Тест API поиска ===\n\n";

// Проверим наличие файлов
echo "1. Проверка файлов:\n";
echo "   - api/search.php: " . (file_exists(__DIR__ . '/api/search.php') ? "✓ Есть" : "✗ НЕТ") . "\n";
echo "   - config/database.php: " . (file_exists(__DIR__ . '/config/database.php') ? "✓ Есть" : "✗ НЕТ") . "\n";
echo "   - classes/Topic.php: " . (file_exists(__DIR__ . '/classes/Topic.php') ? "✓ Есть" : "✗ НЕТ") . "\n";
echo "   - classes/Post.php: " . (file_exists(__DIR__ . '/classes/Post.php') ? "✓ Есть" : "✗ НЕТ") . "\n\n";

// Попробуем загрузить Database
echo "2. Проверка подключения к БД:\n";
try {
    require_once __DIR__ . '/config/database.php';
    $db = new Database();
    $conn = $db->getConnection();
    echo "   ✓ Успешно подключено к БД\n\n";
    
    // Проверим таблицы
    echo "3. Проверка таблиц:\n";
    $tables = ['posts', 'topics', 'users'];
    foreach ($tables as $table) {
        $stmt = $conn->query("SELECT COUNT(*) as cnt FROM {$table}");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "   - {$table}: {$result['cnt']} записей\n";
    }
    echo "\n";
    
    // Попробуем выполнить простой поиск
    echo "4. Тестовый поиск:\n";
    $searchTerm = '%тест%';
    $sql = "
    SELECT 'post' as type, p.id, p.title, p.content, p.author_id, p.created_at, 
           t.id as topic_id, t.title as topic_title, u.username as author_name
    FROM posts p
    JOIN topics t ON p.topic_id = t.id
    JOIN users u ON p.author_id = u.id
    WHERE (p.content LIKE ? OR p.title LIKE ?)
    AND p.deleted = 0
    AND p.hidden = 0
    LIMIT 5
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(1, $searchTerm, PDO::PARAM_STR);
    $stmt->bindParam(2, $searchTerm, PDO::PARAM_STR);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "   - Найдено " . count($results) . " постов\n\n";
    
} catch (Exception $e) {
    echo "   ✗ Ошибка: " . $e->getMessage() . "\n\n";
}

echo "=== URL для тестирования ===\n";
echo "GET /api/search.php?q=тест&limit=20&offset=0\n";
echo "\nОткройте в браузере: http://localhost/ForumChat/api/search.php?q=тест\n";
