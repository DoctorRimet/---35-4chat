<?php
class Post {

    private $conn;
    private $table = 'posts';

    public $id;
    public $topic_id;
    public $author_id;
    public $content;
    public $status;
    public $created_at;
    public $updated_at;
    public $deleted;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create() {

        $sql = 'INSERT INTO ' . $this->table . '
            (topic_id, author_id, content, status, deleted)
            VALUES (:topic_id, :author_id, :content, :status, 0)';

        $stmt = $this->conn->prepare($sql);

        $this->content = htmlspecialchars(strip_tags($this->content));
        $this->status = $this->status ?: 'published';

        $stmt->bindParam(':topic_id', $this->topic_id);
        $stmt->bindParam(':author_id', $this->author_id);
        $stmt->bindParam(':content', $this->content);
        $stmt->bindParam(':status', $this->status);

        if ($stmt->execute()) {
            $this->id = $this->conn->lastInsertId();
            return true;
        }

        return false;
    }

    public function getAll() {

        $sql = 'SELECT * FROM ' . $this->table . '
                WHERE deleted = 0 AND status = \'published\'
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

    public function getByTopicId($topic_id) {
        $sql = 'SELECT * FROM ' . $this->table . '
                WHERE topic_id = :topic_id AND deleted = 0 AND status = \'published\'
                ORDER BY created_at ASC';

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':topic_id', $topic_id);
        $stmt->execute();
        return $stmt;
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

    public function getDraftsByAuthor($author_id) {
        $sql = 'SELECT * FROM ' . $this->table . '
                WHERE author_id = :author_id AND status = \'draft\' AND deleted = 0
                ORDER BY updated_at DESC';

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':author_id', $author_id);
        $stmt->execute();
        return $stmt;
    }

    public function saveDraft($topic_id, $author_id, $content) {
        // Check if draft already exists for this topic and author
        $sql = 'SELECT id FROM ' . $this->table . '
                WHERE topic_id = :topic_id AND author_id = :author_id AND status = \'draft\' AND deleted = 0
                LIMIT 1';

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':topic_id', $topic_id);
        $stmt->bindParam(':author_id', $author_id);
        $stmt->execute();
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update existing draft
            $this->id = $existing['id'];
            $this->content = $content;
            return $this->update();
        } else {
            // Create new draft
            $this->topic_id = $topic_id;
            $this->author_id = $author_id;
            $this->content = $content;
            $this->status = 'draft';
            return $this->create();
        }
    }

    public function publishDraft($id) {
        $sql = 'UPDATE ' . $this->table . '
                SET status = \'published\'
                WHERE id = :id AND status = \'draft\'';

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }
}
?>