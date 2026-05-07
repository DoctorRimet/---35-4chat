<?php

namespace ForumChat;

class Topic
{
    private $conn;
    private $table = 'topics';

    public $id;
    public $title;
    public $description;
    public $category_id;
    public $author_id;
    public $status;
    public $is_pinned;
    public $created_at;
    public $updated_at;
    public $deleted;

    public function __construct($db)
    {
        $this->conn = $db;
        $this->ensureTagsTableExists();
        $this->ensureIsPinnedColumnExists();
    }

    private function ensureIsPinnedColumnExists()
    {
        try {
            $stmt = $this->conn->prepare("SHOW COLUMNS FROM topics WHERE Field = 'is_pinned'");
            $stmt->execute();
            if ($stmt->rowCount() === 0) {
                $this->conn->exec("ALTER TABLE topics ADD COLUMN is_pinned TINYINT(1) DEFAULT 0 AFTER status");
            }
        } catch (Exception $e) {
            // Колонка уже существует или другая ошибка
        }
    }

    private function ensureTagsTableExists()
    {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS `topic_tags` (
                `topic_id` bigint(20) NOT NULL,
                `tag_id` bigint(20) NOT NULL,
                PRIMARY KEY (`topic_id`, `tag_id`),
                KEY `idx_tag_id` (`tag_id`),
                CONSTRAINT `fk_topic_tags_topic` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk_topic_tags_tag` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";
            $this->conn->exec($sql);
        } catch (Exception $e) {
            // Таблица уже существует или другая ошибка, игнорируем
        }
    }

    public function create()
    {
        $sql = "INSERT INTO {$this->table}
                (title, description, category_id, author_id, status, is_pinned)
                VALUES (:title, :description, :category_id, :author_id, :status, :is_pinned)";
        $stmt = $this->conn->prepare($sql);

        $this->title = htmlspecialchars(strip_tags($this->title));
        $this->description = htmlspecialchars(strip_tags($this->description));
        $this->status = $this->status ?: 'open';
        $this->is_pinned = $this->is_pinned ?: 0;

        $stmt->bindParam(':title', $this->title);
        $stmt->bindParam(':description', $this->description);
        $stmt->bindParam(':category_id', $this->category_id);
        $stmt->bindParam(':author_id', $this->author_id);
        $stmt->bindParam(':status', $this->status);
        $stmt->bindParam(':is_pinned', $this->is_pinned);

        if ($stmt->execute()) {
            $this->id = $this->conn->lastInsertId();
            return true;
        }
        return false;
    }

    public function getById($id)
    {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE id=:id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getByAuthorId($author_id)
    {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE author_id=:author_id");
        $stmt->bindParam(':author_id', $author_id);
        $stmt->execute();
        return $stmt;
    }

    public function update()
    {
        $sql = "UPDATE {$this->table}
                SET title=:title, description=:description, category_id=:category_id, status=:status, is_pinned=:is_pinned
                WHERE id=:id";
        $stmt = $this->conn->prepare($sql);

        $this->title = htmlspecialchars(strip_tags($this->title));
        $this->description = htmlspecialchars(strip_tags($this->description));
        $this->is_pinned = $this->is_pinned ?: 0;

        $stmt->bindParam(':title', $this->title);
        $stmt->bindParam(':description', $this->description);
        $stmt->bindParam(':category_id', $this->category_id);
        $stmt->bindParam(':status', $this->status);
        $stmt->bindParam(':is_pinned', $this->is_pinned);
        $stmt->bindParam(':id', $this->id);

        return $stmt->execute();
    }

    public function getDraftsByAuthor($author_id)
    {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE author_id=:author_id AND status='draft'");
        $stmt->bindParam(':author_id', $author_id);
        $stmt->execute();
        return $stmt;
    }

    public function delete($id)
    {
        // Удаляем сначала все комментарии к постам этой темы
        $sql = "DELETE FROM comments WHERE post_id IN (
                    SELECT id FROM posts WHERE topic_id = :id
                )";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        // Удаляем все посты этой темы
        $sql = "DELETE FROM posts WHERE topic_id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        // Удаляем саму тему
        $stmt = $this->conn->prepare("DELETE FROM {$this->table} WHERE id=:id");
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function getAll($tag = null)
    {
        if ($tag) {
            $sql = "SELECT t.* FROM {$this->table} t
                    JOIN topic_tags tt ON tt.topic_id = t.id
                    JOIN tags tg ON tg.id = tt.tag_id
                    WHERE t.status != 'draft' AND LOWER(tg.name) = LOWER(:tag)
                    ORDER BY t.is_pinned DESC, t.created_at DESC";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':tag', $tag);
            $stmt->execute();
            return $stmt;
        }

        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE status != 'draft' ORDER BY is_pinned DESC, created_at DESC");
        $stmt->execute();
        return $stmt;
    }

    public function getTagsByTopic($topic_id)
    {
        $sql = "SELECT tg.id, tg.name FROM tags tg
                JOIN topic_tags tt ON tt.tag_id = tg.id
                WHERE tt.topic_id = :topic_id
                ORDER BY tg.name ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':topic_id', $topic_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPopularTags($limit = 10)
    {
        $sql = "SELECT tg.name, COUNT(tt.topic_id) AS topic_count FROM tags tg
                JOIN topic_tags tt ON tt.tag_id = tg.id
                WHERE tg.type = 'topic'
                GROUP BY tg.id
                ORDER BY topic_count DESC, tg.name ASC
                LIMIT :limit";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveTagsForTopic($topic_id, array $tags)
    {
        $this->clearTags($topic_id);
        foreach ($tags as $tagName) {
            $tagName = trim($tagName);
            if ($tagName === '') {
                continue;
            }
            $tagId = $this->getTagIdByName($tagName);
            if (!$tagId) {
                $tagId = $this->createTag($tagName);
            }
            if ($tagId) {
                $insert = $this->conn->prepare("INSERT IGNORE INTO topic_tags (topic_id, tag_id) VALUES (:topic_id, :tag_id)");
                $insert->bindParam(':topic_id', $topic_id);
                $insert->bindParam(':tag_id', $tagId);
                $insert->execute();
            }
        }
        return true;
    }

    public function clearTags($topic_id)
    {
        $stmt = $this->conn->prepare("DELETE FROM topic_tags WHERE topic_id = :topic_id");
        $stmt->bindParam(':topic_id', $topic_id);
        return $stmt->execute();
    }

    public function getTagIdByName($name)
    {
        $sql = "SELECT id FROM tags WHERE LOWER(name) = LOWER(:name) LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':name', $name);
        $stmt->execute();
        $tag = $stmt->fetch(PDO::FETCH_ASSOC);
        return $tag['id'] ?? null;
    }

    public function createTag($name)
    {
        $normalized = trim($name);
        if ($normalized === '') {
            return null;
        }

        $existingTag = $this->getTagIdByName($normalized);
        if ($existingTag) {
            return $existingTag;
        }

        $sql = "INSERT INTO tags (name, type) VALUES (:name, 'topic')";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':name', $normalized);
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return null;
    }

    // Управление статусом темы (открыта/закрыта)
    public function setStatus($id, $status)
    {
        if (!in_array($status, ['open', 'closed', 'archived', 'draft'])) {
            return false;
        }
        $sql = "UPDATE {$this->table} SET status=:status WHERE id=:id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    // Закрепить/открепить тему
    public function setPinned($id, $pinned)
    {
        $pinned = (int)$pinned;
        $sql = "UPDATE {$this->table} SET is_pinned=:pinned WHERE id=:id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':pinned', $pinned);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    // Переименовать тему
    public function renameTitle($id, $newTitle)
    {
        $newTitle = htmlspecialchars(strip_tags($newTitle));
        if (mb_strlen($newTitle) < 3 || mb_strlen($newTitle) > 255) {
            return false;
        }
        $sql = "UPDATE {$this->table} SET title=:title WHERE id=:id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':title', $newTitle);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    // Переместить тему в другую категорию
    public function moveToCategory($id, $categoryId)
    {
        $categoryId = $categoryId ? (int)$categoryId : null;
        $sql = "UPDATE {$this->table} SET category_id=:category_id WHERE id=:id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':category_id', $categoryId);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    // Проверить закрыта ли тема
    public function isClosed($id)
    {
        $stmt = $this->conn->prepare("SELECT status FROM {$this->table} WHERE id=:id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result && $result['status'] === 'closed';
    }

    // Получить темы по категории
    public function getByCategory($category_id)
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE category_id = :category_id AND status != 'draft' 
                ORDER BY is_pinned DESC, created_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':category_id', $category_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt;
    }

    // Получить темы с информацией о категории
    public function getAllWithCategory()
    {
        $sql = "SELECT t.*, c.name as category_name, c.id as category_id 
                FROM {$this->table} t 
                LEFT JOIN categories c ON c.id = t.category_id 
                WHERE t.status != 'draft' 
                ORDER BY t.is_pinned DESC, t.created_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt;
    }

    // Получить количество всех тем для пагинации
    public function getTotalCount($category_id = null)
    {
        if ($category_id) {
            $sql = "SELECT COUNT(*) as total FROM {$this->table} WHERE category_id = :category_id AND status != 'draft'";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':category_id', $category_id, PDO::PARAM_INT);
        } else {
            $sql = "SELECT COUNT(*) as total FROM {$this->table} WHERE status != 'draft'";
            $stmt = $this->conn->prepare($sql);
        }
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['total'] ?? 0);
    }

    // Получить темы с пагинацией
    public function getPaginated($page = 1, $limit = 20, $category_id = null)
    {
        $page = max(1, (int)$page);
        $limit = max(1, min(100, (int)$limit)); // От 1 до 100
        $offset = ($page - 1) * $limit;

        if ($category_id) {
            $sql = "SELECT * FROM {$this->table} 
                    WHERE category_id = :category_id AND status != 'draft' 
                    ORDER BY is_pinned DESC, created_at DESC 
                    LIMIT :limit OFFSET :offset";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':category_id', $category_id, PDO::PARAM_INT);
        } else {
            $sql = "SELECT * FROM {$this->table} 
                    WHERE status != 'draft' 
                    ORDER BY is_pinned DESC, created_at DESC 
                    LIMIT :limit OFFSET :offset";
            $stmt = $this->conn->prepare($sql);
        }

        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt;
    }

    // Получить недавние обсуждения (темы с последними постами)
    public function getRecentDiscussions($limit = 5)
    {
        $sql = "SELECT t.*, 
                (SELECT MAX(created_at) FROM posts WHERE topic_id = t.id) as last_post_date,
                (SELECT COUNT(*) FROM posts WHERE topic_id = t.id) as posts_count
                FROM {$this->table} t 
                WHERE t.status != 'draft' 
                ORDER BY last_post_date DESC, t.created_at DESC 
                LIMIT :limit";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt;
    }

    // Получить популярные темы (по количеству постов)
    public function getPopularTopics($limit = 5)
    {
        $sql = "SELECT t.*, 
                (SELECT COUNT(*) FROM posts WHERE topic_id = t.id) as posts_count,
                (SELECT COUNT(*) FROM comments WHERE post_id IN (SELECT id FROM posts WHERE topic_id = t.id)) as comments_count,
                ((SELECT COUNT(*) FROM posts WHERE topic_id = t.id) + 
                 (SELECT COUNT(*) FROM comments WHERE post_id IN (SELECT id FROM posts WHERE topic_id = t.id))) as total_engagement
                FROM {$this->table} t 
                WHERE t.status != 'draft' 
                ORDER BY total_engagement DESC, t.created_at DESC 
                LIMIT :limit";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt;
    }

    // Получить темы пользователя
    public function getByUserId($user_id, $limit = null)
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE author_id = :user_id AND status != 'draft'
                ORDER BY created_at DESC";

        if ($limit) {
            $sql .= " LIMIT :limit";
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        if ($limit) {
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt;
    }

    // Получить количество тем пользователя
    public function getCountByUserId($user_id)
    {
        $sql = "SELECT COUNT(*) as total FROM {$this->table} 
                WHERE author_id = :user_id AND status != 'draft'";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['total'] ?? 0);
    }
}
