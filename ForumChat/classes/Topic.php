<?php
class Topic {

    private $conn;
    private $table = 'topics';

    public $id;
    public $title;
    public $description;
    public $category_id;
    public $author_id;
    public $status;
    public $created_at;
    public $updated_at;
    public $deleted;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create() {
        $sql = "INSERT INTO {$this->table}
                (title, description, category_id, author_id, status)
                VALUES (:title, :description, :category_id, :author_id, :status)";
        $stmt = $this->conn->prepare($sql);

        $this->title = htmlspecialchars(strip_tags($this->title));
        $this->description = htmlspecialchars(strip_tags($this->description));
        $this->status = $this->status ?: 'open';

        $stmt->bindParam(':title', $this->title);
        $stmt->bindParam(':description', $this->description);
        $stmt->bindParam(':category_id', $this->category_id);
        $stmt->bindParam(':author_id', $this->author_id);
        $stmt->bindParam(':status', $this->status);

        if ($stmt->execute()) {
            $this->id = $this->conn->lastInsertId();
            return true;
        }
        return false;
    }

    public function getAll() {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE status != 'draft' ORDER BY created_at DESC");
        $stmt->execute();
        return $stmt;
    }

    public function getById($id) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE id=:id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getByAuthorId($author_id) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE author_id=:author_id");
        $stmt->bindParam(':author_id', $author_id);
        $stmt->execute();
        return $stmt;
    }

    public function update() {
        $sql = "UPDATE {$this->table}
                SET title=:title, description=:description, category_id=:category_id, status=:status
                WHERE id=:id";
        $stmt = $this->conn->prepare($sql);

        $this->title = htmlspecialchars(strip_tags($this->title));
        $this->description = htmlspecialchars(strip_tags($this->description));

        $stmt->bindParam(':title', $this->title);
        $stmt->bindParam(':description', $this->description);
        $stmt->bindParam(':category_id', $this->category_id);
        $stmt->bindParam(':status', $this->status);
        $stmt->bindParam(':id', $this->id);

        return $stmt->execute();
    }

    public function getDraftsByAuthor($author_id) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE author_id=:author_id AND status='draft'");
        $stmt->bindParam(':author_id', $author_id);
        $stmt->execute();
        return $stmt;
    }

    public function delete($id) {
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
}
?>