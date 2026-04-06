<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Topic.php';
require_once __DIR__ . '/classes/Post.php';
require_once __DIR__ . '/classes/Comment.php';
require_once __DIR__ . '/classes/User.php';
require_once __DIR__ . '/classes/Category.php';
require_once __DIR__ . '/classes/Attachment.php';
require_once __DIR__ . '/functions/markdown.php';

$db = new Database();
$conn = $db->getConnection();

$topicModel = new Topic($conn);
$postModel = new Post($conn);
$commentModel = new Comment($conn);
$userModel = new User($conn);
$categoryModel = new Category($conn);
$attachmentModel = new Attachment($conn);

$currentUserId = $_SESSION['user_id'] ?? null;
$loggedIn = !empty($currentUserId);
$currentUserRole = $_SESSION['user_role'] ?? 'user';

$topicId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($topicId <= 0) {
    header('Location: index.php');
    exit;
}

$topic = $topicModel->getById($topicId);
if (!$topic) {
    header('Location: index.php');
    exit;
}

$topicAuthor = $userModel->getById($topic['author_id']);
$topicCategory = null;
if (!empty($topic['category_id'])) {
    $topicCategory = $categoryModel->getById($topic['category_id']);
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['comment_text'], $_POST['post_id']) || isset($_POST['content'], $_POST['post_id']))) {
    if (!$loggedIn) {
        $errors[] = 'Только авторизованные пользователи могут оставлять комментарии.';
    } else {
        $postId = (int) $_POST['post_id'];
        $parentCommentId = isset($_POST['parent_comment_id']) ? (int) $_POST['parent_comment_id'] : null;
        $content = trim($_POST['comment_text'] ?? $_POST['content']);

        if ($postId <= 0 || mb_strlen($content) < 3) {
            $errors[] = 'Комментарий должен содержать минимум 3 символа.';
        } else {
            $commentModel->post_id = $postId;
            $commentModel->author_id = $currentUserId;
            $commentModel->parent_comment_id = $parentCommentId > 0 ? $parentCommentId : null;
            $commentModel->content = $content;

            if ($commentModel->create()) {
                $fileUploadError = false;
                
                // Handle multiple file uploads for comment (from reply form in renderComments)
                if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
                    for ($i = 0; $i < count($_FILES['attachments']['name']); $i++) {
                        $file = [
                            'name' => $_FILES['attachments']['name'][$i],
                            'type' => $_FILES['attachments']['type'][$i],
                            'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                            'error' => $_FILES['attachments']['error'][$i],
                            'size' => $_FILES['attachments']['size'][$i]
                        ];
                        
                        $uploadResult = $attachmentModel->upload($file, 'comment', $commentModel->id);
                        if (!$uploadResult['success']) {
                            $errors[] = 'Ошибка при загрузке файла: ' . $uploadResult['error'];
                            $fileUploadError = true;
                        }
                    }
                }
                // Handle single file upload for comment (from main comment form)
                elseif (isset($_FILES['comment_file']) && $_FILES['comment_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                    if (isset($_GET['debug'])) {
                        error_log("DEBUG: Uploading file for comment ID: " . $commentModel->id);
                        error_log("DEBUG: File array: " . json_encode($_FILES['comment_file']));
                    }
                    $uploadResult = $attachmentModel->upload($_FILES['comment_file'], 'comment', $commentModel->id);
                    if (!$uploadResult['success']) {
                        $errors[] = 'Ошибка при загрузке файла: ' . $uploadResult['error'];
                        $fileUploadError = true;
                    } else if (isset($_GET['debug'])) {
                        $errors[] = 'Файл загружен успешно: ' . $uploadResult['filename'];
                    }
                }
                
                // Redirect only if no file upload errors
                if (!$fileUploadError) {
                    header('Location: topic.php?id=' . $topicId . '#post-' . $postId);
                    exit;
                }
            }
            $errors[] = 'Не удалось сохранить комментарий. Попробуйте ещё раз.';
        }
    }
}

// Handle editing topic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_topic_id'], $_POST['edit_topic_title'])) {
    $editTopicId = (int) $_POST['edit_topic_id'];
    if ($editTopicId == $topicId && canEditTopic($topic, $currentUserId, $currentUserRole)) {
        $editTitle = trim($_POST['edit_topic_title']);
        $editDescription = trim($_POST['edit_topic_description'] ?? '');
        $editCategoryId = $_POST['edit_topic_category'] ?? null;
        
        if (empty($editTitle)) {
            $errors[] = 'Заголовок темы не может быть пустым.';
        } elseif (mb_strlen($editDescription) < 10) {
            $errors[] = 'Описание должно содержать минимум 10 символов.';
        } else {
            $topicModel->id = $editTopicId;
            $topicModel->title = $editTitle;
            $topicModel->description = $editDescription;
            $topicModel->category_id = $editCategoryId ?: null;
            
            if ($topicModel->update()) {
                $topic = $topicModel->getById($topicId);
                $topicCategory = null;
                if (!empty($topic['category_id'])) {
                    $topicCategory = $categoryModel->getById($topic['category_id']);
                }
            } else {
                $errors[] = 'Не удалось обновить тему.';
            }
        }
    } else {
        $errors[] = 'У вас нет прав на редактирование этой темы.';
    }
}

// Handle deleting topic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_topic_id'])) {
    $deleteTopicId = (int) $_POST['delete_topic_id'];
    if ($deleteTopicId == $topicId && canDeleteTopic($topic, $currentUserId, $currentUserRole)) {
        // Delete all attachments for this topic and its posts/comments
        $attachmentsForTopic = $attachmentModel->getByTopicId($topicId);
        foreach ($attachmentsForTopic as $attachment) {
            $attachmentModel->delete($attachment['id']);
        }
        
        if ($topicModel->delete($topicId)) {
            header('Location: index.php');
            exit;
        } else {
            $errors[] = 'Не удалось удалить тему.';
        }
    } else {
        $errors[] = 'У вас нет прав на удаление этой темы.';
    }
}

// Handle editing posts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_post_id'], $_POST['edit_content'])) {
    $editPostId = (int) $_POST['edit_post_id'];
    $editContent = trim($_POST['edit_content']);
    $postToEdit = $postModel->getById($editPostId);
    if ($postToEdit && canEditPost($postToEdit, $currentUserId, $currentUserRole)) {
        if (mb_strlen($editContent) < 10) {
            $errors[] = 'Текст поста должен содержать минимум 10 символов.';
        } else {
            $postModel->id = $editPostId;
            $postModel->content = $editContent;
            if ($postModel->update()) {
                header('Location: topic.php?id=' . $topicId . '#post-' . $editPostId);
                exit;
            } else {
                $errors[] = 'Не удалось обновить пост.';
            }
        }
    } else {
        $errors[] = 'У вас нет прав на редактирование этого поста.';
    }
}

// Handle editing comments
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_comment_id'], $_POST['edit_comment_content'])) {
    $editCommentId = (int) $_POST['edit_comment_id'];
    $editContent = trim($_POST['edit_comment_content']);
    $commentToEdit = $commentModel->getById($editCommentId);
    if ($commentToEdit && canEditComment($commentToEdit, $currentUserId, $currentUserRole)) {
        if (mb_strlen($editContent) < 3) {
            $errors[] = 'Комментарий должен содержать минимум 3 символа.';
        } else {
            $commentModel->id = $editCommentId;
            $commentModel->content = $editContent;
            if ($commentModel->update()) {
                header('Location: topic.php?id=' . $topicId . '#post-' . $commentToEdit['post_id']);
                exit;
            } else {
                $errors[] = 'Не удалось обновить комментарий.';
            }
        }
    } else {
        $errors[] = 'У вас нет прав на редактирование этого комментария.';
    }
}

// Handle deleting posts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_post_id'])) {
    $deletePostId = (int) $_POST['delete_post_id'];
    $postToDelete = $postModel->getById($deletePostId);
    if ($postToDelete && canDeletePost($postToDelete, $currentUserId, $currentUserRole)) {
        // Delete attachments for post and its comments
        $attachmentsForPost = $attachmentModel->getByPostId($deletePostId);
        foreach ($attachmentsForPost as $attachment) {
            $attachmentModel->delete($attachment['id']);
        }
        
        if ($postModel->delete($deletePostId)) {
            header('Location: topic.php?id=' . $topicId);
            exit;
        } else {
            $errors[] = 'Не удалось удалить пост.';
        }
    } else {
        $errors[] = 'У вас нет прав на удаление этого поста.';
    }
}

// Handle deleting comments
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_comment_id'])) {
    $deleteCommentId = (int) $_POST['delete_comment_id'];
    $commentToDelete = $commentModel->getById($deleteCommentId);
    if ($commentToDelete && canDeleteComment($commentToDelete, $currentUserId, $currentUserRole)) {
        // Delete attachments for this comment
        $attachments = $attachmentModel->getByCommentId($deleteCommentId);
        foreach ($attachments as $attachment) {
            $attachmentModel->delete($attachment['id']);
        }
        
        if ($commentModel->delete($deleteCommentId)) {
            header('Location: topic.php?id=' . $topicId . '#post-' . $commentToDelete['post_id']);
            exit;
        } else {
            $errors[] = 'Не удалось удалить комментарий.';
        }
    } else {
        $errors[] = 'У вас нет прав на удаление этого комментария.';
    }
}

$postsStmt = $postModel->getByTopicId($topicId);
$posts = $postsStmt->fetchAll(PDO::FETCH_ASSOC);

function buildCommentTree(array $comments) {
    $tree = [];
    foreach ($comments as $comment) {
        $parentId = !empty($comment['parent_comment_id']) ? (int)$comment['parent_comment_id'] : 0;
        $tree[$parentId][] = $comment;
    }
    return $tree;
}

function renderComments(array $tree, int $parentId = 0, int $level = 0) {
    // Initialize globals if not already set
    global $userModel, $attachmentModel, $loggedIn, $currentUserId, $currentUserRole;
    
    // Set GLOBALS for nested access
    $GLOBALS['userModel'] = $userModel;
    $GLOBALS['attachmentModel'] = $attachmentModel;
    $GLOBALS['loggedIn'] = $loggedIn;
    $GLOBALS['currentUserId'] = $currentUserId;
    $GLOBALS['currentUserRole'] = $currentUserRole;
    
    if (empty($tree[$parentId])) {
        return;
    }
    foreach ($tree[$parentId] as $comment) {
        $commentAuthor = $GLOBALS['userModel']->getById($comment['author_id']);
        $leftPadding = $level * 30;
        $borderColor = $level == 0 ? '#0d6efd' : '#6c757d';
        
        echo '<div style="margin-left:' . $leftPadding . 'px; border-left: 3px solid ' . $borderColor . '; padding-left: 12px; margin-top: 12px;">';
        
        // Comment Header with Author Info
        echo '<div class="d-flex align-items-start gap-2 mb-2">';
        echo '<div style="flex-shrink:0;">';
        echo getAvatarHtml($commentAuthor['username'] ?? 'Unknown', $commentAuthor['avatar_url'] ?? null);
        echo '</div>';
        
        echo '<div class="flex-grow-1" style="width:100%;">';
        echo '<div class="d-flex align-items-center gap-2 mb-1">';
        echo '<strong>' . htmlspecialchars($commentAuthor['username'] ?? 'Unknown') . '</strong>';
        echo getAuthorBadge($commentAuthor['user_role'] ?? 'user');
        
        // Level indicator for nested comments
        if ($level > 0) {
            echo '<span class="badge bg-light text-dark" style="font-size:0.75rem;">Ур. ' . ($level) . '</span>';
        }
        
        echo '</div>';
        
        echo '<div style="font-size: 0.85rem; color: #6c757d;">';
        echo '📅 ' . formatTime($comment['created_at']);
        if (!empty($comment['updated_at']) && $comment['updated_at'] != $comment['created_at']) {
            echo ' <span style="margin-left: 8px;">✏️ ред. ' . formatTime($comment['updated_at']) . '</span>';
        }
        echo '</div>';
        
        echo '</div>';
        
        // Edit/Delete buttons
        if ($GLOBALS['loggedIn'] && (canEditComment($comment, $GLOBALS['currentUserId'], $GLOBALS['currentUserRole']) || canDeleteComment($comment, $GLOBALS['currentUserId'], $GLOBALS['currentUserRole']))) {
            echo '<div class="btn-group btn-group-sm" role="group" style="margin-top:8px;">';
            if (canEditComment($comment, $GLOBALS['currentUserId'], $GLOBALS['currentUserRole'])) {
                echo '<button type="button" class="btn btn-outline-warning btn-sm" onclick="toggleEditForm(\'comment\', ' . $comment['id'] . ')">✏️ Редакт.</button>';
            }
            if (canDeleteComment($comment, $GLOBALS['currentUserId'], $GLOBALS['currentUserRole'])) {
                echo '<form method="post" style="display:inline;"><input type="hidden" name="delete_comment_id" value="' . $comment['id'] . '"><button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm(\'Удалить этот комментарий?\')">🗑️ Удалить</button></form>';
            }
            echo '</div>';
        }
        echo '</div>';
        
        // Edit form for comment
        if (canEditComment($comment, $GLOBALS['currentUserId'], $GLOBALS['currentUserRole'])) {
            echo '<div id="edit-form-comment-' . $comment['id'] . '" class="edit-form d-none mt-3">';
            echo '<form method="post">';
            echo '<div class="mb-3"><textarea name="edit_comment_content" class="form-control" rows="3" required>' . htmlspecialchars($comment['content']) . '</textarea></div>';
            echo '<input type="hidden" name="edit_comment_id" value="' . $comment['id'] . '">';
            echo '<button type="submit" class="btn btn-primary btn-sm">Сохранить</button>';
            echo '<button type="button" class="btn btn-secondary btn-sm" onclick="toggleEditForm(\'comment\', ' . $comment['id'] . ')">Отмена</button>';
            echo '</form>';
            echo '</div>';
        }
        
        // Comment body
        echo '<div class="comment-body" style="padding: 8px 0; margin-bottom: 8px;">';
        echo '<p style="white-space: pre-wrap; word-break: break-word;">' . htmlspecialchars($comment['content']) . '</p>';
        
        // Display attachments
        $attachments = $GLOBALS['attachmentModel']->getByCommentId($comment['id']);
        if (!empty($attachments)) {
            echo '<div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #eee;">';
            foreach ($attachments as $attachment) {
                displayAttachment($attachment);
            }
            echo '</div>';
        }
        echo '</div>';
        
        // Reply button
        if ($GLOBALS['loggedIn']) {
            echo '<button class="btn btn-sm btn-link p-0" data-bs-toggle="collapse" data-bs-target="#replyForm' . $comment['id'] . '" onclick="setParentComment(' . $comment['id'] . ')">💬 Ответить</button>';
            echo '<div class="collapse mt-2" id="replyForm' . $comment['id'] . '">';
            echo '<form method="POST" enctype="multipart/form-data" class="comment-form" onsubmit="return validateCommentForm(this)">';
            echo '<input type="hidden" name="parent_comment_id" value="' . $comment['id'] . '">';
            echo '<input type="hidden" name="post_id" value="' . $comment['post_id'] . '">';
            echo '<textarea name="content" placeholder="Ваш ответ..." required maxlength="5000" style="width: 100%; min-height: 80px; border: 1px solid #dee2e6; border-radius: 4px; padding: 8px;"></textarea>';
            echo '<div style="margin-top: 8px;">';
            echo '<input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.zip,.rar,.txt" style="margin-bottom: 8px;">';
            echo '</div>';
            echo '<button type="submit" class="btn btn-primary btn-sm">Отправить</button>';
            echo '</form>';
            echo '</div>';
        }
        
        // Recursively render child comments
        echo '</div>';
        renderComments($tree, $comment['id'], $level + 1);
    }
}

// Helper functions for formatting
function displayAttachment($attachment) {
    global $attachmentModel;
    
    if ($attachmentModel->isImage($attachment['file_type'])) {
        // Display image inline
        echo '<div style="margin:5px 0;">';
        echo '<a href="' . htmlspecialchars($attachment['file_path']) . '" target="_blank" style="text-decoration:none;">';
        echo '<img src="' . htmlspecialchars($attachment['file_path']) . '" style="max-width:100%;max-height:300px;border-radius:4px;cursor:pointer;" title="' . htmlspecialchars($attachment['original_filename']) . '">';
        echo '</a>';
        echo '<div style="font-size:0.85rem;color:#666;margin-top:3px;">' . htmlspecialchars($attachment['original_filename']) . '</div>';
        echo '</div>';
    } else {
        // Display file link
        echo '<div style="margin:5px 0;">';
        echo '<a href="' . htmlspecialchars($attachment['file_path']) . '" target="_blank" download>';
        echo '📎 ' . htmlspecialchars($attachment['original_filename']) . '</a>';
        echo ' (' . round($attachment['file_size']/1024) . ' KB)';
        echo '</div>';
    }
}

function formatTime($timestamp) {
    $date = new DateTime($timestamp);
    $now = new DateTime();
    $diff = $now->diff($date);
    
    if ($diff->days == 0) {
        if ($diff->h == 0) {
            return $diff->i . ' мин назад';
        }
        return $diff->h . ' ч назад';
    } elseif ($diff->days < 7) {
        return $diff->days . ' дн назад';
    }
    return $date->format('d.m.Y H:i');
}

function getAuthorBadge($userRole) {
    $badges = [
        'admin' => '<span class="badge bg-danger">👑 Администратор</span>',
        'moderator' => '<span class="badge bg-warning text-dark">⚡ Модератор</span>',
        'user' => '<span class="badge bg-secondary">👤 Пользователь</span>'
    ];
    return $badges[$userRole] ?? $badges['user'];
}

function getAvatarHtml($username, $avatarUrl = null) {
    if ($avatarUrl && file_exists($avatarUrl)) {
        return '<img src="' . htmlspecialchars($avatarUrl) . '" class="rounded-circle" width="40" height="40" alt="' . htmlspecialchars($username) . '" title="' . htmlspecialchars($username) . '">';
    }
    // Default avatar with first letter
    $initials = mb_substr($username, 0, 1);
    $colors = ['#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8', '#F7DC6F'];
    $colorIndex = ord($initials) % count($colors);
    $bgColor = $colors[$colorIndex];
    return '<div style="display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:50%;background-color:' . $bgColor . ';color:white;font-weight:bold;font-size:18px;" title="' . htmlspecialchars($username) . '">' . htmlspecialchars($initials) . '</div>';
}

function canEditTopic($topic, $userId, $userRole) {
    if (in_array($userRole, ['moderator', 'admin'])) {
        return true;
    }
    if ($topic['author_id'] == $userId) {
        return true;
    }
    return false;
}

function canDeleteTopic($topic, $userId, $userRole) {
    if (in_array($userRole, ['moderator', 'admin'])) {
        return true;
    }
    if ($topic['author_id'] == $userId) {
        $createdTime = strtotime($topic['created_at']);
        $now = time();
        $hoursSinceCreation = ($now - $createdTime) / 3600;
        return $hoursSinceCreation <= 24;
    }
    return false;
}

function canEditPost($post, $userId, $userRole) {
    if (in_array($userRole, ['moderator', 'admin'])) {
        return true;
    }
    if ($post['author_id'] == $userId) {
        $createdTime = strtotime($post['created_at']);
        $now = time();
        $hoursSinceCreation = ($now - $createdTime) / 3600;
        return $hoursSinceCreation <= 24;
    }
    return false;
}

function canDeletePost($post, $userId, $userRole) {
    if (in_array($userRole, ['moderator', 'admin'])) {
        return true;
    }
    if ($post['author_id'] == $userId) {
        $createdTime = strtotime($post['created_at']);
        $now = time();
        $hoursSinceCreation = ($now - $createdTime) / 3600;
        return $hoursSinceCreation <= 24;
    }
    return false;
}

function canEditComment($comment, $userId, $userRole) {
    if (in_array($userRole, ['moderator', 'admin'])) {
        return true;
    }
    if ($comment['author_id'] == $userId) {
        $createdTime = strtotime($comment['created_at']);
        $now = time();
        $hoursSinceCreation = ($now - $createdTime) / 3600;
        return $hoursSinceCreation <= 24;
    }
    return false;
}

function canDeleteComment($comment, $userId, $userRole) {
    if (in_array($userRole, ['moderator', 'admin'])) {
        return true;
    }
    if ($comment['author_id'] == $userId) {
        $createdTime = strtotime($comment['created_at']);
        $now = time();
        $hoursSinceCreation = ($now - $createdTime) / 3600;
        return $hoursSinceCreation <= 24;
    }
    return false;
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($topic['title']) ?> — Тема</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        .post-card { border: 1px solid #dee2e6; border-radius: .75rem; padding: 1.25rem; }
        .comment-card { background: #f8f9fa; border-radius: .75rem; padding: 1rem; margin-top: .75rem; }
        .comment-form textarea { min-height: 120px; }
        .topic-badge { font-size: .85rem; }
    </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-light bg-white sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="index.php">
            <div class="navbar-brand-icon">💬</div>
            <span>Forum<span style="color:#6366f1">Chat</span></span>
        </a>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link" href="index.php">
                        <i class="bi bi-house me-1"></i>Главная
                    </a>
                </li>
                <?php if ($loggedIn): ?>
                <li class="nav-item">
                    <a class="nav-link" href="home/profile.php">
                        <i class="bi bi-person me-1"></i>Профиль
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="create.php">
                        <i class="bi bi-plus-circle me-1"></i>Создать тему
                    </a>
                </li>
                <?php endif; ?>
            </ul>
            <div class="d-flex align-items-center gap-2">
                <?php if ($loggedIn): ?>
                    <a href="auth/logout.php" class="btn btn-sm btn-outline-danger rounded-3">
                        <i class="bi bi-box-arrow-right me-1"></i>Выйти
                    </a>
                <?php else: ?>
                    <a href="auth/login.php" class="btn btn-sm btn-outline-primary rounded-3">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Войти
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<div class="container py-5">
    <div class="row">
        <div class="col-lg-9 mx-auto">
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex align-items-start justify-content-between mb-3">
                        <div class="flex-grow-1">
                            <h1 class="card-title mb-1"><?= htmlspecialchars($topic['title']) ?></h1>
                            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                                <?php if (!empty($topic['updated_at']) && $topic['updated_at'] != $topic['created_at']): ?>
                                    <span class="badge bg-info text-dark topic-badge">Отредактирована</span>
                                <?php endif; ?>
                                <?php if (!empty($topicCategory)): ?>
                                    <span class="badge bg-primary bg-opacity-15 text-primary topic-badge"><?= htmlspecialchars($topicCategory['name']) ?></span>
                                <?php endif; ?>
                                <span class="badge bg-secondary topic-badge">ID <?= $topic['id'] ?></span>
                                <span class="badge bg-success topic-badge">Автор: <?= htmlspecialchars($topicAuthor ? $topicAuthor['username'] : 'Неизвестно') ?></span>
                            </div>
                            <p class="text-muted mb-0">Создана <?= htmlspecialchars($topic['created_at']) ?></p>
                        </div>
                        <?php if ($loggedIn && (canEditTopic($topic, $currentUserId, $currentUserRole) || canDeleteTopic($topic, $currentUserId, $currentUserRole))): ?>
                            <div class="btn-group ms-2" role="group">
                                <?php if (canEditTopic($topic, $currentUserId, $currentUserRole)): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleEditTopicForm()">Редактировать</button>
                                <?php endif; ?>
                                <?php if (canDeleteTopic($topic, $currentUserId, $currentUserRole)): ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="delete_topic_id" value="<?= $topic['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Удалить эту тему?')">Удалить</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($topic['description'])): ?>
                        <p class="mt-3 mb-0"><?= nl2br(htmlspecialchars($topic['description'])) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (canEditTopic($topic, $currentUserId, $currentUserRole)): ?>
                <div id="edit-topic-form" class="card mb-4 d-none">
                    <div class="card-body">
                        <h5 class="mb-3">Редактировать тему</h5>
                        <form method="post">
                            <div class="mb-3">
                                <label class="form-label">Заголовок</label>
                                <input type="text" name="edit_topic_title" class="form-control" value="<?= htmlspecialchars($topic['title']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Категория</label>
                                <select name="edit_topic_category" class="form-select">
                                    <option value="">Без категории</option>
                                    <?php 
                                    $categoriesStmt = $categoryModel->getAll();
                                    $categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($categories as $cat): 
                                    ?>
                                        <option value="<?= $cat['id'] ?>" <?= ($topic['category_id'] == $cat['id']) ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Описание</label>
                                <textarea name="edit_topic_description" class="form-control" rows="6" required><?= htmlspecialchars($topic['description']) ?></textarea>
                            </div>
                            <input type="hidden" name="edit_topic_id" value="<?= $topic['id'] ?>">
                            <button type="submit" class="btn btn-primary">Сохранить</button>
                            <button type="button" class="btn btn-secondary" onclick="toggleEditTopicForm()">Отмена</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (empty($posts)): ?>
                <div class="alert alert-warning">В этой теме пока нет сообщений.</div>
            <?php endif; ?>

            <?php foreach ($posts as $post): ?>
                <?php 
                $comments = $commentModel->getByPostId($post['id'])->fetchAll(PDO::FETCH_ASSOC);
                $postAuthor = $userModel->getById($post['author_id']);
                $postNumber = array_search($post['id'], array_column($posts, 'id')) + 1;
                ?>
                <div id="post-<?= $post['id'] ?>" class="post-card mb-4">
                    <!-- Post Header with Author Info -->
                    <div class="d-flex align-items-start gap-3 mb-3" style="border-bottom: 1px solid #dee2e6; padding-bottom: 12px;">
                        <div>
                            <?= getAvatarHtml($postAuthor['username'] ?? 'Unknown', $postAuthor['avatar_url'] ?? null) ?>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <strong><?= htmlspecialchars($postAuthor['username'] ?? 'Unknown') ?></strong>
                                <?= getAuthorBadge($postAuthor['user_role'] ?? 'user') ?>
                            </div>
                            <div style="font-size: 0.85rem; color: #6c757d;">
                                <span title="<?= htmlspecialchars($post['created_at']) ?>">📅 <?= formatTime($post['created_at']) ?></span>
                                <?php if (!empty($post['updated_at']) && $post['updated_at'] != $post['created_at']): ?>
                                    <span style="margin-left: 10px;" title="<?= htmlspecialchars($post['updated_at']) ?>">✏️ ред. <?= formatTime($post['updated_at']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <span class="badge bg-info">#<?= $postNumber ?></span>
                        </div>
                    </div>

                    <!-- Post Header Controls -->
                    <div class="d-flex align-items-center justify-content-end mb-2"
                            <span class="badge bg-secondary topic-badge">Комментариев: <?= count($comments) ?></span>
                            <?php if ($loggedIn && (canEditPost($post, $currentUserId, $currentUserRole) || canDeletePost($post, $currentUserId, $currentUserRole))): ?>
                                <div class="btn-group" role="group">
                                    <?php if (canEditPost($post, $currentUserId, $currentUserRole)): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleEditForm('post', <?= $post['id'] ?>)">Редактировать</button>
                                    <?php endif; ?>
                                    <?php if (canDeletePost($post, $currentUserId, $currentUserRole)): ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="delete_post_id" value="<?= $post['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Удалить этот пост?')">Удалить</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="background-color:#f8f9fa;padding:15px;border-radius:5px;margin:15px 0;">
                        <?= parseMarkdown(nl2br(htmlspecialchars($post['content']))) ?>
                    </div>
                    
                    <?php 
                    // Display post attachments
                    $postAttachments = $GLOBALS['attachmentModel']->getByPostId($post['id']);
                    if (!empty($postAttachments)) {
                        echo '<div style="margin:10px 0;">';
                        foreach ($postAttachments as $att) {
                            if ($GLOBALS['attachmentModel']->isImage($att['file_type'])) {
                                echo '<div style="margin:5px 0;">';
                                echo '<a href="' . htmlspecialchars($att['file_path']) . '" target="_blank" style="text-decoration:none;">';
                                echo '<img src="' . htmlspecialchars($att['file_path']) . '" style="max-width:100%;max-height:300px;border-radius:4px;cursor:pointer;" title="' . htmlspecialchars($att['original_filename']) . '">';
                                echo '</a>';
                                echo '<div style="font-size:0.85rem;color:#666;margin-top:3px;">' . htmlspecialchars($att['original_filename']) . '</div>';
                                echo '</div>';
                            } else {
                                echo '<div style="margin:5px 0;">';
                                echo '<a href="' . htmlspecialchars($att['file_path']) . '" target="_blank" download>';
                                echo '📎 ' . htmlspecialchars($att['original_filename']) . '</a>';
                                echo ' (' . round($att['file_size']/1024) . ' KB)';
                                echo '</div>';
                            }
                        }
                        echo '</div>';
                    }
                    ?>
                    
                    <div style="margin-top:10px;font-size:0.9rem;">
                        <button type="button" class="btn btn-sm btn-light" onclick="insertQuote(0, <?= $post['id'] ?>, '<?= addslashes(htmlspecialchars(mb_substr($post['content'], 0, 50))) ?>...')">Цитировать</button>
                    </div>

                    <?php if (canEditPost($post, $currentUserId, $currentUserRole)): ?>
                        <div id="edit-form-post-<?= $post['id'] ?>" class="edit-form d-none mb-3">
                            <form method="post">
                                <div class="mb-3">
                                    <label class="form-label">Редактировать пост</label>
                                    <textarea name="edit_content" class="form-control" rows="5" required><?= htmlspecialchars($post['content']) ?></textarea>
                                </div>
                                <input type="hidden" name="edit_post_id" value="<?= $post['id'] ?>">
                                <button type="submit" class="btn btn-primary btn-sm">Сохранить</button>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleEditForm('post', <?= $post['id'] ?>)">Отмена</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($comments)): ?>
                        <div class="mb-4">
                            <h6 class="mb-3">Комментарии</h6>
                            <?php
                                $commentTree = buildCommentTree($comments);
                                renderComments($commentTree);
                            ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($loggedIn): ?>
                        <form method="post" class="comment-form" enctype="multipart/form-data" onsubmit="return validateCommentForm(this)">
                            <div class="mb-3">
                                <label for="comment_text_<?= $post['id'] ?>" class="form-label">Добавить комментарий</label>
                                <div class="btn-group btn-group-sm mb-2" role="group" style="display:flex;flex-wrap:wrap;">
                                    <button type="button" class="btn btn-outline-secondary" onclick="insertMarkdown('comment_text_<?= $post['id'] ?>', '**', '**')"><strong>Ж</strong></button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="insertMarkdown('comment_text_<?= $post['id'] ?>', '*', '*')"><em>К</em></button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="insertMarkdown('comment_text_<?= $post['id'] ?>', '`', '`')">Code</button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="insertMarkdown('comment_text_<?= $post['id'] ?>', '[', '](url)')">Link</button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="insertMarkdown('comment_text_<?= $post['id'] ?>', '> ', '')">Quote</button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="insertMarkdown('comment_text_<?= $post['id'] ?>', '- ', '')">List</button>
                                </div>
                                <textarea id="comment_text_<?= $post['id'] ?>" name="comment_text" class="form-control" rows="3" required></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="comment_file_<?= $post['id'] ?>" class="form-label">Прикрепить файл (макс. <?= Attachment::COMMENT_MAX_FILES ?> файлов, до 1MB)</label>
                                <input type="file" id="comment_file_<?= $post['id'] ?>" name="comment_file" class="form-control" accept=".jpg,.jpeg,.png,.gif,.pdf,.zip" onchange="validateFileSize(this, 1)">
                            </div>
                            <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                            <button type="submit" class="btn btn-primary">Отправить</button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-secondary mb-0">Чтобы оставить комментарий, <a href="auth/login.php">войдите</a> или <a href="auth/register.php">зарегистрируйтесь</a>.</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleEditForm(type, id) {
    const form = document.getElementById('edit-form-' + type + '-' + id);
    if (form) {
        form.classList.toggle('d-none');
    }
}

function toggleEditTopicForm() {
    const form = document.getElementById('edit-topic-form');
    if (form) {
        form.classList.toggle('d-none');
    }
}

function insertMarkdown(textareaId, before, after) {
    const textarea = document.getElementById(textareaId);
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const selectedText = textarea.value.substring(start, end) || 'текст';
    const newText = textarea.value.substring(0, start) + before + selectedText + after + textarea.value.substring(end);
    
    textarea.value = newText;
    textarea.focus();
    textarea.selectionStart = start + before.length;
    textarea.selectionEnd = start + before.length + selectedText.length;
}

function validateFileSize(fileInput, maxSizeMB) {
    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        return true; // No file selected is OK
    }
    
    for (let i = 0; i < fileInput.files.length; i++) {
        const file = fileInput.files[i];
        const maxSizeBytes = maxSizeMB * 1024 * 1024;
        
        if (file.size > maxSizeBytes) {
            alert(`❌ Файл "${file.name}" слишком большой!\n\nМаксимальный размер: ${maxSizeMB}MB\nВаш файл: ${(file.size / 1024 / 1024).toFixed(2)}MB`);
            fileInput.value = ''; // Clear the input
            return false;
        }
    }
    return true;
}

function validateCommentForm(formElement) {
    // Check for attachments[] (from reply forms in renderComments)
    const fileInputMultiple = formElement.querySelector('input[name="attachments[]"]');
    if (fileInputMultiple && fileInputMultiple.files.length > 0) {
        for (let i = 0; i < fileInputMultiple.files.length; i++) {
            const file = fileInputMultiple.files[i];
            const maxSizeBytes = 1 * 1024 * 1024;
            if (file.size > maxSizeBytes) {
                alert(`❌ Файл "${file.name}" слишком большой!\n\nМаксимальный размер: 1MB\nВаш файл: ${(file.size / 1024 / 1024).toFixed(2)}MB`);
                fileInputMultiple.value = '';
                return false;
            }
        }
    }
    
    // Check for single comment_file (from main comment form)
    const fileInput = formElement.querySelector('input[name="comment_file"]');
    if (fileInput) {
        return validateFileSize(fileInput, 1); // 1MB for comments
    }
    return true;
}

function insertQuote(commentId, postId, preview) {
    const textareas = document.querySelectorAll('textarea[name="comment_text"]');
    let targetTextarea = null;
    
    if (commentId > 0) {
        // Вложенный ответ - найти форму ответа на конкретный комментарий
        targetTextarea = document.getElementById('reply-textarea-' + commentId);
    } else {
        // Цитирование поста - найти основную форму комментария
        textareas.forEach(ta => {
            const postInput = ta.closest('form').querySelector('input[name="post_id"]');
            if (postInput && postInput.value == postId) {
                targetTextarea = ta;
            }
        });
    }
    
    if (targetTextarea) {
        const quoteText = '\n> Цитата:\n> ' + preview + '\n';
        targetTextarea.value += quoteText;
        targetTextarea.focus();
        targetTextarea.scrollIntoView({ behavior: 'smooth' });
    }
}

function setParentComment(commentId) {
    // This function can be used to scroll to or focus on the reply form
    const replyForm = document.getElementById('replyForm' + commentId);
    if (replyForm) {
        const textarea = replyForm.querySelector('textarea[name="content"]');
        if (textarea) {
            textarea.focus();
        }
    }
}

function deleteComment(commentId) {
    if (confirm('Вы уверены, что хотите удалить этот комментарий?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="delete_comment_id" value="' + commentId + '">';
        document.body.appendChild(form);
        form.submit();
    }
}
</script>
</body>
</html>
