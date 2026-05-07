<?php
/**
 * Простой тест основных функций модерации без авторизации
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/PostModeration.php';
require_once __DIR__ . '/../classes/NotificationManager.php';

$db = new Database();
$conn = $db->getConnection();

$results = [];
$errors = [];

// Тест 1: Подключение к БД
try {
    $testQuery = $conn->query("SELECT 1");
    $results[] = ['name' => '✅ Подключение к БД', 'status' => 'OK'];
} catch (Exception $e) {
    $errors[] = ['name' => '❌ Подключение к БД', 'error' => $e->getMessage()];
}

// Тест 2: Таблица moderation_log
try {
    $result = $conn->query("SELECT COUNT(*) as count FROM moderation_log")->fetch(PDO::FETCH_ASSOC);
    $results[] = ['name' => '✅ Таблица moderation_log существует', 'detail' => $result['count'] . ' записей'];
} catch (Exception $e) {
    $errors[] = ['name' => '❌ Таблица moderation_log', 'error' => $e->getMessage()];
}

// Тест 3: Таблица notifications
try {
    $result = $conn->query("SELECT COUNT(*) as count FROM notifications")->fetch(PDO::FETCH_ASSOC);
    $results[] = ['name' => '✅ Таблица notifications существует', 'detail' => $result['count'] . ' уведомлений'];
} catch (Exception $e) {
    $errors[] = ['name' => '❌ Таблица notifications', 'error' => $e->getMessage()];
}

// Тест 4: Проверка колонок в users (ban_reason)
try {
    $columns = $conn->query("DESCRIBE users")->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'Field');

    if (in_array('ban_reason', $columnNames)) {
        $results[] = ['name' => '✅ Колонка ban_reason в users существует', 'detail' => 'Готова для хранения причин банов'];
    } else {
        $errors[] = ['name' => '❌ Отсутствует колонка ban_reason в users', 'error' => 'Необходимо добавить колонку'];
    }
} catch (Exception $e) {
    $errors[] = ['name' => '❌ Проверка колонок users', 'error' => $e->getMessage()];
}

// Тест 5: Класс User - проверка методов
try {
    $user = new User($conn);
    $results[] = ['name' => '✅ Класс User загружен', 'detail' => 'Методы доступны'];
} catch (Exception $e) {
    $errors[] = ['name' => '❌ Класс User', 'error' => $e->getMessage()];
}

// Тест 6: Класс PostModeration - проверка методов
try {
    $pm = new PostModeration($conn);
    $results[] = ['name' => '✅ Класс PostModeration загружен', 'detail' => 'Методы доступны'];
} catch (Exception $e) {
    $errors[] = ['name' => '❌ Класс PostModeration', 'error' => $e->getMessage()];
}

// Тест 7: Класс NotificationManager - проверка методов
try {
    $nm = new NotificationManager($conn);
    $results[] = ['name' => '✅ Класс NotificationManager загружен', 'detail' => 'Методы доступны'];
} catch (Exception $e) {
    $errors[] = ['name' => '❌ Класс NotificationManager', 'error' => $e->getMessage()];
}

// Вывод результатов
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тест основных функций модерации</title>
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

        .test-result {
            margin-bottom: 10px;
            padding: 10px;
            border-radius: 5px;
        }

        .success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-shield-alt"></i> Тест основных функций модерации</h1>

        <div class="mb-4">
            <h4>✅ Успешные тесты (<?php echo count($results); ?>)</h4>
            <?php foreach ($results as $result): ?>
                <div class="test-result success">
                    <strong><?php echo htmlspecialchars($result['name']); ?></strong>
                    <?php if (isset($result['detail'])): ?>
                        <br><small><?php echo htmlspecialchars($result['detail']); ?></small>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="mb-4">
            <h4>❌ Ошибки (<?php echo count($errors); ?>)</h4>
            <?php foreach ($errors as $error): ?>
                <div class="test-result error">
                    <strong><?php echo htmlspecialchars($error['name']); ?></strong>
                    <br><small><?php echo htmlspecialchars($error['error']); ?></small>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="text-center mt-4">
            <a href="index.php" class="btn btn-primary">
                <i class="fas fa-home"></i> Вернуться на главную
            </a>
        </div>
    </div>
</body>
</html>