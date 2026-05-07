<?php
/**
 * Тестовый скрипт для проверки системы модерации
 * Используйте: http://yourforum.com/test_moderation.php
 */

session_start();

// Проверка авторизации и прав
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || 
    ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'moderator')) {
    http_response_code(403);
    echo '<h1>❌ Ошибка доступа</h1>';
    echo '<p>Доступно только администраторам и модераторам</p>';
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/PostModeration.php';
require_once __DIR__ . '/../classes/ForbiddenWords.php';
require_once __DIR__ . '/../classes/AutoModerator.php';
require_once __DIR__ . '/../classes/NotificationManager.php';
require_once __DIR__ . '/../classes/AdminManager.php';

$db = new Database();
$conn = $db->connect();

$results = [];
$errors = [];

// Тест 1: Подключение к БД
try {
    $testQuery = $conn->query("SELECT 1");
    $results[] = ['name' => '✅ Подключение к БД', 'status' => 'OK'];
} catch (Exception $e) {
    $errors[] = ['name' => '❌ Подключение к БД', 'error' => $e->getMessage()];
}

// Тест 2: Таблица forbidden_words
try {
    $result = $conn->query("SELECT COUNT(*) as count FROM forbidden_words")->fetch(PDO::FETCH_ASSOC);
    $results[] = ['name' => '✅ Таблица forbidden_words существует', 'detail' => $result['count'] . ' слов в базе'];
} catch (Exception $e) {
    $errors[] = ['name' => '❌ Таблица forbidden_words', 'error' => $e->getMessage()];
}

// Тест 3: Таблица post_deletion_marks
try {
    $result = $conn->query("SELECT COUNT(*) as count FROM post_deletion_marks")->fetch(PDO::FETCH_ASSOC);
    $results[] = ['name' => '✅ Таблица post_deletion_marks существует', 'detail' => $result['count'] . ' метак'];
} catch (Exception $e) {
    $errors[] = ['name' => '❌ Таблица post_deletion_marks', 'error' => $e->getMessage()];
}

// Тест 4: Таблица notifications
try {
    $result = $conn->query("SELECT COUNT(*) as count FROM notifications")->fetch(PDO::FETCH_ASSOC);
    $results[] = ['name' => '✅ Таблица notifications существует', 'detail' => $result['count'] . ' уведомлений'];
} catch (Exception $e) {
    $errors[] = ['name' => '❌ Таблица notifications', 'error' => $e->getMessage()];
}

// Тест 5: Таблица admin_actions
try {
    $result = $conn->query("SELECT COUNT(*) as count FROM admin_actions")->fetch(PDO::FETCH_ASSOC);
    $results[] = ['name' => '✅ Таблица admin_actions существует', 'detail' => $result['count'] . ' действий'];
} catch (Exception $e) {
    $errors[] = ['name' => '❌ Таблица admin_actions', 'error' => $e->getMessage()];
}

// Тест 6: Колонки в таблице posts
try {
    $columns = $conn->query("DESCRIBE posts")->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'Field');
    
    $requiredColumns = ['hidden', 'hidden_reason', 'hidden_at'];
    $missingColumns = array_diff($requiredColumns, $columnNames);
    
    if (empty($missingColumns)) {
        $results[] = ['name' => '✅ Колонки в таблице posts существуют', 'detail' => 'hidden, hidden_reason, hidden_at'];
    } else {
        $errors[] = ['name' => '❌ Отсутствуют колонки в posts', 'error' => 'Отсутствуют: ' . implode(', ', $missingColumns)];
    }
} catch (Exception $e) {
    $errors[] = ['name' => '❌ Проверка колонок posts', 'error' => $e->getMessage()];
}

// Тест 7: Класс ForbiddenWords
try {
    $fw = new ForbiddenWords($conn);
    $words = $fw->getAllWords(1);
    $results[] = ['name' => '✅ Класс ForbiddenWords работает', 'detail' => 'Загружено ' . count($words) . ' слово'];
} catch (Exception $e) {
    $errors[] = ['name' => '❌ Класс ForbiddenWords', 'error' => $e->getMessage()];
}

// Тест 8: Проверка контента
try {
    $fw = new ForbiddenWords($conn);
    $fw->addWord('тестовое_слово_проверка', $_SESSION['user_id']);
    
    $result = $fw->checkContentDetailed('текст с тестовое_слово_проверка внутри');
    
    if ($result['has_forbidden'] && isset($result['words_found']['тестовое_слово_проверка'])) {
        $results[] = ['name' => '✅ Проверка контента на запрещённые слова', 'detail' => 'Найдено 1 нарушение'];
        
        // Очищаем тестовое слово
        $fw->removeWord(key(array_filter($fw->getAllWords(100), function($w) { return $w['word'] === 'тестовое_слово_проверка'; })));
    } else {
        $errors[] = ['name' => '❌ Проверка контента', 'error' => 'Слово не найдено'];
    }
} catch (Exception $e) {
    $errors[] = ['name' => '❌ Проверка контента', 'error' => $e->getMessage()];
}

// Тест 9: Класс PostModeration
try {
    $pm = new PostModeration($conn);
    $results[] = ['name' => '✅ Класс PostModeration работает', 'detail' => 'Готов к использованию'];
} catch (Exception $e) {
    $errors[] = ['name' => '❌ Класс PostModeration', 'error' => $e->getMessage()];
}

// Тест 10: Класс NotificationManager
try {
    $nm = new NotificationManager($conn);
    $count = $nm->getUnreadCount($_SESSION['user_id']);
    $results[] = ['name' => '✅ Класс NotificationManager работает', 'detail' => 'Непрочитанных: ' . $count];
} catch (Exception $e) {
    $errors[] = ['name' => '❌ Класс NotificationManager', 'error' => $e->getMessage()];
}

// Тест 11: Класс AdminManager
try {
    $am = new AdminManager($conn);
    $stats = $am->getActionStats(7);
    $results[] = ['name' => '✅ Класс AdminManager работает', 'detail' => 'Действий за 7 дней: ' . count($stats)];
} catch (Exception $e) {
    $errors[] = ['name' => '❌ Класс AdminManager', 'error' => $e->getMessage()];
}

// Тест 12: Проверка файлов
try {
    $files = [
        'classes/PostModeration.php',
        'classes/ForbiddenWords.php',
        'classes/AutoModerator.php',
        'classes/NotificationManager.php',
        'classes/AdminManager.php',
        'notifications.php',
        'admin_panel.php',
        'api_moderation.php',
        'init_moderation.php'
    ];
    
    $missingFiles = [];
    foreach ($files as $file) {
        if (!file_exists($file)) {
            $missingFiles[] = $file;
        }
    }
    
    if (empty($missingFiles)) {
        $results[] = ['name' => '✅ Все необходимые файлы присутствуют', 'detail' => count($files) . ' файл(ов)'];
    } else {
        $errors[] = ['name' => '❌ Отсутствуют файлы', 'error' => implode(', ', $missingFiles)];
    }
} catch (Exception $e) {
    $errors[] = ['name' => '❌ Проверка файлов', 'error' => $e->getMessage()];
}

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тест системы модерации</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            padding: 30px;
            max-width: 800px;
        }
        
        h1 {
            color: #333;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .test-item {
            padding: 15px;
            margin-bottom: 10px;
            border-left: 4px solid #ddd;
            background: #f9f9f9;
            border-radius: 4px;
        }
        
        .test-item.success {
            border-left-color: #28a745;
            background: #d4edda;
        }
        
        .test-item.error {
            border-left-color: #dc3545;
            background: #f8d7da;
        }
        
        .test-name {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .test-detail {
            font-size: 0.9rem;
            color: #666;
            margin: 5px 0;
        }
        
        .test-error {
            font-size: 0.9rem;
            color: #dc3545;
            margin: 5px 0;
            font-family: monospace;
        }
        
        .summary {
            background: #f0f0f0;
            padding: 20px;
            border-radius: 4px;
            margin-top: 30px;
            text-align: center;
        }
        
        .progress-text {
            font-size: 2rem;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .progress-ok {
            color: #28a745;
        }
        
        .progress-error {
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Тест системы модерации</h1>
        
        <?php if (!empty($results)): ?>
            <h3 class="mb-3">✅ Успешные проверки (<?php echo count($results); ?>)</h3>
            <?php foreach ($results as $result): ?>
                <div class="test-item success">
                    <div class="test-name"><?php echo $result['name']; ?></div>
                    <?php if (isset($result['detail'])): ?>
                        <div class="test-detail">📌 <?php echo $result['detail']; ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <h3 class="mb-3 mt-4">❌ Ошибки (<?php echo count($errors); ?>)</h3>
            <?php foreach ($errors as $error): ?>
                <div class="test-item error">
                    <div class="test-name"><?php echo $error['name']; ?></div>
                    <div class="test-error">⚠️ <?php echo $error['error']; ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <div class="summary">
            <h4>📊 Результаты</h4>
            <div class="progress-text">
                <span class="progress-ok"><?php echo count($results); ?>/<?php echo count($results) + count($errors); ?></span>
            </div>
            <?php if (empty($errors)): ?>
                <p class="text-success" style="font-size: 1.2rem;">
                    🎉 <strong>Система готова к использованию!</strong>
                </p>
                <p>Все компоненты работают корректно.</p>
            <?php else: ?>
                <p class="text-danger" style="font-size: 1.2rem;">
                    ⚠️ <strong>Обнаружены проблемы</strong>
                </p>
                <p>Пожалуйста, исправьте ошибки выше перед использованием системы.</p>
            <?php endif; ?>
            
            <div class="mt-3">
                <a href="admin_panel.php" class="btn btn-primary">
                    <i class="fas fa-shield-alt"></i> Перейти в админ-панель
                </a>
                <a href="notifications.php" class="btn btn-info">
                    <i class="fas fa-bell"></i> Уведомления
                </a>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-home"></i> На главную
                </a>
            </div>
        </div>
    </div>
</body>
</html>
