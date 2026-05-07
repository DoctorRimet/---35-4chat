<?php
class Comment {
    private $conn;
    private $table = 'comments';

    public $id;
    public $post_id;
    public $author_id;
    public $parent_comment_id;
    public $content;
    public $created_at;
    public $updated_at;
    public $deleted;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create() {
        $sql = 'INSERT INTO ' . $this->table . ' (post_id, author_id, parent_comment_id, content, deleted) VALUES (:post_id, :author_id, :parent_comment_id, :content, 0)';
        $stmt = $this->conn->prepare($sql);

        $this->content = htmlspecialchars(strip_tags($this->content));

        $stmt->bindParam(':post_id', $this->post_id);
        $stmt->bindParam(':author_id', $this->author_id);
        $stmt->bindParam(':parent_comment_id', $this->parent_comment_id);
        $stmt->bindParam(':content', $this->content);

        if ($stmt->execute()) {
            $this->id = $this->conn->lastInsertId();
            return true;
        }
        return false;
    }

    public function getByPostId($post_id) {
        $sql = 'SELECT c.*, u.username FROM ' . $this->table . ' c
                JOIN users u ON u.id = c.author_id
                WHERE c.post_id = :post_id AND c.deleted = 0
                ORDER BY c.created_at ASC';

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':post_id', $post_id);
        $stmt->execute();

        return $stmt;
    }

    public function getById($id) {
        $sql = 'SELECT c.*, u.username FROM ' . $this->table . ' c
                JOIN users u ON u.id = c.author_id
                WHERE c.id = :id LIMIT 1';

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getByUserId($user_id) {
        $sql = 'SELECT c.id, c.post_id, c.content, c.created_at, p.topic_id, t.title AS topic_title
                FROM ' . $this->table . ' c
                JOIN posts p ON p.id = c.post_id
                JOIN topics t ON t.id = p.topic_id
                WHERE c.author_id = :author_id
                  AND c.deleted = 0
                ORDER BY c.created_at DESC';

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':author_id', $user_id);
        $stmt->execute();

        return $stmt;
    }

    public function getRecentByUserId($user_id, $limit = 3) {
        $sql = 'SELECT c.id, c.post_id, c.content, c.created_at, p.topic_id, t.title AS topic_title
                FROM ' . $this->table . ' c
                JOIN posts p ON p.id = c.post_id
                JOIN topics t ON t.id = p.topic_id
                WHERE c.author_id = :author_id
                  AND c.deleted = 0
                ORDER BY c.created_at DESC
                LIMIT :limit';

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':author_id', $user_id);
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt;
    }

    public function countByUserId($user_id) {
        $sql = 'SELECT COUNT(*) AS total FROM ' . $this->table . ' WHERE author_id = :author_id AND deleted = 0';
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':author_id', $user_id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['total'] : 0;
    }

    public function countByPostId($post_id) {
        $sql = 'SELECT COUNT(*) AS total FROM ' . $this->table . ' WHERE post_id = :post_id AND deleted = 0';
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':post_id', $post_id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['total'] : 0;
    }

    public function update() {
        $sql = 'UPDATE ' . $this->table . ' SET
                content = :content
                WHERE id = :id';

        $stmt = $this->conn->prepare($sql);

        $this->content = htmlspecialchars(strip_tags($this->content));

        $stmt->bindParam(':content', $this->content);
        $stmt->bindParam(':id', $this->id);

        return $stmt->execute();
    }

    public function delete($id) {
        $sql = 'UPDATE ' . $this->table . '
                SET deleted = 1
                WHERE id = :id';

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $id);

        return $stmt->execute();
    }
}
