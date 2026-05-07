# CHANGELOG

## [1.0.0] - 2025-05-04

### Добавлено
- REST API: четыре endpoint (read, create, update, delete)
- Валидация всех форм на сервере
- Система ролей: admin, user, moderation, anonim
- CI/CD: GitHub Actions с автоматической проверкой синтаксиса и стиля PHP
- Postman-коллекция с автотестами для всех API endpoints
- Файл .htaccess с правилами безопасности и маршрутизации
- Конфигурационный файл с параметрами среды (.env)
- Техническая документация в YouTrack Knowledge Base
- README.md с инструкцией запуска

### Исправлено
- Исправлена синтаксическая ошибка в topic.php (дублирующий elseif)
- Исправлена ошибка кодировки в JSON (добавлен JSON_UNESCAPED_UNICODE)
- Исправлены нарушения стиля PSR-12 в 64 файлах (1381 ошибка)
- Исправлен BOM-символ в composer.json
- Переименован метод test_example в testExample согласно camelCase

### Безопасность
- Добавлена серверная валидация всех форм
- Защита страниц через requireLogin() и requireRole()
- Применён htmlspecialchars() для защиты от XSS-атак
- Запрет выполнения PHP в папке uploads через .htaccess
