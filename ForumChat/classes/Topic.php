<?php
class Topic {

    private $conn;
    private $table = 'topics';

    public $id;
    public $title;
    public $author_id;
    public $status;
    public $created_at;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create() {
        $sql = "INSERT INTO {$this->table}
                (title, author_id, status)
                VALUES (:title, :author_id, 'open')";
        $stmt = $this->conn->prepare($sql);

        $this->title = htmlspecialchars(strip_tags($this->title));

        $stmt->bindParam(':title', $this->title);
        $stmt->bindParam(':author_id', $this->author_id);

        return $stmt->execute();
    }

    public function getAll() {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table}");
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
                SET title=:title, status=:status
                WHERE id=:id";
        $stmt = $this->conn->prepare($sql);

        $stmt->bindParam(':title', $this->title);
        $stmt->bindParam(':status', $this->status);
        $stmt->bindParam(':id', $this->id);

        return $stmt->execute();
    }

    public function delete($id) {
        $stmt = $this->conn->prepare("DELETE FROM {$this->table} WHERE id=:id");
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }
}
?>