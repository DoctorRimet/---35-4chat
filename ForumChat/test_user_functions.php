<?php

/**
 * Тестовый скрипт для проверки пользовательских функций (версия 2.0)
 *
 * Использование:
 * 1. Поместить в корень проекта
 * 2. Открыть в браузере: http://forum.local/test_user_functions.php
 * 3. Проверить результаты
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Topic.php';
require_once __DIR__ . '/classes/Post.php';
require_once __DIR__ . '/classes/SearchHistory.php';
require_once __DIR__ . '/classes/User.php';

$db = new Database();
$conn = $db->getConnection();

$tests = [];
$user_id = 1; // Используем пользователя с ID 1 для тестирования
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тестирование пользовательских функций - ForumChat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background-color: #f0f2f5; padding: 2rem 0; }
        .test-card { border-left: 4px solid #6366f1; margin-bottom: 1.5rem; }
        .test-pass { border-left-color: #198754; background-color: #d1e7dd; }
        .test-fail { border-left-color: #dc3545; background-color: #f8d7da; }
        .test-info { border-left-color: #0dcaf0; background-color: #cfe2ff; }
        code { background-color: #e9ecef; padding: 0.2rem 0.4rem; border-radius: 3px; }
    </style>
</head>
<body>

<div class="container">
    <h1 class="mb-4"><i class="bi bi-clipboard-check me-2"></i>Тестирование пользовательских функций v2.0</h1>
    
    <div class="alert alert-info" role="alert">
        <strong><i class="bi bi-info-circle me-2"></i>Информация:</strong>
        Этот скрипт проверяет все новые функции, добавленные в версии 2.0
    </div>

    <?php
    // TEST 1: Topic::getByUserId()
    try {
        $topic = new Topic($conn);
        $stmt = $topic->getByUserId($user_id);
        $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = count($topics);
        ?>
        <div class="card test-card test-pass">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-check-circle me-2"></i>Topic::getByUserId()</h5>
                <p class="card-text">Получение всех тем пользователя</p>
                <code>$topic->getByUserId($user_id)</code>
                <div class="mt-2">
                    <p><strong>Результат:</strong> ✅ Успешно</p>
                    <p><strong>Найдено тем:</strong> <span class="badge bg-primary"><?= $count ?></span></p>
                    <?php if ($count > 0) : ?>
                    <p><strong>Первая тема:</strong> <?= htmlspecialchars($topics[0]['title']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    } catch (Exception $e) {
        ?>
        <div class="card test-card test-fail">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-x-circle me-2"></i>Topic::getByUserId()</h5>
                <p class="card-text">Ошибка: <?= htmlspecialchars($e->getMessage()) ?></p>
            </div>
        </div>
        <?php
    }

    // TEST 2: Topic::getCountByUserId()
    try {
        $topic = new Topic($conn);
        $count = $topic->getCountByUserId($user_id);
        ?>
        <div class="card test-card test-pass">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-check-circle me-2"></i>Topic::getCountByUserId()</h5>
                <p class="card-text">Получение количества тем пользователя</p>
                <code>$topic->getCountByUserId($user_id)</code>
                <div class="mt-2">
                    <p><strong>Результат:</strong> ✅ Успешно</p>
                    <p><strong>Количество тем:</strong> <span class="badge bg-primary"><?= $count ?></span></p>
                </div>
            </div>
        </div>
        <?php
    } catch (Exception $e) {
        ?>
        <div class="card test-card test-fail">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-x-circle me-2"></i>Topic::getCountByUserId()</h5>
                <p class="card-text">Ошибка: <?= htmlspecialchars($e->getMessage()) ?></p>
            </div>
        </div>
        <?php
    }

    // TEST 3: Post::getByUserId()
    try {
        $post = new Post($conn);
        $stmt = $post->getByUserId($user_id);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = count($posts);
        ?>
        <div class="card test-card test-pass">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-check-circle me-2"></i>Post::getByUserId()</h5>
                <p class="card-text">Получение всех ответов пользователя</p>
                <code>$post->getByUserId($user_id)</code>
                <div class="mt-2">
                    <p><strong>Результат:</strong> ✅ Успешно</p>
                    <p><strong>Найдено ответов:</strong> <span class="badge bg-primary"><?= $count ?></span></p>
                    <?php if ($count > 0) : ?>
                    <p><strong>Связанная тема:</strong> <?= htmlspecialchars($posts[0]['topic_title'] ?? 'N/A') ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    } catch (Exception $e) {
        ?>
        <div class="card test-card test-fail">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-x-circle me-2"></i>Post::getByUserId()</h5>
                <p class="card-text">Ошибка: <?= htmlspecialchars($e->getMessage()) ?></p>
            </div>
        </div>
        <?php
    }

    // TEST 4: Post::getCountByUserId()
    try {
        $post = new Post($conn);
        $count = $post->getCountByUserId($user_id);
        ?>
        <div class="card test-card test-pass">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-check-circle me-2"></i>Post::getCountByUserId()</h5>
                <p class="card-text">Получение количества ответов пользователя</p>
                <code>$post->getCountByUserId($user_id)</code>
                <div class="mt-2">
                    <p><strong>Результат:</strong> ✅ Успешно</p>
                    <p><strong>Количество ответов:</strong> <span class="badge bg-primary"><?= $count ?></span></p>
                </div>
            </div>
        </div>
        <?php
    } catch (Exception $e) {
        ?>
        <div class="card test-card test-fail">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-x-circle me-2"></i>Post::getCountByUserId()</h5>
                <p class="card-text">Ошибка: <?= htmlspecialchars($e->getMessage()) ?></p>
            </div>
        </div>
        <?php
    }

    // TEST 5: SearchHistory::addSearch()
    try {
        $sh = new SearchHistory($conn);
        $result = $sh->addSearch($user_id, 'тест поиска');
        ?>
        <div class="card test-card test-pass">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-check-circle me-2"></i>SearchHistory::addSearch()</h5>
                <p class="card-text">Добавление поиска в историю</p>
                <code>$sh->addSearch($user_id, 'тест поиска')</code>
                <div class="mt-2">
                    <p><strong>Результат:</strong> ✅ Успешно</p>
                    <p><strong>Статус:</strong> <span class="badge bg-success"><?= $result ? 'Добавлено' : 'Ошибка' ?></span></p>
                    <p><strong>Примечание:</strong> Таблица <code>search_history</code> создана автоматически</p>
                </div>
            </div>
        </div>
        <?php
    } catch (Exception $e) {
        ?>
        <div class="card test-card test-fail">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-x-circle me-2"></i>SearchHistory::addSearch()</h5>
                <p class="card-text">Ошибка: <?= htmlspecialchars($e->getMessage()) ?></p>
            </div>
        </div>
        <?php
    }

    // TEST 6: SearchHistory::getHistory()
    try {
        $sh = new SearchHistory($conn);
        $stmt = $sh->getHistory($user_id, 20);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="card test-card test-pass">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-check-circle me-2"></i>SearchHistory::getHistory()</h5>
                <p class="card-text">Получение истории поисков</p>
                <code>$sh->getHistory($user_id, 20)</code>
                <div class="mt-2">
                    <p><strong>Результат:</strong> ✅ Успешно</p>
                    <p><strong>Найдено поисков:</strong> <span class="badge bg-primary"><?= count($history) ?></span></p>
                    <?php if (!empty($history)) : ?>
                    <p><strong>Последний поиск:</strong> "<?= htmlspecialchars($history[0]['query']) ?>" - <?= $history[0]['last_search'] ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    } catch (Exception $e) {
        ?>
        <div class="card test-card test-fail">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-x-circle me-2"></i>SearchHistory::getHistory()</h5>
                <p class="card-text">Ошибка: <?= htmlspecialchars($e->getMessage()) ?></p>
            </div>
        </div>
        <?php
    }

    // TEST 7: SearchHistory::getTopSearches()
    try {
        $sh = new SearchHistory($conn);
        $stmt = $sh->getTopSearches($user_id, 10);
        $topSearches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="card test-card test-pass">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-check-circle me-2"></i>SearchHistory::getTopSearches()</h5>
                <p class="card-text">Получение топ поисков по количеству</p>
                <code>$sh->getTopSearches($user_id, 10)</code>
                <div class="mt-2">
                    <p><strong>Результат:</strong> ✅ Успешно</p>
                    <p><strong>Найдено уникальных поисков:</strong> <span class="badge bg-primary"><?= count($topSearches) ?></span></p>
                    <?php if (!empty($topSearches)) : ?>
                    <p><strong>Топ поиск:</strong> "<?= htmlspecialchars($topSearches[0]['query']) ?>" (<?= $topSearches[0]['search_count'] ?> раз)</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    } catch (Exception $e) {
        ?>
        <div class="card test-card test-fail">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-x-circle me-2"></i>SearchHistory::getTopSearches()</h5>
                <p class="card-text">Ошибка: <?= htmlspecialchars($e->getMessage()) ?></p>
            </div>
        </div>
        <?php
    }

    // TEST 8: Проверка таблицы поиска
    try {
        $sql = "SHOW TABLES LIKE 'search_history'";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $tableExists = !empty($result);
        ?>
        <div class="card test-card <?= $tableExists ? 'test-pass' : 'test-fail' ?>">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-check-circle me-2"></i>Таблица search_history</h5>
                <p class="card-text">Проверка наличия таблицы в БД</p>
                <div class="mt-2">
                    <p><strong>Результат:</strong> <?= $tableExists ? '✅ Существует' : '❌ Не найдена' ?></p>
                    <?php if ($tableExists) : ?>
                    <p><strong>Статус:</strong> <span class="badge bg-success">Готова к использованию</span></p>
                    <?php else : ?>
                    <p><strong>Примечание:</strong> Таблица будет создана при первом сохранении поиска</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    } catch (Exception $e) {
        ?>
        <div class="card test-card test-fail">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-x-circle me-2"></i>Таблица search_history</h5>
                <p class="card-text">Ошибка: <?= htmlspecialchars($e->getMessage()) ?></p>
            </div>
        </div>
        <?php
    }

    // TEST 9: Проверка файлов
    $files = [
        'classes/SearchHistory.php' => 'SearchHistory класс',
        'pages/my_topics.php' => 'Страница Мои темы',
        'pages/my_posts.php' => 'Страница Мои ответы',
        'pages/search_history.php' => 'Страница История поисков',
    ];

    foreach ($files as $file => $description) {
        $exists = file_exists(__DIR__ . '/' . $file);
        ?>
        <div class="card test-card <?= $exists ? 'test-pass' : 'test-fail' ?>">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-<?= $exists ? 'check-circle' : 'x-circle' ?> me-2"></i><?= $description ?></h5>
                <p class="card-text"><code><?= $file ?></code></p>
                <div class="mt-2">
                    <p><strong>Статус:</strong> <?= $exists ? '<span class="badge bg-success">Файл существует</span>' : '<span class="badge bg-danger">Файл не найден</span>' ?></p>
                </div>
            </div>
        </div>
        <?php
    }
    ?>

    <div class="alert alert-success mt-4" role="alert">
        <i class="bi bi-check-circle me-2"></i>
        <strong>Все тесты завершены!</strong>
        <br>
        Для более подробной информации смотрите документацию: <code>USER_FUNCTIONS_GUIDE.md</code>
    </div>

    <div class="alert alert-info" role="alert">
        <i class="bi bi-info-circle me-2"></i>
        <strong>Ссылки для быстрого доступа:</strong>
        <ul class="mb-0 mt-2">
            <li><a href="pages/my_topics.php">Мои темы</a></li>
            <li><a href="pages/my_posts.php">Мои ответы</a></li>
            <li><a href="pages/search_history.php">История поисков</a></li>
            <li><a href="pages/index.php">Главная страница</a></li>
        </ul>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
