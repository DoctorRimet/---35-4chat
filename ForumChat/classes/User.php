<?php
class User {
    private $conn;
    private $table = 'users';

    public $id;
    public $username;
    public $email;
    public $password_hash;
    public $status;
    public $failed_attempts;
    public $locked_until;
    public $created_at;
    public $updated_at;

    const MAX_FAILED_ATTEMPTS = 5;
    const LOCK_DURATION_MINUTES = 15;
    const MAX_SESSIONS = 1000;
    const MAX_USERNAME_LENGTH = 30;
    const MIN_PASSWORD_LENGTH = 8;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create() {
        $sql = "INSERT INTO {$this->table}
                (username, email, password_hash, status, failed_attempts)
                VALUES (:username, :email, :password_hash, 'active', 0)";
        $stmt = $this->conn->prepare($sql);
        $this->username = htmlspecialchars(strip_tags($this->username));
        $this->email = htmlspecialchars(strip_tags($this->email));
        $stmt->bindParam(':username', $this->username);
        $stmt->bindParam(':email', $this->email);
        $stmt->bindParam(':password_hash', $this->password_hash);
        if ($stmt->execute()) {
            $this->id = $this->conn->lastInsertId();
            return true;
        }
        return false;
    }

    public function getAll() {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table}");
        $stmt->execute();
        return $stmt;
    }

    public function getById($id) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getByEmail($email) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE email = :email LIMIT 1");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getByUsername($username) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE username = :username LIMIT 1");
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function emailExists($email) {
        $stmt = $this->conn->prepare("SELECT id FROM {$this->table} WHERE email = :email LIMIT 1");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    public function usernameExists($username) {
        $stmt = $this->conn->prepare("SELECT id FROM {$this->table} WHERE username = :username LIMIT 1");
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    public function update() {
        $sql = "UPDATE {$this->table}
                SET username=:username, email=:email, status=:status
                WHERE id=:id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':username', $this->username);
        $stmt->bindParam(':email', $this->email);
        $stmt->bindParam(':status', $this->status);
        $stmt->bindParam(':id', $this->id);
        return $stmt->execute();
    }

    public function delete($id) {
        $stmt = $this->conn->prepare("DELETE FROM {$this->table} WHERE id=:id");
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function incrementFailedAttempts($id) {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table}
             SET failed_attempts = failed_attempts + 1,
                 locked_until = CASE
                     WHEN failed_attempts + 1 >= :max_attempts
                     THEN DATE_ADD(NOW(), INTERVAL :lock_min MINUTE)
                     ELSE locked_until
                 END
             WHERE id = :id"
        );
        $max = self::MAX_FAILED_ATTEMPTS;
        $lock = self::LOCK_DURATION_MINUTES;
        $stmt->bindParam(':max_attempts', $max);
        $stmt->bindParam(':lock_min', $lock);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function resetFailedAttempts($id) {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table}
             SET failed_attempts = 0, locked_until = NULL
             WHERE id = :id"
        );
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function isLocked($user) {
        if (empty($user['locked_until'])) return false;
        return strtotime($user['locked_until']) > time();
    }

    public function getLockRemainingMinutes($user) {
        if (empty($user['locked_until'])) return 0;
        $remaining = strtotime($user['locked_until']) - time();
        return $remaining > 0 ? ceil($remaining / 60) : 0;
    }

    public function countActiveSessions($user_id) {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM sessions
             WHERE user_id = :user_id AND expires_at > NOW()"
        );
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function createSession($user_id) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
        $stmt = $this->conn->prepare(
            "INSERT INTO sessions (user_id, token, expires_at) VALUES (:user_id, :token, :expires_at)"
        );
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':token', $token);
        $stmt->bindParam(':expires_at', $expires);
        $stmt->execute();
        return $token;
    }

    public function validateSession($token) {
        $stmt = $this->conn->prepare(
            "SELECT s.user_id, u.username, u.email, u.status
             FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.token = :token AND s.expires_at > NOW()
             LIMIT 1"
        );
        $stmt->bindParam(':token', $token);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function deleteSession($token) {
        $stmt = $this->conn->prepare("DELETE FROM sessions WHERE token = :token");
        $stmt->bindParam(':token', $token);
        return $stmt->execute();
    }
}
?>