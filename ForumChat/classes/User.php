<?php

namespace ForumChat;

class User
{
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
    public $ban_until;
    public $ban_reason;
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

    public static function getPasswordAlgo()
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : self::PASSWORD_ALGO;
    }

    public function __construct($db)
    {
        $this->conn = $db;
    }

    public function create()
    {
        $this->ensureEmailConfirmedColumnExists();

        $sql = "INSERT INTO {$this->table}
                (username, email, password_hash, status, failed_attempts, email_confirmed)
                VALUES (:username, :email, :password_hash, 'active', 0, 0)";
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

    private function ensureEmailConfirmedColumnExists()
    {
        $stmt = $this->conn->query("SHOW COLUMNS FROM {$this->table} LIKE 'email_confirmed'");
        if ($stmt && $stmt->fetch()) {
            return true;
        }

        $sql = "ALTER TABLE {$this->table} ADD COLUMN email_confirmed TINYINT(1) NOT NULL DEFAULT 1";
        $this->conn->exec($sql);
        return true;
    }

    private function ensureEmailConfirmationsTableExists()
    {
        $stmt = $this->conn->query("SHOW TABLES LIKE 'email_confirmations'");
        if ($stmt && $stmt->fetch()) {
            $columnStmt = $this->conn->query("SHOW COLUMNS FROM email_confirmations LIKE 'id'");
            $columnInfo = $columnStmt ? $columnStmt->fetch(PDO::FETCH_ASSOC) : false;
            if ($columnInfo && stripos($columnInfo['Extra'], 'auto_increment') === false) {
                $isKey = !empty($columnInfo['Key']);
                if ($isKey) {
                    $this->conn->exec("ALTER TABLE email_confirmations MODIFY COLUMN id BIGINT(20) NOT NULL AUTO_INCREMENT");
                } else {
                    $this->conn->exec(
                        "ALTER TABLE email_confirmations MODIFY COLUMN id BIGINT(20) NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)"
                    );
                }
            }
            return true;
        }

        $sql = "CREATE TABLE IF NOT EXISTS email_confirmations (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) NOT NULL,
            token VARCHAR(255) NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            UNIQUE KEY token (token),
            CONSTRAINT email_confirmations_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";

        $this->conn->exec($sql);
        return true;
    }

    public function createEmailConfirmationToken($user_id)
    {
        $this->ensureEmailConfirmationsTableExists();

        $token = bin2hex(random_bytes(32));
        $stmt = $this->conn->prepare(
            'INSERT INTO email_confirmations (user_id, token, expires_at, used, created_at)
             VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL 24 HOUR), 0, NOW())'
        );
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':token', $token);
        return $stmt->execute() ? $token : false;
    }

    public function createEmailConfirmationTokenByEmail($email)
    {
        $user = $this->getByEmail($email);
        if (!$user) {
            return false;
        }
        return $this->createEmailConfirmationToken($user['id']);
    }

    public function getEmailConfirmationRequest($token)
    {
        $this->ensureEmailConfirmationsTableExists();

        $stmt = $this->conn->prepare(
            'SELECT ec.*, u.id AS user_id, u.username, u.email
             FROM email_confirmations ec
             JOIN users u ON u.id = ec.user_id
             WHERE ec.token = :token
               AND ec.used = 0
               AND ec.expires_at >= NOW()
             LIMIT 1'
        );
        $stmt->bindParam(':token', $token);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function confirmEmailToken($token)
    {
        $request = $this->getEmailConfirmationRequest($token);
        if (!$request) {
            return false;
        }

        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare(
                'UPDATE users SET email_confirmed = 1 WHERE id = :id'
            );
            $stmt->bindParam(':id', $request['user_id']);
            $updated = $stmt->execute();
            if (!$updated) {
                $this->conn->rollBack();
                return false;
            }

            $stmt = $this->conn->prepare(
                'UPDATE email_confirmations SET used = 1 WHERE token = :token'
            );
            $stmt->bindParam(':token', $token);
            $stmt->execute();

            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollBack();
            return false;
        }
    }

    public function invalidateEmailConfirmationTokens($user_id)
    {
        $stmt = $this->conn->prepare(
            'UPDATE email_confirmations SET used = 1 WHERE user_id = :user_id'
        );
        $stmt->bindParam(':user_id', $user_id);
        return $stmt->execute();
    }

    public static function hashPassword($password)
    {
        return password_hash($password, self::getPasswordAlgo());
    }

    public function getRoles($user_id)
    {
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

    public function getPrimaryRole($user_id)
    {
        $user = $this->getById($user_id);
        return $user['user_role'] ?? self::ROLE_USER;
    }

    public function userHasRole($user_id, $role_name)
    {
        $roles = $this->getRoles($user_id);
        return in_array($role_name, $roles, true);
    }

    public function assignRole($user_id, $role_name)
    {
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

    public function removeRole($user_id, $role_name)
    {
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

    private function getRoleIdByName($role_name)
    {
        $stmt = $this->conn->prepare("SELECT id FROM roles WHERE name = :name LIMIT 1");
        $stmt->bindParam(':name', $role_name);
        $stmt->execute();
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        return $role['id'] ?? null;
    }

    public function generateTwoFactorSecret($length = 16)
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }
        return $secret;
    }

    private function base32Decode($secret)
    {
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

    private function getTotpCode($secret, $timeSlice = null)
    {
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

    public function verifyTwoFactorCode($secret, $code, $window = self::TOTP_WINDOW)
    {
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

    public function enableTwoFactor($user_id, $secret)
    {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table}
             SET two_factor_enabled = 1, two_factor_secret = :secret
             WHERE id = :id"
        );
        $stmt->bindParam(':secret', $secret);
        $stmt->bindParam(':id', $user_id);
        return $stmt->execute();
    }

    public function disableTwoFactor($user_id)
    {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table}
             SET two_factor_enabled = 0, two_factor_secret = NULL
             WHERE id = :id"
        );
        $stmt->bindParam(':id', $user_id);
        return $stmt->execute();
    }

    public function getAll()
    {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table}");
        $stmt->execute();
        return $stmt;
    }

    public function getById($id)
    {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getByEmail($email)
    {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE email = :email LIMIT 1");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getByUsername($username)
    {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE username = :username LIMIT 1");
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function emailExists($email)
    {
        $stmt = $this->conn->prepare("SELECT id FROM {$this->table} WHERE email = :email LIMIT 1");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    public function usernameExists($username)
    {
        $stmt = $this->conn->prepare("SELECT id FROM {$this->table} WHERE username = :username LIMIT 1");
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    public function isUsernameTakenByOther($username, $user_id)
    {
        $stmt = $this->conn->prepare("SELECT id FROM {$this->table} WHERE username = :username AND id <> :id LIMIT 1");
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':id', $user_id);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    public function verifyPassword($password, $hash)
    {
        return password_verify($password, $hash);
    }

    public function isEmailTakenByOther($email, $user_id)
    {
        $stmt = $this->conn->prepare("SELECT id FROM {$this->table} WHERE email = :email AND id <> :id LIMIT 1");
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':id', $user_id);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    public function updateUsername($user_id, $username)
    {
        $stmt = $this->conn->prepare("UPDATE {$this->table} SET username = :username WHERE id = :id");
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':id', $user_id);
        return $stmt->execute();
    }

    public function updateEmail($user_id, $email)
    {
        $stmt = $this->conn->prepare("UPDATE {$this->table} SET email = :email WHERE id = :id");
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':id', $user_id);
        return $stmt->execute();
    }

    public function updatePassword($user_id, $password)
    {
        $hashed = self::hashPassword($password);
        $stmt = $this->conn->prepare("UPDATE {$this->table} SET password_hash = :password_hash WHERE id = :id");
        $stmt->bindParam(':password_hash', $hashed);
        $stmt->bindParam(':id', $user_id);
        return $stmt->execute();
    }

    private function ensurePasswordResetsTableExists()
    {
        $stmt = $this->conn->query("SHOW TABLES LIKE 'password_resets'");
        if ($stmt && $stmt->fetch()) {
            return true;
        }

        $sql = "CREATE TABLE IF NOT EXISTS password_resets (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) NOT NULL,
            token VARCHAR(255) NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            UNIQUE KEY token (token),
            CONSTRAINT password_resets_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";

        $this->conn->exec($sql);
        return true;
    }

    public function createPasswordResetToken($user_id)
    {
        $this->ensurePasswordResetsTableExists();

        $token = bin2hex(random_bytes(32));

        $stmt = $this->conn->prepare(
            'INSERT INTO password_resets (user_id, token, expires_at, used, created_at)
             VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL 1 HOUR), 0, NOW())'
        );
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':token', $token);

        return $stmt->execute() ? $token : false;
    }

    public function createPasswordResetTokenByEmail($email)
    {
        $user = $this->getByEmail($email);
        if (!$user) {
            return false;
        }
        return $this->createPasswordResetToken($user['id']);
    }

    public function getPasswordResetRequest($token)
    {
        $this->ensurePasswordResetsTableExists();

        $stmt = $this->conn->prepare(
            'SELECT pr.*, u.id AS user_id, u.username, u.email
             FROM password_resets pr
             JOIN users u ON u.id = pr.user_id
             WHERE pr.token = :token
               AND pr.used = 0
               AND pr.expires_at >= NOW()
             LIMIT 1'
        );
        $stmt->bindParam(':token', $token);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function consumePasswordResetToken($token)
    {
        $stmt = $this->conn->prepare(
            'UPDATE password_resets SET used = 1 WHERE token = :token'
        );
        $stmt->bindParam(':token', $token);
        return $stmt->execute();
    }

    public function resetPasswordByToken($token, $password)
    {
        $request = $this->getPasswordResetRequest($token);
        if (!$request) {
            return false;
        }

        $this->conn->beginTransaction();
        try {
            $updated = $this->updatePassword($request['user_id'], $password);
            if (!$updated) {
                $this->conn->rollBack();
                return false;
            }

            $this->consumePasswordResetToken($token);
            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollBack();
            return false;
        }
    }

    public function invalidatePasswordResetTokens($user_id)
    {
        $stmt = $this->conn->prepare(
            'UPDATE password_resets SET used = 1 WHERE user_id = :user_id'
        );
        $stmt->bindParam(':user_id', $user_id);
        return $stmt->execute();
    }

    public function getProfile($user_id)
    {
        $stmt = $this->conn->prepare('SELECT * FROM user_profiles WHERE user_id = :user_id LIMIT 1');
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateProfile($user_id, $avatar_url = null, $bio = null, $first_name = null, $last_name = null)
    {
        $existing = $this->getProfile($user_id);

        if ($existing) {
            $sql = 'UPDATE user_profiles SET avatar_url = :avatar_url, bio = :bio, first_name = :first_name, last_name = :last_name WHERE user_id = :user_id';
        } else {
            $sql = 'INSERT INTO user_profiles (user_id, avatar_url, bio, first_name, last_name) VALUES (:user_id, :avatar_url, :bio, :first_name, :last_name)';
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':avatar_url', $avatar_url);
        $stmt->bindParam(':bio', $bio);
        $stmt->bindParam(':first_name', $first_name);
        $stmt->bindParam(':last_name', $last_name);
        $executed = $stmt->execute();

        if ($executed) {
            $updateUserAvatar = $this->conn->prepare("UPDATE {$this->table} SET avatar_url = :avatar_url WHERE id = :id");
            $updateUserAvatar->bindParam(':avatar_url', $avatar_url);
            $updateUserAvatar->bindParam(':id', $user_id);
            $updateUserAvatar->execute();
        }

        return $executed;
    }

    public function update()
    {
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

    public function delete($id)
    {
        $stmt = $this->conn->prepare("DELETE FROM {$this->table} WHERE id=:id");
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function incrementFailedAttempts($id)
    {
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

    public function resetFailedAttempts($id)
    {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table}
             SET failed_attempts = 0, locked_until = NULL, last_failed_at = NULL
             WHERE id = :id"
        );
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function isLocked($user)
    {
        if (empty($user['locked_until'])) {
            return false;
        }
        return strtotime($user['locked_until']) > time();
    }

    public function getLockRemainingMinutes($user)
    {
        if (empty($user['locked_until'])) {
            return 0;
        }
        $remaining = strtotime($user['locked_until']) - time();
        return $remaining > 0 ? ceil($remaining / 60) : 0;
    }

    public function countActiveSessions($user_id)
    {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM sessions
             WHERE user_id = :user_id AND expires_at > NOW()"
        );
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function createSession($user_id, $remember = false)
    {
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

    public function validateSession($token)
    {
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

    public function deleteSession($token)
    {
        $stmt = $this->conn->prepare("DELETE FROM sessions WHERE token = :token");
        $stmt->bindParam(':token', $token);
        return $stmt->execute();
    }

    /**
     * Заблокировать пользователя
     * @param int $user_id ID пользователя
     * @param int $admin_id ID администратора
     * @param string $duration Продолжительность ('1h', '1d', '7d', '30d', 'permanent')
     * @param string $reason Причина блокировки
     * @return bool
     */
    public function banUser($user_id, $admin_id, $duration, $reason = '')
    {
        // Получаем информацию о модераторе/админе
        $moderator = $this->getById($admin_id);
        if (!$moderator) {
            return false;
        }

        // Получаем информацию о пользователе, которого пытаемся забанить
        $target_user = $this->getById($user_id);
        if (!$target_user) {
            return false;
        }

        // Проверка прав: только админ может банить других админов и модераторов
        if ($moderator['user_role'] !== 'admin') {
            // Модератор не может банить админов
            if ($target_user['user_role'] === 'admin') {
                return false;
            }
            // Модератор не может банить других модераторов
            if ($target_user['user_role'] === 'moderator') {
                return false;
            }
        }

        $ban_until = null;

        switch ($duration) {
            case '1h':
                $ban_until = date('Y-m-d H:i:s', strtotime('+1 hour'));
                break;
            case '1d':
                $ban_until = date('Y-m-d H:i:s', strtotime('+1 day'));
                break;
            case '7d':
                $ban_until = date('Y-m-d H:i:s', strtotime('+7 days'));
                break;
            case '30d':
                $ban_until = date('Y-m-d H:i:s', strtotime('+30 days'));
                break;
            case 'permanent':
                $ban_until = null; // Перманентный бан
                break;
            default:
                return false;
        }

        $status = $duration === 'permanent' ? 'blocked' : 'active';

        $stmt = $this->conn->prepare(
            "UPDATE {$this->table}
             SET status = :status, ban_until = :ban_until, ban_reason = :ban_reason
             WHERE id = :user_id"
        );
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':ban_until', $ban_until);
        $stmt->bindParam(':ban_reason', $reason);
        $stmt->bindParam(':user_id', $user_id);

        if ($stmt->execute()) {
            // Логируем действие
            $this->logAdminAction(
                $admin_id,
                'user_ban',
                'user',
                $user_id,
                "Блокировка на $duration. Причина: $reason"
            );

            // Записываем в moderation_log
            $this->logModerationAction($admin_id, 'ban', 'user', $user_id, $reason, $duration);

            // Создаем уведомление пользователю
            $this->createBanNotification($user_id, $duration, $reason);

            return true;
        }
        return false;
    }

    /**
     * Разблокировать пользователя
     * @param int $user_id ID пользователя
     * @param int $admin_id ID администратора
     * @return bool
     */
    public function unbanUser($user_id, $admin_id)
    {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table}
             SET status = 'active', ban_until = NULL, ban_reason = NULL
             WHERE id = :user_id"
        );
        $stmt->bindParam(':user_id', $user_id);

        if ($stmt->execute()) {
            // Логируем действие
            $this->logAdminAction($admin_id, 'user_unban', 'user', $user_id, 'Разблокировка пользователя');

            // Записываем в moderation_log
            $this->logModerationAction($admin_id, 'unban', 'user', $user_id, 'Разблокировка пользователя');

            // Создаем уведомление пользователю
            require_once __DIR__ . '/NotificationManager.php';
            $notificationManager = new NotificationManager($this->conn);
            $notificationManager->createNotification($user_id, 'unban', 'Ваш аккаунт разблокирован', 'success');

            return true;
        }
        return false;
    }

    /**
     * Проверить, заблокирован ли пользователь
     * @param array $user Данные пользователя
     * @return bool
     */
    public function isBanned($user)
    {
        if ($user['status'] === 'blocked') {
            return true;
        }
        if (!empty($user['ban_until']) && strtotime($user['ban_until']) > time()) {
            return true;
        }
        return false;
    }

    /**
     * Получить информацию о блокировке пользователя
     * @param int $user_id ID пользователя
     * @return array|null
     */
    public function getBanInfo($user_id)
    {
        $user = $this->getById($user_id);
        if (!$user) {
            return null;
        }

        if ($user['status'] === 'blocked') {
            return [
                'banned' => true,
                'permanent' => true,
                'ban_until' => null,
                'reason' => $user['ban_reason'] ?? 'Перманентная блокировка'
            ];
        }

        if (!empty($user['ban_until']) && strtotime($user['ban_until']) > time()) {
            return [
                'banned' => true,
                'permanent' => false,
                'ban_until' => $user['ban_until'],
                'remaining_time' => strtotime($user['ban_until']) - time(),
                'reason' => $user['ban_reason'] ?? 'Временная блокировка'
            ];
        }

        return ['banned' => false];
    }

    /**
     * Логировать действие администратора
     */
    private function logAdminAction($admin_id, $action_type, $target_type, $target_id, $details = '')
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO admin_actions (admin_id, action_type, target_type, target_id, details, created_at)
             VALUES (:admin_id, :action_type, :target_type, :target_id, :details, NOW())"
        );
        $stmt->bindParam(':admin_id', $admin_id);
        $stmt->bindParam(':action_type', $action_type);
        $stmt->bindParam(':target_type', $target_type);
        $stmt->bindParam(':target_id', $target_id);
        $stmt->bindParam(':details', $details);
        return $stmt->execute();
    }

    /**
     * Записать действие модерации в лог
     */
    private function logModerationAction($moderator_id, $action, $target_type, $target_id, $reason, $duration = null)
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO moderation_log (moderator_id, action, target_type, target_id, reason, duration)
             VALUES (:moderator_id, :action, :target_type, :target_id, :reason, :duration)"
        );
        $stmt->bindParam(':moderator_id', $moderator_id);
        $stmt->bindParam(':action', $action);
        $stmt->bindParam(':target_type', $target_type);
        $stmt->bindParam(':target_id', $target_id);
        $stmt->bindParam(':reason', $reason);
        $stmt->bindParam(':duration', $duration);
        return $stmt->execute();
    }

    /**
     * Создать уведомление о бане
     */
    private function createBanNotification($user_id, $duration, $reason)
    {
        require_once __DIR__ . '/NotificationManager.php';
        $notificationManager = new NotificationManager($this->conn);

        $message = "Ваш аккаунт заблокирован";
        if ($duration !== 'permanent') {
            $message .= " на $duration";
        } else {
            $message .= " навсегда";
        }
        if (!empty($reason)) {
            $message .= ". Причина: $reason";
        }

        return $notificationManager->createNotification($user_id, 'ban', $message, 'warning');
    }

    /**
     * Создать уведомление об удалении поста
     */
    public function notifyPostDeleted($user_id, $reason = '')
    {
        require_once __DIR__ . '/NotificationManager.php';
        $notificationManager = new NotificationManager($this->conn);

        $message = "Ваш пост был удален";
        if (!empty($reason)) {
            $message .= ". Причина: $reason";
        }

        return $notificationManager->createNotification($user_id, 'post_deleted', $message);
    }

    /**
     * Создать уведомление о скрытии поста
     */
    public function notifyPostHidden($user_id, $reason = '')
    {
        require_once __DIR__ . '/NotificationManager.php';
        $notificationManager = new NotificationManager($this->conn);

        $message = "Ваш пост был скрыт";
        if (!empty($reason)) {
            $message .= ". Причина: $reason";
        }

        return $notificationManager->createNotification($user_id, 'post_hidden', $message);
    }

    /**
     * Создать уведомление об удалении комментария
     */
    public function notifyCommentDeleted($user_id, $reason = '')
    {
        require_once __DIR__ . '/NotificationManager.php';
        $notificationManager = new NotificationManager($this->conn);

        $message = "Ваш комментарий был удален";
        if (!empty($reason)) {
            $message .= ". Причина: $reason";
        }

        return $notificationManager->createNotification($user_id, 'comment_deleted', $message);
    }
}
