<?php
class Category {

    private $conn;
    private $table = 'categories';

    public $id;
    public $name;
    public $parent_id;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create() {
        $stmt = $this->conn->prepare(
            "INSERT INTO {$this->table} (name, parent_id)
             VALUES (:name, :parent_id)"
        );

        $stmt->bindParam(':name', $this->name);
        $stmt->bindParam(':parent_id', $this->parent_id);

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

    public function update() {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table}
             SET name=:name, parent_id=:parent_id
             WHERE id=:id"
        );

        $stmt->bindParam(':name', $this->name);
        $stmt->bindParam(':parent_id', $this->parent_id);
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