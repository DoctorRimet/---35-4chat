<?php

require 'config/database.php';
require 'vendor/autoload.php';

use Faker\Factory;

$faker = Factory::create('ru_RU');

echo "🚀 Начинаем наполнение базы данных...\n";

/* =========================
   ЧАСТЬ 1 — USERS
========================= */

echo "👤 Создаем пользователей... ";

$userIds = [];

for ($i = 0; $i < 10; $i++) {

    $username = $faker->unique()->userName;
    $email = $faker->unique()->safeEmail;
    $password = password_hash('123456', PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, password_hash, status)
        VALUES (?, ?, ?, 'active')
    ");

    $stmt->execute([$username, $email, $password]);

    $userIds[] = $pdo->lastInsertId();
}

echo "Готово!\n";


echo "🧵 Создаем темы... ";

$topicIds = [];

for ($i = 0; $i < 20; $i++) {

    $title = $faker->sentence(6);
    $author = $userIds[array_rand($userIds)];

    $stmt = $pdo->prepare("
        INSERT INTO topics (title, author_id, status)
        VALUES (?, ?, 'open')
    ");

    $stmt->execute([$title, $author]);

    $topicIds[] = $pdo->lastInsertId();
}

echo "Готово!\n";


echo "💬 Создаем сообщения... ";

for ($i = 0; $i < 100; $i++) {

    $content = $faker->realText(200);
    $author = $userIds[array_rand($userIds)];
    $topic = $topicIds[array_rand($topicIds)];

    $stmt = $pdo->prepare("
        INSERT INTO posts (topic_id, author_id, content, deleted)
        VALUES (?, ?, ?, 0)
    ");

    $stmt->execute([$topic, $author, $content]);
}

echo "Готово! (100 сообщений создано)\n";

echo " База данных успешно заполнена!\n";