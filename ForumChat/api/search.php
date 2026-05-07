<?php

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Логирование запроса
$logFile = __DIR__ . '/../search_api.log';
$logMessage = date('Y-m-d H:i:s') . ' | ' . $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' | Query: ' . json_encode($_GET) . "\n";
file_put_contents($logFile, $logMessage, FILE_APPEND);

require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/SearchHistory.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    $searchHistory = new SearchHistory($conn);

    // Параметры поиска
    $query = isset($_GET['q']) ? trim($_GET['q']) : '';

    // Сохранить поиск в историю для авторизованных пользователей
    if (!$is_guest && !empty($query)) {
        $searchHistory->addSearch($_SESSION['user_id'], $query);
    }

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    // Параметры фильтрации
    $sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'date_desc';
    $authorId = isset($_GET['author']) ? (int)$_GET['author'] : 0;
    $dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
    $dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
    $minReplies = isset($_GET['min_replies']) ? (int)$_GET['min_replies'] : 0;

    // Проверка минимальной длины запроса
    if (strlen($query) < 3) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Минимальная длина запроса: 3 символа',
            'code' => 'MIN_LENGTH'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Санитизация запроса для поиска
    $searchTerm = '%' . $query . '%';

    // Определяем ORDER BY в зависимости от выбранной сортировки
    $orderBy = 'created_at DESC';
    switch ($sortBy) {
        case 'date_asc':
            $orderBy = 'created_at ASC';
            break;
        case 'popularity':
            $orderBy = 'replies_count DESC, created_at DESC';
            break;
        case 'replies':
            $orderBy = 'replies_count DESC';
            break;
    }

    // SQL запрос для поиска постов с подсчетом ответов
    $sql = "
    SELECT 
        'post' as type,
        p.id,
        COALESCE(t.title, '') as title,
        COALESCE(p.content, '') as content,
        p.author_id,
        p.created_at,
        p.hidden,
        t.id as topic_id,
        COALESCE(t.title, '') as topic_title,
        COALESCE(u.username, 'Unknown') as author_name,
        COUNT(DISTINCT c.id) as replies_count
    FROM posts p
    LEFT JOIN topics t ON p.topic_id = t.id
    LEFT JOIN users u ON p.author_id = u.id
    LEFT JOIN comments c ON c.post_id = p.id AND c.deleted = 0
    WHERE (p.content LIKE ? OR COALESCE(t.title, '') LIKE ?)
    AND p.deleted = 0
    AND p.hidden = 0
    ";

    // Добавляем условия фильтра
    if ($authorId > 0) {
        $sql .= " AND p.author_id = " . (int)$authorId;
    }
    if (!empty($dateFrom)) {
        $sql .= " AND DATE(p.created_at) >= '" . $conn->quote($dateFrom) . "'";
    }
    if (!empty($dateTo)) {
        $sql .= " AND DATE(p.created_at) <= '" . $conn->quote($dateTo) . "'";
    }

    $sql .= " GROUP BY p.id";

    // Добавляем фильтр по количеству ответов в HAVING
    if ($minReplies > 0) {
        $sql .= " HAVING replies_count >= " . (int)$minReplies;
    }

    $sql .= "
    UNION ALL
    
    SELECT 
        'topic' as type,
        t.id,
        COALESCE(t.title, '') as title,
        COALESCE(t.description, '') as content,
        t.author_id,
        t.created_at,
        0 as hidden,
        t.id as topic_id,
        COALESCE(t.title, '') as topic_title,
        COALESCE(u.username, 'Unknown') as author_name,
        COUNT(DISTINCT p2.id) as replies_count
    FROM topics t
    LEFT JOIN users u ON t.author_id = u.id
    LEFT JOIN posts p2 ON p2.topic_id = t.id AND p2.deleted = 0
    WHERE COALESCE(t.title, '') LIKE ?
    AND t.status != 'archived'
    ";

    // Добавляем фильтры для тем
    if ($authorId > 0) {
        $sql .= " AND t.author_id = " . (int)$authorId;
    }
    if (!empty($dateFrom)) {
        $sql .= " AND DATE(t.created_at) >= '" . $conn->quote($dateFrom) . "'";
    }
    if (!empty($dateTo)) {
        $sql .= " AND DATE(t.created_at) <= '" . $conn->quote($dateTo) . "'";
    }

    $sql .= " GROUP BY t.id";

    if ($minReplies > 0) {
        $sql .= " HAVING replies_count >= " . (int)$minReplies;
    }

    $sql .= "
    ORDER BY $orderBy
    LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('SQL prepare error: ' . implode(', ', $conn->errorInfo()));
    }

    $stmt->bindParam(1, $searchTerm, PDO::PARAM_STR);
    $stmt->bindParam(2, $searchTerm, PDO::PARAM_STR);
    $stmt->bindParam(3, $searchTerm, PDO::PARAM_STR);
    $stmt->bindParam(4, $limit, PDO::PARAM_INT);
    $stmt->bindParam(5, $offset, PDO::PARAM_INT);

    if (!$stmt->execute()) {
        throw new Exception('SQL execute error: ' . implode(', ', $stmt->errorInfo()));
    }

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Подсветка найденных слов в результатах
    foreach ($results as &$result) {
        $result['title_highlighted'] = highlightText($result['title'], $query);
        $result['content_highlighted'] = highlightText($result['content'], $query);
    }

    // Подсчитываем общее количество результатов
    $total = count($results) + $offset;
    if (count($results) < $limit) {
        $total = $offset + count($results);
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'query' => htmlspecialchars($query),
        'results' => $results,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'pages' => ceil($total / $limit),
        'filters' => [
            'sort' => $sortBy,
            'author' => $authorId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'min_replies' => $minReplies
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);

    // Логируем ошибку
    $logFile = __DIR__ . '/../search_api_errors.log';
    $logMessage = date('Y-m-d H:i:s') . ' | Error: ' . $e->getMessage() . ' | Query: ' . json_encode($_GET) . "\n";
    @file_put_contents($logFile, $logMessage, FILE_APPEND);

    echo json_encode([
        'success' => false,
        'error' => 'Ошибка при выполнении поиска',
        'message' => $e->getMessage(),
        'code' => 'SERVER_ERROR'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Подсвечивает найденное слово в тексте
 * @param string $text Исходный текст
 * @param string $search Поисковый запрос
 * @return string Текст с подсвеченными словами
 */
function highlightText($text, $search)
{
    if (empty($text) || empty($search)) {
        return $text;
    }

    // Регулярное выражение для поиска (без учета регистра)
    $pattern = '/(' . preg_quote($search, '/') . ')/iu';

    // Заменяем найденные слова на выделенные версии
    $highlighted = preg_replace(
        $pattern,
        '<mark class="search-highlight">$1</mark>',
        htmlspecialchars($text)
    );

    return $highlighted;
}
