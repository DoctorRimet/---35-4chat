<?php

class Attachment
{
    private $conn;
    private $table = 'attachments';

    public $id;
    public $post_id;
    public $comment_id;
    public $topic_id;
    public $filename;
    public $original_filename;
    public $file_type;
    public $file_size;
    public $file_path;
    public $uploaded_by;
    public $uploaded_at;

    // Upload limits
    const TOPIC_POST_MAX_FILES = 5;
    const TOPIC_POST_MAX_SIZE = 5242880; // 5MB per file
    const COMMENT_MAX_FILES = 2;
    const COMMENT_MAX_SIZE = 1048576; // 1MB per file
    const UPLOAD_DIR = 'uploads/';
    const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'application/zip'];

    public function __construct($db)
    {
        $this->conn = $db;
        $this->id = null;
        $this->post_id = null;
        $this->comment_id = null;
        $this->topic_id = null;
        $this->filename = null;
        $this->original_filename = null;
        $this->file_type = null;
        $this->file_size = null;
        $this->file_path = null;
        $this->uploaded_by = null;
        $this->uploaded_at = null;
    }

    public function upload($file, $type, $relatedId)
    {
        // $type: 'post', 'comment', 'topic'
        // $relatedId: id of post, comment, or topic

        // Reset all properties
        $this->id = null;
        $this->post_id = null;
        $this->comment_id = null;
        $this->topic_id = null;

        if (!isset($file['tmp_name']) || !isset($file['name'])) {
            return ['success' => false, 'error' => 'Файл не выбран'];
        }

        // Determine limits based on type
        $maxFiles = ($type === 'comment') ? self::COMMENT_MAX_FILES : self::TOPIC_POST_MAX_FILES;
        $maxSize = ($type === 'comment') ? self::COMMENT_MAX_SIZE : self::TOPIC_POST_MAX_SIZE;

        // Check file size
        if ($file['size'] > $maxSize) {
            $maxSizeMB = ($type === 'comment') ? 1 : 5;
            return ['success' => false, 'error' => "Размер файла не должен превышать {$maxSizeMB}MB"];
        }

        // Check file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, self::ALLOWED_TYPES)) {
            return ['success' => false, 'error' => 'Недопустимый тип файла. Допускаются: jpg, png, gif, pdf, zip'];
        }

        // Check count of existing files
        $columnName = ($type === 'post') ? 'post_id' : (($type === 'comment') ? 'comment_id' : 'topic_id');
        $stmt = $this->conn->prepare("SELECT COUNT(*) as cnt FROM {$this->table} WHERE {$columnName} = :id");
        $stmt->bindParam(':id', $relatedId);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['cnt'] >= $maxFiles) {
            return ['success' => false, 'error' => "Максимум {$maxFiles} файлов на {$type}"];
        }

        // Create uploads directory if needed
        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }

        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid('attachment_') . '.' . $extension;
        $filePath = self::UPLOAD_DIR . $filename;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            return ['success' => false, 'error' => 'Ошибка при загрузке файла'];
        }

        // Save to database
        $this->filename = $filename;
        $this->original_filename = $file['name'];
        $this->file_type = $mimeType;
        $this->file_size = $file['size'];
        $this->file_path = $filePath;
        $this->uploaded_by = $_SESSION['user_id'] ?? null;

        if ($type === 'post') {
            $this->post_id = $relatedId;
        } elseif ($type === 'comment') {
            $this->comment_id = $relatedId;
        } else {
            $this->topic_id = $relatedId;
        }

        // Debug log
        if (isset($_GET['debug'])) {
            error_log("DEBUG Attachment->upload(): type=$type, relatedId=$relatedId");
            error_log("DEBUG Set values: post_id={$this->post_id}, comment_id={$this->comment_id}, topic_id={$this->topic_id}");
        }

        $sql = "INSERT INTO {$this->table} 
                (post_id, comment_id, topic_id, filename, original_filename, file_type, file_size, file_path, uploaded_by)
                VALUES (:post_id, :comment_id, :topic_id, :filename, :original_filename, :file_type, :file_size, :file_path, :uploaded_by)";

        $stmt = $this->conn->prepare($sql);

        // Debug log
        if (isset($_GET['debug'])) {
            error_log("DEBUG Before bindValue: post_id={$this->post_id}, comment_id={$this->comment_id}, topic_id={$this->topic_id}");
        }

        // Use proper type for each parameter
        $postIdType = $this->post_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT;
        $commentIdType = $this->comment_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT;
        $topicIdType = $this->topic_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT;
        $uploadedByType = $this->uploaded_by === null ? PDO::PARAM_NULL : PDO::PARAM_INT;

        $stmt->bindValue(':post_id', $this->post_id, $postIdType);
        $stmt->bindValue(':comment_id', $this->comment_id, $commentIdType);
        $stmt->bindValue(':topic_id', $this->topic_id, $topicIdType);
        $stmt->bindValue(':filename', $this->filename, PDO::PARAM_STR);
        $stmt->bindValue(':original_filename', $this->original_filename, PDO::PARAM_STR);
        $stmt->bindValue(':file_type', $this->file_type, PDO::PARAM_STR);
        $stmt->bindValue(':file_size', $this->file_size, PDO::PARAM_INT);
        $stmt->bindValue(':file_path', $this->file_path, PDO::PARAM_STR);
        $stmt->bindValue(':uploaded_by', $this->uploaded_by, $uploadedByType);

        if ($stmt->execute()) {
            $this->id = $this->conn->lastInsertId();
            if (isset($_GET['debug'])) {
                error_log("DEBUG Insert successful, ID: {$this->id}");
            }
            return ['success' => true, 'id' => $this->id, 'filename' => $filename];
        }

        $errorInfo = $stmt->errorInfo();
        if (isset($_GET['debug'])) {
            error_log("DEBUG Insert failed: " . json_encode($errorInfo));
        }
        return ['success' => false, 'error' => 'Ошибка БД: ' . ($errorInfo[2] ?? 'Unknown error')];
    }

    public function getByPostId($postId)
    {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE post_id = :post_id");
        $stmt->bindParam(':post_id', $postId);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getByCommentId($commentId)
    {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE comment_id = :comment_id");
        $stmt->bindParam(':comment_id', $commentId);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getByTopicId($topicId)
    {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE topic_id = :topic_id");
        $stmt->bindParam(':topic_id', $topicId);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function delete($id)
    {
        // Get file path first
        $stmt = $this->conn->prepare("SELECT file_path FROM {$this->table} WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && file_exists($result['file_path'])) {
            unlink($result['file_path']);
        }

        // Delete from database
        $stmt = $this->conn->prepare("DELETE FROM {$this->table} WHERE id = :id");
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function isImage($mimeType)
    {
        return strpos($mimeType, 'image/') === 0;
    }
}
