<?php

namespace ForumChat;

class AutoModerator
{
    private $conn;
    private $postMod;
    private $forbiddenWords;
    private $admin_id = 1; // Системный аккаунт админа

    public function __construct($db, $postModeration = null, $forbiddenWordsClass = null)
    {
        $this->conn = $db;
        $this->postMod = $postModeration ?? new PostModeration($db);
        $this->forbiddenWords = $forbiddenWordsClass ?? new ForbiddenWords($db);
    }

    /**
     * Проверить пост при создании
     */
    public function checkPostOnCreate($post_id, $content, $author_id)
    {
        $checkResult = $this->forbiddenWords->checkContentDetailed($content);

        if ($checkResult['has_forbidden']) {
            // Скрыть пост автоматически
            $this->hidePostAutomatic(
                $post_id,
                $author_id,
                'Пост содержит запрещённые слова: ' . implode(', ', array_keys($checkResult['words_found']))
            );

            return [
                'hidden' => true,
                'reason' => 'Пост содержит запрещённые слова и был скрыт системой',
                'violations' => $checkResult['words_found']
            ];
        }

        return ['hidden' => false];
    }

    /**
     * Скрыть пост автоматически (системой)
     */
    private function hidePostAutomatic($post_id, $author_id, $reason)
    {
        // Скрываем пост
        $this->postMod->hidePost($post_id, $reason, $this->admin_id);

        // Создаём метку на скрытие
        $hideSql = "INSERT INTO post_deletion_marks (post_id, marked_by, reason, hidden) 
                   VALUES (:post_id, :marked_by, :reason, 1)";
        $stmt = $this->conn->prepare($hideSql);
        $stmt->bindParam(':post_id', $post_id);
        $stmt->bindParam(':marked_by', $this->admin_id);
        $stmt->bindParam(':reason', $reason);
        $stmt->execute();

        // Уведомляем автора
        $message = $reason . ' Свяжитесь с модератором если вы считаете это ошибкой.';
        $this->postMod->createNotification(
            $author_id,
            'post_auto_hidden',
            $message
        );

        // Логируем
        $this->logAction($this->admin_id, 'auto_hide_post', 'post', $post_id, $reason);
    }

    /**
     * Проверить пост при редактировании
     */
    public function checkPostOnUpdate($post_id, $new_content, $author_id)
    {
        $checkResult = $this->forbiddenWords->checkContentDetailed($new_content);

        if ($checkResult['has_forbidden']) {
            // Скрыть пост
            $this->hidePostAutomatic(
                $post_id,
                $author_id,
                'Отредактированный контент содержит запрещённые слова'
            );

            return [
                'hidden' => true,
                'reason' => 'Отредактированный пост содержит запрещённые слова',
                'violations' => $checkResult['words_found']
            ];
        }

        return ['hidden' => false];
    }

    /**
     * Выполнить ежедневную проверку
     */
    public function dailyCheck()
    {
        $results = [
            'checked' => 0,
            'hidden' => 0,
            'errors' => []
        ];

        try {
            // Получать все видимые посты
            $sql = "SELECT id, content, author_id FROM posts WHERE hidden = 0 AND deleted = 0";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();

            while ($post = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results['checked']++;

                $checkResult = $this->forbiddenWords->checkContentDetailed($post['content']);

                if ($checkResult['has_forbidden']) {
                    $reason = 'Содержит запрещённые слова: ' . implode(', ', array_keys($checkResult['words_found']));

                    $this->hidePostAutomatic($post['id'], $post['author_id'], $reason);
                    $results['hidden']++;
                }
            }
        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Логировать действие системы
     */
    private function logAction($admin_id, $action_type, $target_type, $target_id, $details = null)
    {
        $sql = "INSERT INTO admin_actions (admin_id, action_type, target_type, target_id, details) 
                VALUES (:admin_id, :action_type, :target_type, :target_id, :details)";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':admin_id', $admin_id, PDO::PARAM_INT);
        $stmt->bindParam(':action_type', $action_type);
        $stmt->bindParam(':target_type', $target_type);
        $stmt->bindParam(':target_id', $target_id, PDO::PARAM_INT);
        $stmt->bindParam(':details', $details);

        return $stmt->execute();
    }

    /**
     * Получить статистику модерации
     */
    public function getModerationStats($days = 7)
    {
        $sql = "SELECT 
                    DATE(hidden_at) as date,
                    COUNT(*) as hidden_count
                FROM posts
                WHERE hidden = 1 AND hidden_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY DATE(hidden_at)
                ORDER BY date DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':days', $days, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получить топ запрещённых слов
     */
    public function getTopForbiddenWords($limit = 10)
    {
        $sql = "SELECT fw.word, COUNT(pdm.id) as violation_count
                FROM forbidden_words fw
                LEFT JOIN post_deletion_marks pdm ON FIND_IN_SET(fw.id, pdm.reason) > 0
                GROUP BY fw.id
                ORDER BY violation_count DESC
                LIMIT :limit";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Восстановить пост после проверки админом
     */
    public function unhidePostManually($post_id, $admin_id, $reason = 'Восстановлено администратором')
    {
        $this->postMod->unhidePost($post_id);

        // Удаляем метку
        $deleteSql = "DELETE FROM post_deletion_marks WHERE post_id = :post_id AND hidden = 1";
        $stmt = $this->conn->prepare($deleteSql);
        $stmt->bindParam(':post_id', $post_id);
        $stmt->execute();

        // Логируем
        $this->logAction($admin_id, 'manual_unhide_post', 'post', $post_id, $reason);

        return true;
    }

    /**
     * Установить уровень чувствительности фильтра (для будущего использования)
     */
    public function setFilterSensitivity($level = 'medium')
    {
        $_SESSION['filter_sensitivity'] = $level;
    }

    /**
     * Получить уровень чувствительности
     */
    public function getFilterSensitivity()
    {
        return $_SESSION['filter_sensitivity'] ?? 'medium';
    }
}
