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
    public $last_failed_at;
    public $two_factor_secret;
    public $two_factor_enabled;
    public $created_at;
    public $updated_at;

    const MAX_FAILED_ATTEMPTS = 5;
    const LOCK_DURATION_MINUTES = 15;
    const MAX_SESSIONS = 1000;
    const MAX_USERNAME_LENGTH = 30;
    const MIN_PASSWORD_LENGTH = 8;

    const ROLE_USER = 'user';
    const ROLE_MODERATOR = 'moderator';
    const ROLE_ADMIN = 'admin';

    const PASSWORD_ALGO = PASSWORD_DEFAULT;
    const TOTP_WINDOW = 1;
    const TOTP_PERIOD = 30;

    public static function getPasswordAlgo() {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : self::PASSWORD_ALGO;
    }

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
            $this->assignRole($this->id, self::ROLE_USER);
            return true;
        }
        return false;
    }

    public static function hashPassword($password) {
        return password_hash($password, self::getPasswordAlgo());
    }

    public function getRoles($user_id) {
        $stmt = $this->conn->prepare(
            "SELECT r.name
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user_id"
        );
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    }

    public function getPrimaryRole($user_id) {
        $roles = $this->getRoles($user_id);
        if (in_array(self::ROLE_ADMIN, $roles, true)) {
            return self::ROLE_ADMIN;
        }
        if (in_array(self::ROLE_MODERATOR, $roles, true)) {
            return self::ROLE_MODERATOR;
        }
        return self::ROLE_USER;
    }

    public function userHasRole($user_id, $role_name) {
        $roles = $this->getRoles($user_id);
        return in_array($role_name, $roles, true);
    }

    public function assignRole($user_id, $role_name) {
        $role_id = $this->getRoleIdByName($role_name);
        if (!$role_id) {
            return false;
        }
        $stmt = $this->conn->prepare(
            "INSERT IGNORE INTO user_roles (user_id, role_id)
             VALUES (:user_id, :role_id)"
        );
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':role_id', $role_id);
        return $stmt->execute();
    }

    public function removeRole($user_id, $role_name) {
        $role_id = $this->getRoleIdByName($role_name);
        if (!$role_id) {
            return false;
        }
        $stmt = $this->conn->prepare(
            "DELETE FROM user_roles WHERE user_id = :user_id AND role_id = :role_id"
        );
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':role_id', $role_id);
        return $stmt->execute();
    }

    private function getRoleIdByName($role_name) {
        $stmt = $this->conn->prepare("SELECT id FROM roles WHERE name = :name LIMIT 1");
        $stmt->bindParam(':name', $role_name);
        $stmt->execute();
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        return $role['id'] ?? null;
    }

    public function generateTwoFactorSecret($length = 16) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }
        return $secret;
    }

    private function base32Decode($secret) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper($secret);
        $paddingCharCount = substr_count($secret, '=');
        $allowedValues = array_flip(str_split($alphabet));
        $secret = str_replace('=', '', $secret);
        $binaryString = '';
        foreach (str_split($secret) as $char) {
            if (!isset($allowedValues[$char])) {
                return false;
            }
            $binaryString .= str_pad(decbin($allowedValues[$char]), 5, '0', STR_PAD_LEFT);
        }
        $decoded = '';
        foreach (str_split($binaryString, 8) as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr(bindec($byte));
            }
        }
        return $decoded;
    }

    private function getTotpCode($secret, $timeSlice = null) {
        $secretKey = $this->base32Decode($secret);
        if ($secretKey === false) {
            return false;
        }
        $timeSlice = $timeSlice ?? floor(time() / self::TOTP_PERIOD);
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncated = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;
        return str_pad($truncated % 1000000, 6, '0', STR_PAD_LEFT);
    }

    public function verifyTwoFactorCode($secret, $code, $window = self::TOTP_WINDOW) {
        if (empty($secret) || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $timeSlice = floor(time() / self::TOTP_PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->getTotpCode($secret, $timeSlice + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    public function enableTwoFactor($user_id, $secret) {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table}
             SET two_factor_enabled = 1, two_factor_secret = :secret
             WHERE id = :id"
        );
        $stmt->bindParam(':secret', $secret);
        $stmt->bindParam(':id', $user_id);
        return $stmt->execute();
    }

    public function disableTwoFactor($user_id) {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table}
             SET two_factor_enabled = 0, two_factor_secret = NULL
             WHERE id = :id"
        );
        $stmt->bindParam(':id', $user_id);
        return $stmt->execute();
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
        $user = $this->getById($id);
        if (!$user) {
            return false;
        }

        $lastFailedAt = !empty($user['last_failed_at']) ? strtotime($user['last_failed_at']) : 0;
        $now = time();
        $failedAttempts = 1;

        if ($lastFailedAt && $lastFailedAt > $now - self::LOCK_DURATION_MINUTES * 60) {
            $failedAttempts = $user['failed_attempts'] + 1;
        }

        $lockedUntil = null;
        if ($failedAttempts >= self::MAX_FAILED_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', $now + self::LOCK_DURATION_MINUTES * 60);
        }

        $stmt = $this->conn->prepare(
            "UPDATE {$this->table}
             SET failed_attempts = :failed_attempts,
                 last_failed_at = :last_failed_at,
                 locked_until = :locked_until
             WHERE id = :id"
        );
        $stmt->bindParam(':failed_attempts', $failedAttempts, PDO::PARAM_INT);
        $lastFailedAtSql = date('Y-m-d H:i:s', $now);
        $stmt->bindParam(':last_failed_at', $lastFailedAtSql);
        $stmt->bindParam(':locked_until', $lockedUntil);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        if ($stmt->execute()) {
            return $failedAttempts;
        }
        return false;
    }

    public function resetFailedAttempts($id) {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table}
             SET failed_attempts = 0, locked_until = NULL, last_failed_at = NULL
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

    public function createSession($user_id, $remember = false) {
        $token = bin2hex(random_bytes(32));
        $expires = $remember
            ? date('Y-m-d H:i:s', strtotime('+30 days'))
            : date('Y-m-d H:i:s', strtotime('+1 day'));
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
