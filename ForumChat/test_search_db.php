<?php
/**
 * Диагностика API поиска - проверка структуры БД
 */
header('Content-Type: text/html; charset=utf-8');

echo "<h1>🔍 Диагностика API поиска</h1>";

try {
    require_once __DIR__ . '/config/database.php';
    $db = new Database();
    $conn = $db->getConnection();
    
    echo "<h2>✓ Подключение к БД успешно</h2>";
    
    // Проверим таблицы
    echo "<h3>Структура таблиц:</h3>";
    
    $tables = [
        'posts' => ['id', 'title', 'content', 'topic_id', 'author_id', 'deleted', 'hidden'],
        'topics' => ['id', 'title', 'description', 'status', 'author_id'],
        'users' => ['id', 'username']
    ];
    
    foreach ($tables as $table => $expectedCols) {
        echo "<h4>Таблица: $table</h4>";
        
        // Получаем информацию о колонках
        $stmt = $conn->query("DESCRIBE $table");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        
        echo "<ul>";
        foreach ($expectedCols as $col) {
            $exists = in_array($col, $columns) ? '✓' : '✗';
            echo "<li>$exists $col</li>";
        }
        echo "</ul>";
        
        // Количество записей
        $countStmt = $conn->query("SELECT COUNT(*) FROM $table");
        $count = $countStmt->fetchColumn();
        echo "<p>Записей: <strong>$count</strong></p>";
    }
    
    // Попробуем простой поиск
    echo "<h3>Тестовый поиск:</h3>";
    
    $searchTerm = '%тест%';
    
    $sql = "
    SELECT 'post' as type, p.id, COALESCE(t.title, '') as title, COALESCE(p.content, '') as content
    FROM posts p
    LEFT JOIN topics t ON p.topic_id = t.id
    WHERE (p.content LIKE ? OR COALESCE(t.title, '') LIKE ?)
    AND p.deleted = 0
    AND p.hidden = 0
    LIMIT 5
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(1, $searchTerm, PDO::PARAM_STR);
    $stmt->bindParam(2, $searchTerm, PDO::PARAM_STR);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Результатов найдено: <strong>" . count($results) . "</strong></p>";
    
    if (count($results) > 0) {
        echo "<ul>";
        foreach ($results as $row) {
            $title = substr($row['title'], 0, 50);
            echo "<li>[$row[type]] $title</li>";
        }
        echo "</ul>";
    }
    
    echo "<h3 style='color: green;'>✓ Поиск работает, проверяйте API!</h3>";
    
} catch (Exception $e) {
    echo "<h2 style='color: red;'>✗ Ошибка: " . $e->getMessage() . "</h2>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
