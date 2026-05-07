# ForumChat

![PHP Checks](https://github.com/DoctorRimet/---35-4chat/actions/workflows/php-checks.yml/badge.svg)
![PHP Version](https://img.shields.io/badge/PHP-8.0-blue)

## Описание
ForumChat — форум для общения пользователей. Позволяет создавать топики, писать комментарии, управлять профилем и модерировать контент. Разработан в рамках учебного проекта в СВГТК им. Абая Кунанбаева.

## Технологии
- PHP 8.0
- MySQL
- Bootstrap

## Установка и запуск
1. Клонируйте репозиторий:git clone https://github.com/DoctorRimet/---35-4chat.git
2. Скопируйте папку в OpenServer/domains/
3. Создайте базу данных и импортируйте database.sql
4. Настройте config/database.php
5. Откройте http://localhost/ForumChat/

## Роли пользователей
| Роль    | Возможности                              |
|---------|------------------------------------------|
| admin   | Полный доступ, модерация, управление     |
| moderation   | Частичный доступ, модерация, управление     |
| user    | Создание топиков, комментарии, профиль   |

## API
Документация API: [Knowledge Base](https://rimet.youtrack.cloud/articles/APP-A-8/Tehnicheskaya-dokumentaciya-Sistema-postinga-i-strutura-obsuzhdeniya)

## Автор
Лютый Артем Юрьевич, студент группы ПВТ-9-23, СВГТК им. Абая Кунанбаева
