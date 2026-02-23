<?php
class Post {

    private $conn;
    private $table = 'posts';

    public $id;
    public $topic_id;
    public $author_id;
    public $content;
    public $created_at;
    public $updated_at;
    public $deleted;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create() {

        $sql = 'INSERT INTO ' . $this->table . '
            (topic_id, author_id, content, deleted)
            VALUES (:topic_id, :author_id, :content, 0)';

        $stmt = $this->conn->prepare($sql);

        $this->content = htmlspecialchars(strip_tags($this->content));

        $stmt->bindParam(':topic_id', $this->topic_id);
        $stmt->bindParam(':author_id', $this->author_id);
        $stmt->bindParam(':content', $this->content);

        if ($stmt->execute()) {
            $this->id = $this->conn->lastInsertId();
            return true;
        }

        return false;
    }

    public function getAll() {

        $sql = 'SELECT * FROM ' . $this->table . '
                WHERE deleted = 0
                ORDER BY created_at DESC';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute();

        return $stmt;
    }

    public function getById($id) {

        $sql = 'SELECT * FROM ' . $this->table . '
                WHERE id = :id LIMIT 1';

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $this->id = $row['id'];
            $this->topic_id = $row['topic_id'];
            $this->author_id = $row['author_id'];
            $this->content = $row['content'];
            $this->created_at = $row['created_at'];
            $this->updated_at = $row['updated_at'];
            $this->deleted = $row['deleted'];
            return true;
        }

        return false;
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
?>