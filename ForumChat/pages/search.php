<?php

/**
 * Страница глобального поиска по форуму
 * Доступна для всех пользователей (авторизованных и анонимных)
 */

require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/SearchHistory.php';

$db = new Database();
$conn = $db->getConnection();
$searchHistory = new SearchHistory($conn);

// Сохранить поиск в историю, если пользователь авторизован
$query = isset($_GET['q']) ? trim($_GET['q']) : '';
if (!$is_guest && !empty($query)) {
    $searchHistory->addSearch($_SESSION['user_id'], $query);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Поиск - ForumChat</title>
    <link rel="stylesheet" href="../assets/search.css">
    <link rel="stylesheet" href="../assets/filter.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 20px;">
                <a href="/" style="color: #007bff; text-decoration: none; font-size: 18px;">← Назад на главную</a>
                <span style="color: #ddd;">|</span>
                <span style="color: #666;">Версия: Поиск v1.0</span>
            </div>
            
            <h1>🔍 Глобальный поиск</h1>

            <!-- Форма поиска -->
            <div class="search-container">
                <div style="display: flex; gap: 10px; align-items: center;">
                    <div class="search-box" style="flex: 1;">
                        <input 
                            type="text" 
                            id="search-input"
                            placeholder="Введите минимум 3 символа для поиска..."
                            autocomplete="off"
                        >
                        <button class="search-btn" data-search-btn>
                            Поиск
                        </button>
                    </div>
                    <button class="filter-toggle-btn" data-filter-toggle title="Фильтры поиска">
                        <i class="bi bi-funnel"></i> Фильтры
                    </button>
                </div>
                <small class="search-help">
                    ⓘ Поиск работает по названиям тем и содержанию постов. Минимум 3 символа.
                </small>
            </div>

            <!-- Результаты поиска -->
            <div id="search-results" class="search-results-area"></div>
        </div>

        <!-- Модальное окно фильтров -->
        <div id="filterModal" class="filter-modal">
            <div class="filter-modal-content">
                <div class="filter-modal-header">
                    <h2>⚙️ Фильтры поиска</h2>
                    <button class="filter-close-btn" data-filter-close>✕</button>
                </div>

                <div class="filter-group">
                    <label for="filterSort">Сортировка</label>
                    <select id="filterSort">
                        <option value="date_desc">Новые сначала</option>
                        <option value="date_asc">Старые сначала</option>
                        <option value="replies">По количеству ответов</option>
                        <option value="popularity">По популярности</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filterAuthor">ID Автора (оставьте пусто для всех)</label>
                    <input type="number" id="filterAuthor" placeholder="Введите ID автора" min="1">
                </div>

                <div class="filter-group">
                    <label>Период создания</label>
                    <div class="filter-date-range">
                        <input type="date" id="filterDateFrom" placeholder="От">
                        <input type="date" id="filterDateTo" placeholder="До">
                    </div>
                </div>

                <div class="filter-group">
                    <label for="filterMinReplies">Минимум ответов</label>
                    <input type="number" id="filterMinReplies" value="0" min="0" placeholder="0">
                </div>

                <div class="filter-actions">
                    <button class="filter-btn filter-btn-apply" data-filter-apply>Применить</button>
                    <button class="filter-btn filter-btn-reset" data-filter-reset>Сброс</button>
                </div>
            </div>
        </div>

        <!-- Справка -->
        <div class="info-box">
            <div class="info-content">
                <h5>ℹ️ Как использовать поиск?</h5>
                <ul>
                    <li>Введите <strong>минимум 3 символа</strong> для поиска</li>
                    <li>Поиск работает по названиям тем и содержанию постов</li>
                    <li>Найденные слова выделены <mark class="search-highlight">жёлтым</mark></li>
                    <li>Доступен для всех пользователей (авторизованных и анонимных)</li>
                    <li>Скрытые посты не отображаются в результатах</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../assets/search.js"></script>
    <script src="../assets/filter.js"></script>
    <script>
        // Переходим на страницу поиска и выполняем поиск, если в URL есть параметр q
        document.addEventListener('DOMContentLoaded', function() {
            // Инициализируем поиск
            window.searchInstance = new ForumSearch({
                searchUrl: '/api/search.php',
                inputSelector: '#search-input',
                resultsSelector: '#search-results'
            });
            
            const urlParams = new URLSearchParams(window.location.search);
            const query = urlParams.get('q');
            
            if (query) {
                document.getElementById('search-input').value = query;
                window.searchInstance.search();
            }
        });
    </script>
</body>
</html>
