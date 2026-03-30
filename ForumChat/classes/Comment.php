<?php
class Comment {
    private $conn;
    private $table = 'comments';

    public $id;
    public $post_id;
    public $author_id;
    public $content;
    public $created_at;
    public $updated_at;
    public $deleted;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create() {
        $sql = 'INSERT INTO ' . $this->table . ' (post_id, author_id, content, deleted) VALUES (:post_id, :author_id, :content, 0)';
        $stmt = $this->conn->prepare($sql);

        $this->content = htmlspecialchars(strip_tags($this->content));

        $stmt->bindParam(':post_id', $this->post_id);
        $stmt->bindParam(':author_id', $this->author_id);
        $stmt->bindParam(':content', $this->content);

        return $stmt->execute();
    }

    public function getByPostId($post_id) {
        $sql = 'SELECT c.*, u.username FROM ' . $this->table . ' c
                JOIN users u ON u.id = c.author_id
                WHERE c.post_id = :post_id
                  AND c.deleted = 0
                ORDER BY c.created_at ASC';

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':post_id', $post_id);
        $stmt->execute();

        return $stmt;
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

    public function countByPostId($post_id) {
        $sql = 'SELECT COUNT(*) AS total FROM ' . $this->table . ' WHERE post_id = :post_id AND deleted = 0';
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':post_id', $post_id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['total'] : 0;
    }
}
