<?php
class Notification {

    private $conn;
    private $table = 'notifications';

    public $id;
    public $user_id;
    public $message;
    public $type;
    public $read_status;
    public $created_at;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create() {
        $stmt = $this->conn->prepare(
            "INSERT INTO {$this->table}
            (user_id, message, type, read_status)
            VALUES (:user_id, :message, :type, 0)"
        );

        $stmt->bindParam(':user_id', $this->user_id);
        $stmt->bindParam(':message', $this->message);
        $stmt->bindParam(':type', $this->type);

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
    public function getByUserId($user_id) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE user_id=:user_id");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        return $stmt;
    }

    public function update() {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table}
             SET message=:message, read_status=:read_status
             WHERE id=:id"
        );

        $stmt->bindParam(':message', $this->message);
        $stmt->bindParam(':read_status', $this->read_status);
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