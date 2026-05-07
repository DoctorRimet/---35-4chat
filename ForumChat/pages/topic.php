<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Topic.php';
require_once __DIR__ . '/../classes/Post.php';
require_once __DIR__ . '/../classes/Comment.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Category.php';
require_once __DIR__ . '/../classes/Attachment.php';
require_once __DIR__ . '/../classes/NotificationManager.php';
require_once __DIR__ . '/../classes/AutoModerator.php';
require_once __DIR__ . '/../classes/PostModeration.php';
require_once __DIR__ . '/../classes/ForbiddenWords.php';
require_once __DIR__ . '/../functions/markdown.php';

$db = new Database();
$conn = $db->getConnection();

$topicModel = new Topic($conn);
$postModel = new Post($conn);
$commentModel = new Comment($conn);
$userModel = new User($conn);
$categoryModel = new Category($conn);
$attachmentModel = new Attachment($conn);
$notificationManager = new NotificationManager($conn);
$postModerator = new PostModeration($conn);
$forbiddenWords = new ForbiddenWords($conn);
$autoModerator = new AutoModerator($conn, $postModerator, $forbiddenWords);

$currentUserId = $_SESSION['user_id'] ?? null;
$loggedIn = !empty($currentUserId);
$currentUserRole = $_SESSION['user_role'] ?? 'user';

// Проверка на блокировку пользователя
$userBanned = false;
$banInfo = null;
if ($currentUserId) {
    $currentUser = $userModel->getById($currentUserId);
    $userBanned = $userModel->isBanned($currentUser);
    $banInfo = $_SESSION['ban_info'] ?? null;
}

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
$topicAuthorProfile = $userModel->getProfile($topicAuthor['id']);
$topicAuthor['avatar_url'] = $topicAuthorProfile['avatar_url'] ?? $topicAuthor['avatar_url'] ?? null;
$topicCategory = null;
if (!empty($topic['category_id'])) {
    $topicCategory = $categoryModel->getById($topic['category_id']);
}

$topicTags = $topicModel->getTagsByTopic($topicId);
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['comment_text'], $_POST['post_id']) || isset($_POST['content'], $_POST['post_id']))) {
    if (!$loggedIn) {
        $errors[] = 'Только авторизованные пользователи могут оставлять комментарии.';
    } elseif ($userBanned) {
        $errors[] = 'Ваш аккаунт заблокирован. Создание контента невозможно.';
    } else {
        $postId = (int) $_POST['post_id'];
        $parentCommentId = isset($_POST['parent_comment_id']) ? (int) $_POST['parent_comment_id'] : null;
        $content = trim($_POST['comment_text'] ?? $_POST['content']);

        if ($topic['status'] === 'closed') {
            $errors[] = 'Тема закрыта, добавление новых ответов запрещено.';
        } elseif ($postId <= 0 || mb_strlen($content) < 3) {
            $errors[] = 'Комментарий должен содержать минимум 3 символа.';
        } elseif (mb_strlen($content) > 5000) {
            $errors[] = 'Комментарий не должен превышать 5000 символов.';
        } else {
            // Проверяем доступ к посту (если пост скрыт)
            $checkPostStmt = $conn->prepare("SELECT author_id, hidden FROM posts WHERE id = :post_id");
            $checkPostStmt->execute([':post_id' => $postId]);
            $checkPost = $checkPostStmt->fetch(PDO::FETCH_ASSOC);

            if (!$checkPost) {
                $errors[] = 'Пост не найден.';
            } elseif ($checkPost['hidden']) {
                // Если пост скрыт - проверяем может ли пользователь его видеть
                $canComment = false;
                if (in_array($currentUserRole, ['admin', 'moderator'])) {
                    $canComment = true;
                } elseif ($checkPost['author_id'] == $currentUserId) {
                    $canComment = true;
                }

                if (!$canComment) {
                    $errors[] = 'Этот пост скрыт. Вы не можете добавлять комментарии к скрытому посту.';
                }
            }

            if (empty($errors)) {
                // Проверяем на запрещённые слова в комментарии
                $checkResult = $forbiddenWords->checkContentDetailed($content);

                if ($checkResult['has_forbidden']) {
                    // Есть запрещённые слова
                    $violations_list = implode(', ', array_keys($checkResult['words_found']));
                    $errors[] = 'Ваш комментарий содержит запрещённые слова: ' . htmlspecialchars($violations_list);
                } else {
                    // Комментарий в порядке
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
                            } elseif (isset($_GET['debug'])) {
                                $errors[] = 'Файл загружен успешно: ' . $uploadResult['filename'];
                            }
                        }

                // Redirect only if no file upload errors
                        if (!$fileUploadError) {
                            header('Location: topic.php?id=' . $topicId . '#post-' . $postId);
                            exit;
                        }
                    } else {
                        $errors[] = 'Не удалось сохранить комментарий. Попробуйте ещё раз.';
                    }
                }
            }
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
        $editStatus = $topic['status'];
        $editPinned = $topic['is_pinned'] ?? 0;

        if (in_array($currentUserRole, ['moderator', 'admin'])) {
            $editStatus = $_POST['edit_topic_status'] ?? $topic['status'];
            $editPinned = isset($_POST['edit_topic_pinned']) ? 1 : 0;
        }

        if (empty($editTitle)) {
            $errors[] = 'Заголовок темы не может быть пустым.';
        } elseif (mb_strlen($editDescription) < 10) {
            $errors[] = 'Описание должно содержать минимум 10 символов.';
        } elseif (!in_array($editStatus, ['open', 'closed', 'archived', 'draft'])) {
            $errors[] = 'Неверный статус темы.';
        } else {
            $topicModel->id = $editTopicId;
            $topicModel->title = $editTitle;
            $topicModel->description = $editDescription;
            $topicModel->category_id = $editCategoryId ?: null;
            $topicModel->status = $editStatus;
            $topicModel->is_pinned = $editPinned;

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
            // Проверяем на запрещённые слова
            $checkResult = $autoModerator->checkPostOnUpdate($editPostId, $editContent, $currentUserId);

            if ($checkResult['hidden']) {
                // Пост был скрыт системой
                $errors[] = 'Ваш пост содержит запрещённые слова и был скрыт.'  ;
            } else {
                // Пост в порядке, обновляем
                $postModel->id = $editPostId;
                $postModel->content = $editContent;
                if ($postModel->update()) {
                    header('Location: topic.php?id=' . $topicId . '#post-' . $editPostId);
                    exit;
                } else {
                    $errors[] = 'Не удалось обновить пост.';
                }
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
$allPosts = $postsStmt->fetchAll(PDO::FETCH_ASSOC);

// Фильтруем посты по видимости: скрытые видят только администраторы/модераторы и автор
$posts = [];
foreach ($allPosts as $post) {
    if (!$post['hidden']) {
        // Видимые посты видят все
        $posts[] = $post;
    } elseif ($loggedIn && (in_array($currentUserRole, ['admin', 'moderator']) || $post['author_id'] == $currentUserId)) {
        // Скрытые посты видят только админы/модераторы и автор
        $posts[] = $post;
    }
}

function buildCommentTree(array $comments)
{
    $tree = [];
    foreach ($comments as $comment) {
        $parentId = !empty($comment['parent_comment_id']) ? (int)$comment['parent_comment_id'] : 0;
        $tree[$parentId][] = $comment;
    }
    return $tree;
}

function renderComments(array $tree, int $parentId = 0, int $level = 0)
{
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
        $commentAuthorProfile = $GLOBALS['userModel']->getProfile($commentAuthor['id']);
        $commentAuthor['avatar_url'] = $commentAuthorProfile['avatar_url'] ?? $commentAuthor['avatar_url'] ?? null;
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
        echo '<p style="white-space: pre-wrap; word-break: break-word;">' . parseMarkdown(nl2br(htmlspecialchars($comment['content']))) . '</p>';

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
function displayAttachment($attachment)
{
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
        echo ' (' . round($attachment['file_size'] / 1024) . ' KB)';
        echo '</div>';
    }
}

function formatTime($timestamp)
{
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

function getAuthorBadge($userRole)
{
    $badges = [
        'admin' => '<span class="badge bg-danger">👑 Администратор</span>',
        'moderator' => '<span class="badge bg-warning text-dark">⚡ Модератор</span>',
        'user' => '<span class="badge bg-secondary">👤 Пользователь</span>'
    ];
    return $badges[$userRole] ?? $badges['user'];
}

function getAvatarHtml($username, $avatarUrl = null)
{
    if ($avatarUrl) {
        if (filter_var($avatarUrl, FILTER_VALIDATE_URL)) {
            // External URL
            return '<img src="' . htmlspecialchars($avatarUrl) . '?t=' . time() . '" class="rounded-circle" width="40" height="40" alt="' . htmlspecialchars($username) . '" title="' . htmlspecialchars($username) . '" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'inline-flex\';">' .
                   '<div style="display:none; align-items:center; justify-content:center; width:40px; height:40px; border-radius:50%; background-color:#ccc; color:white; font-weight:bold; font-size:18px;" title="' . htmlspecialchars($username) . '">' . htmlspecialchars(mb_substr($username, 0, 1)) . '</div>';
        }

        $localPath = $avatarUrl;
        if (strpos($avatarUrl, '/') === 0) {
            $localPath = __DIR__ . $avatarUrl;
        } else {
            $localPath = __DIR__ . '/' . ltrim($avatarUrl, '/');
        }

        if (file_exists($localPath)) {
            // Local file
            return '<img src="' . htmlspecialchars($avatarUrl) . '?t=' . time() . '" class="rounded-circle" width="40" height="40" alt="' . htmlspecialchars($username) . '" title="' . htmlspecialchars($username) . '">';
        }
    }
    // Default avatar with first letter
    $initials = mb_substr($username, 0, 1);
    $colors = ['#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8', '#F7DC6F'];
    $colorIndex = ord($initials) % count($colors);
    $bgColor = $colors[$colorIndex];
    return '<div style="display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:50%;background-color:' . $bgColor . ';color:white;font-weight:bold;font-size:18px;" title="' . htmlspecialchars($username) . '">' . htmlspecialchars($initials) . '</div>';
}

function canEditTopic($topic, $userId, $userRole)
{
    if (in_array($userRole, ['moderator', 'admin'])) {
        return true;
    }
    if ($topic['author_id'] == $userId) {
        return true;
    }
    return false;
}

function canDeleteTopic($topic, $userId, $userRole)
{
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

function canEditPost($post, $userId, $userRole)
{
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

function canDeletePost($post, $userId, $userRole)
{
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

function canEditComment($comment, $userId, $userRole)
{
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

function canDeleteComment($comment, $userId, $userRole)
{
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

// Переменные для навигации
$username = htmlspecialchars($_SESSION['username'] ?? 'Гость');
$is_guest = !isset($_SESSION['user_id']) || $_SESSION['user_id'] == 0;
$userRole = $_SESSION['user_role'] ?? 'user';
$currentUserAvatar = '';

if (!$is_guest && isset($_SESSION['user_id'])) {
    $currentUserData = $userModel->getById($_SESSION['user_id']);
    $currentUserProfile = $userModel->getProfile($_SESSION['user_id']);

    // Ensure profile exists
    if (!$currentUserProfile) {
        $userModel->updateProfile($_SESSION['user_id'], null, null, null, null);
        $currentUserProfile = $userModel->getProfile($_SESSION['user_id']);
    }

    $currentUserAvatar = $currentUserProfile['avatar_url'] ?? $currentUserData['avatar_url'] ?? '';
}

// Получаем количество непрочитанных уведомлений
$unread_count = 0;
if (!$is_guest && isset($_SESSION['user_id'])) {
    $unread_count = $notificationManager->getUnreadCount($_SESSION['user_id']);
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
        body { background-color: #f0f2f5; }
        .navbar-brand-icon { width: 36px; height: 36px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; }
        .navbar { box-shadow: 0 1px 8px rgba(0,0,0,.06); }
        .avatar-sm { width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #8b5cf6); display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: .75rem; font-weight: 700; flex-shrink: 0; }
        .post-card { border: 1px solid #dee2e6; border-radius: .75rem; padding: 1.25rem; }
        .comment-card { background: #f8f9fa; border-radius: .75rem; padding: 1rem; margin-top: .75rem; }
        .comment-form textarea { min-height: 120px; }
        .topic-badge { font-size: .85rem; }
    </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-light bg-white sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="../index.php">
            <div class="navbar-brand-icon">💬</div>
            <span>Forum<span style="color:#6366f1">Chat</span></span>
        </a>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link" href="../index.php">
                        <i class="bi bi-house me-1"></i>Главная
                    </a>
                </li>
                <?php if ($loggedIn) : ?>
                <li class="nav-item">
                    <a class="nav-link" href="../notifications.php">
                        <i class="bi bi-bell me-1"></i>Сообщения
                        <?php if ($unread_count > 0) : ?>
                            <span class="badge bg-danger ms-1"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../home/profile.php">
                        <i class="bi bi-person me-1"></i>Профиль
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../create.php">
                        <i class="bi bi-plus-circle me-1"></i>Создать тему
                    </a>
                </li>
                    <?php if (in_array($currentUserRole, ['admin', 'moderator'])) : ?>
                <li class="nav-item">
                    <a class="nav-link text-primary fw-semibold" href="../admin_panel.php">
                        <i class="bi bi-shield-check me-1"></i>Модерация
                    </a>
                </li>
                    <?php endif; ?>
                <?php endif; ?>
            </ul>
            <div class="d-flex align-items-center gap-2">
                <?php if ($loggedIn) : ?>
                    <a href="../auth/logout.php" class="btn btn-sm btn-outline-danger rounded-3">
                        <i class="bi bi-box-arrow-right me-1"></i>Выйти
                    </a>
                <?php else : ?>
                    <a href="../auth/login.php" class="btn btn-sm btn-outline-primary rounded-3">
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
                                <?php if (!empty($topic['updated_at']) && $topic['updated_at'] != $topic['created_at']) : ?>
                                    <span class="badge bg-info text-dark topic-badge">Отредактирована</span>
                                <?php endif; ?>
                                <?php if (!empty($topic['is_pinned'])) : ?>
                                    <span class="badge bg-warning text-dark topic-badge">Закреплена</span>
                                <?php endif; ?>
                                <?php if (!empty($topicCategory)) : ?>
                                    <span class="badge bg-primary bg-opacity-15 text-primary topic-badge"><?= htmlspecialchars($topicCategory['name']) ?></span>
                                <?php endif; ?>
                                <span class="badge bg-secondary topic-badge">ID <?= $topic['id'] ?></span>
                                <a class="badge bg-success topic-badge text-decoration-none" href="../home/profile.php?id=<?= $topicAuthor ? (int)$topicAuthor['id'] : 0 ?>">
                                    Автор: <?= htmlspecialchars($topicAuthor ? $topicAuthor['username'] : 'Неизвестно') ?>
                                </a>
                            </div>
                            <p class="text-muted mb-0">Создана <?= htmlspecialchars($topic['created_at']) ?></p>
                            <?php if (!empty($topicTags)) : ?>
                                <div class="mt-3">
                                    <?php foreach ($topicTags as $tag) : ?>
                                        <a href="../index.php?tag=<?= urlencode($tag['name']) ?>" class="badge bg-secondary bg-opacity-15 text-dark me-1">#<?= htmlspecialchars($tag['name']) ?></a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($loggedIn && (canEditTopic($topic, $currentUserId, $currentUserRole) || canDeleteTopic($topic, $currentUserId, $currentUserRole))) : ?>
                            <div class="btn-group ms-2" role="group">
                                <?php if (canEditTopic($topic, $currentUserId, $currentUserRole)) : ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleEditTopicForm()">Редактировать</button>
                                <?php endif; ?>
                                <?php if (canDeleteTopic($topic, $currentUserId, $currentUserRole)) : ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="delete_topic_id" value="<?= $topic['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Удалить эту тему?')">Удалить</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($loggedIn && $currentUserId !== $topic['author_id']) : ?>
                            <button type="button" class="btn btn-sm btn-outline-warning ms-2" data-bs-toggle="modal" data-bs-target="#reportTopicModal">
                                <i class="bi bi-exclamation-circle me-1"></i>Пожаловаться
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($topic['description'])) : ?>
                        <p class="mt-3 mb-0"><?= nl2br(htmlspecialchars($topic['description'])) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (canEditTopic($topic, $currentUserId, $currentUserRole)) : ?>
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
                                    foreach ($categories as $cat) :
                                        ?>
                                        <option value="<?= $cat['id'] ?>" <?= ($topic['category_id'] == $cat['id']) ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if (in_array($currentUserRole, ['moderator', 'admin'])) : ?>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Статус темы</label>
                                        <select name="edit_topic_status" class="form-select">
                                            <option value="open" <?= $topic['status'] === 'open' ? 'selected' : '' ?>>Открыта</option>
                                            <option value="closed" <?= $topic['status'] === 'closed' ? 'selected' : '' ?>>Закрыта</option>
                                            <option value="archived" <?= $topic['status'] === 'archived' ? 'selected' : '' ?>>Архив</option>
                                            <option value="draft" <?= $topic['status'] === 'draft' ? 'selected' : '' ?>>Черновик</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Закрепить</label>
                                        <div class="form-check form-switch mt-2">
                                            <input class="form-check-input" type="checkbox" id="edit_topic_pinned" name="edit_topic_pinned" <?= !empty($topic['is_pinned']) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="edit_topic_pinned">Закрепить тему сверху</label>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
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

            <?php if (!empty($errors)) : ?>
                <div class="alert alert-danger alert-auto-close" role="alert">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error) : ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <script>
                    // Auto-close forbidden words alert after 5 seconds
                    document.addEventListener('DOMContentLoaded', function() {
                        const alerts = document.querySelectorAll('.alert-auto-close');
                        alerts.forEach(alert => {
                            setTimeout(() => {
                                alert.style.transition = 'opacity 0.5s ease-out';
                                alert.style.opacity = '0';
                                setTimeout(() => alert.remove(), 500);
                            }, 5000);
                        });
                    });
                </script>
            <?php endif; ?>

            <?php if ($userBanned && $banInfo) : ?>
                <div class="alert alert-warning" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <strong>Ваш аккаунт заблокирован!</strong>
                    <?php if ($banInfo['permanent']) : ?>
                        Вы не можете добавлять комментарии.
                    <?php else : ?>
                        Вы не можете добавлять комментарии до <?php echo date('d.m.Y H:i', strtotime($banInfo['ban_until'])); ?>.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($posts)) : ?>
                <div class="alert alert-warning">В этой теме пока нет сообщений.</div>
            <?php endif; ?>

            <?php foreach ($posts as $post) : ?>
                <?php
                $comments = $commentModel->getByPostId($post['id'])->fetchAll(PDO::FETCH_ASSOC);
                $postAuthor = $userModel->getById($post['author_id']);
                $postAuthorProfile = $userModel->getProfile($postAuthor['id']);
                $postAuthor['avatar_url'] = $postAuthorProfile['avatar_url'] ?? $postAuthor['avatar_url'] ?? null;
                $postNumber = array_search($post['id'], array_column($posts, 'id')) + 1;

                // Проверяем может ли текущий пользователь видеть содержимое скрытого поста
                $canViewHiddenContent = false;
                if ($post['hidden']) {
                    if (in_array($currentUserRole, ['admin', 'moderator'])) {
                        $canViewHiddenContent = true;
                    } elseif ($loggedIn && $post['author_id'] == $currentUserId) {
                        $canViewHiddenContent = true;
                    }
                }
                ?>
                <div id="post-<?= $post['id'] ?>" class="post-card mb-4">
                    <!-- Post Header with Author Info -->
                    <div class="d-flex align-items-start gap-3 mb-3" style="border-bottom: 1px solid #dee2e6; padding-bottom: 12px;">
                        <div>
                            <?= getAvatarHtml($postAuthor['username'] ?? 'Unknown', $postAuthor['avatar_url'] ?? null) ?>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <strong><a href="../home/profile.php?id=<?= $postAuthor ? (int)$postAuthor['id'] : 0 ?>" class="text-decoration-none text-dark"><?= htmlspecialchars($postAuthor['username'] ?? 'Unknown') ?></a></strong>
                                <?= getAuthorBadge($postAuthor['user_role'] ?? 'user') ?>
                            </div>
                            <div style="font-size: 0.85rem; color: #6c757d;">
                                <span title="<?= htmlspecialchars($post['created_at']) ?>">📅 <?= formatTime($post['created_at']) ?></span>
                                <?php if (!empty($post['updated_at']) && $post['updated_at'] != $post['created_at']) : ?>
                                    <span style="margin-left: 10px;" title="<?= htmlspecialchars($post['updated_at']) ?>">✏️ ред. <?= formatTime($post['updated_at']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <span class="badge bg-info">#<?= $postNumber ?></span>
                        </div>
                    </div>

                    <!-- Post Header Controls -->
                    <div class="d-flex align-items-center justify-content-end mb-2" style="gap: 10px;">
                            <span class="badge bg-secondary topic-badge">Комментариев: <?= count($comments) ?></span>
                            
                            <?php if ($loggedIn && in_array($currentUserRole, ['admin', 'moderator'])) : ?>
                                <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#moderationModal<?= $post['id'] ?>">
                                    <i class="bi bi-shield-check me-1"></i>Модерация
                                </button>
                            <?php endif; ?>
                            
                            <?php if ($loggedIn && $post['author_id'] == $currentUserId && (!$post['hidden'] || $canViewHiddenContent)) : ?>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="delete_post_id" value="<?= $post['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Удалить этот пост?')">Удалить</button>
                                </form>
                            <?php endif; ?>
                            
                            <?php if ($loggedIn && canEditPost($post, $currentUserId, $currentUserRole) && (!$post['hidden'] || $canViewHiddenContent)) : ?>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleEditForm('post', <?= $post['id'] ?>)">Редактировать</button>
                            <?php endif; ?>
                            
                            <?php if ($loggedIn && $post['author_id'] !== $currentUserId && (!$post['hidden'] || $canViewHiddenContent)) : ?>
                                <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#reportPostModal<?= $post['id'] ?>">
                                    <i class="bi bi-exclamation-circle me-1"></i>Пожаловаться
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Post Content (Hidden if post is hidden) -->
                    <?php if ($post['hidden'] && !$canViewHiddenContent) : ?>
                        <div class="alert alert-danger mb-3">
                            <i class="bi bi-eye-slash me-2"></i><strong>Этот пост скрыт</strong><br>
                            <small class="text-muted">Причина: <?= htmlspecialchars($post['hidden_reason'] ?? 'Не указана') ?></small>
                        </div>
                    <?php else : ?>
                        <?php if ($post['hidden'] && $canViewHiddenContent) : ?>
                            <div class="alert alert-warning mb-3">
                                <i class="bi bi-exclamation-triangle me-2"></i><strong>⚠️ Этот пост скрыт</strong> (видно только администраторам и автору)<br>
                                <small class="text-muted">Причина: <?= htmlspecialchars($post['hidden_reason'] ?? 'Не указана') ?></small>
                            </div>
                        <?php endif; ?>
                        <div style="background-color:#f8f9fa;padding:15px;border-radius:5px;margin:15px 0;">
                            <?= parseMarkdown(nl2br(htmlspecialchars($post['content']))) ?>
                        </div>
                    <?php endif; ?>
                    
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
                                echo ' (' . round($att['file_size'] / 1024) . ' KB)';
                                echo '</div>';
                            }
                        }
                        echo '</div>';
                    }
                    ?>
                    
                    <?php if (!$post['hidden'] || $canViewHiddenContent) : ?>
                    <div style="margin-top:10px;font-size:0.9rem;">
                        <button type="button" class="btn btn-sm btn-light" onclick="insertQuote(0, <?= $post['id'] ?>, '<?= addslashes(htmlspecialchars(mb_substr($post['content'], 0, 50))) ?>...')">Цитировать</button>
                    </div>
                    <?php endif; ?>

                    <?php if (canEditPost($post, $currentUserId, $currentUserRole) && (!$post['hidden'] || $canViewHiddenContent)) : ?>
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

                    <?php if (!empty($comments) && (!$post['hidden'] || $canViewHiddenContent)) : ?>
                        <div class="mb-4">
                            <h6 class="mb-3">Комментарии</h6>
                            <?php
                                $commentTree = buildCommentTree($comments);
                                renderComments($commentTree);
                            ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($post['hidden'] && !$canViewHiddenContent) : ?>
                        <div class="alert alert-danger mb-0">
                            <i class="bi bi-lock me-2"></i>Этот пост скрыт. Добавление комментариев и других действий невозможно.
                        </div>
                    <?php elseif ($topic['status'] === 'closed') : ?>
                        <div class="alert alert-warning mb-0">
                            <i class="bi bi-lock-fill me-2"></i>Тема закрыта. Добавление новых комментариев запрещено.
                        </div>
                    <?php elseif ($loggedIn) : ?>
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
                    <?php else : ?>
                        <div class="alert alert-secondary mb-0">Чтобы оставить комментарий, <a href="../auth/login.php">войдите</a> или <a href="../auth/register.php">зарегистрируйтесь</a>.</div>
                    <?php endif; ?>
                </div>

                <!-- Модальное окно для модерации поста -->
                <?php if (in_array($currentUserRole, ['admin', 'moderator'])) : ?>
                <div class="modal fade" id="moderationModal<?= $post['id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header bg-warning bg-opacity-50">
                                <h5 class="modal-title"><i class="bi bi-shield-check me-2"></i>Модерация поста</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p class="mb-3"><strong>Автор:</strong> <?= htmlspecialchars($postAuthor['username'] ?? 'Unknown') ?></p>
                                <p class="mb-3"><strong>Содержание:</strong> <?= mb_substr(htmlspecialchars(strip_tags($post['content'])), 0, 100) ?>...</p>
                                
                                <div class="alert alert-info mb-3">
                                    📋 Статус: 
                                    <?php if ($post['hidden']) : ?>
                                        <span class="badge bg-danger">Скрыт</span> - <?= htmlspecialchars($post['hidden_reason']) ?>
                                    <?php else : ?>
                                        <span class="badge bg-success">Видимый</span>
                                    <?php endif; ?>
                                </div>

                                <h6 class="mb-3">Действия модерации:</h6>
                                <div class="d-grid gap-2">
                                    <?php if (!$post['hidden']) : ?>
                                        <form method="POST" class="moderation-form" action="api_moderation.php">
                                            <input type="hidden" name="action" value="hide_post">
                                            <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                                            <div class="mb-2">
                                                <label class="form-label">Причина скрытия:</label>
                                                <select name="reason" class="form-select form-select-sm" required>
                                                    <option value="">-- Выбери причину --</option>
                                                    <option value="Содержит запрещённые слова">Содержит запрещённые слова</option>
                                                    <option value="Спам">Спам</option>
                                                    <option value="Оскорбительный контент">Оскорбительный контент</option>
                                                    <option value="Нарушение правил">Нарушение правил</option>
                                                    <option value="Другое">Другое</option>
                                                </select>
                                            </div>
                                            <button type="submit" class="btn btn-warning btn-sm w-100">
                                                <i class="bi bi-eye-slash me-1"></i>Скрыть пост
                                            </button>
                                        </form>
                                    <?php else : ?>
                                        <div class="d-flex gap-2">
                                            <a href="#" class="btn btn-info btn-sm flex-fill" onclick="viewHiddenPost(<?= $post['id'] ?>, <?= $topicId ?>); return false;">
                                                <i class="bi bi-eye me-1"></i>Посмотреть
                                            </a>
                                            <form method="POST" action="api_moderation.php" class="flex-fill">
                                                <input type="hidden" name="action" value="unhide_post">
                                                <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                                                <button type="submit" class="btn btn-success btn-sm w-100">
                                                    <i class="bi bi-eye me-1"></i>Показать
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>

                                    <form method="POST" action="api_moderation.php">
                                        <input type="hidden" name="action" value="mark_for_deletion">
                                        <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                                        <div class="mb-2">
                                            <label class="form-label form-label-sm">Причина удаления:</label>
                                            <select name="reason" class="form-select form-select-sm" required>
                                                <option value="">-- Выбери причину --</option>
                                                <option value="Спам">Спам</option>
                                                <option value="Оскорбления">Оскорбления</option>
                                                <option value="Реклама">Реклама</option>
                                                <option value="Нарушение правил">Нарушение правил</option>
                                                <option value="Не по теме">Не по теме</option>
                                                <option value="Другое">Другое</option>
                                            </select>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-check">
                                                <input type="checkbox" class="form-check-input" id="scheduleDelete<?= $post['id'] ?>" onchange="toggleScheduleInput(<?= $post['id'] ?>)">
                                                <span class="form-check-label">Удалить через (часов)</span>
                                            </label>
                                        </div>
                                        <div class="mb-2" id="hoursInput<?= $post['id'] ?>" style="display:none;">
                                            <input type="number" name="hours" class="form-control form-control-sm" min="1" max="168" value="24" placeholder="Ввести часы">
                                        </div>
                                        <button type="submit" class="btn btn-danger btn-sm w-100">
                                            <i class="bi bi-trash me-1"></i>Отметить на удаление
                                        </button>
                                    </form>

                                    <form method="POST" action="api_moderation.php" class="mt-2">
                                        <input type="hidden" name="action" value="delete_post_now">
                                        <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                                        <div class="mb-2">
                                            <label class="form-label form-label-sm">Причина удаления:</label>
                                            <select name="reason" class="form-select form-select-sm" required>
                                                <option value="">-- Выбери причину --</option>
                                                <option value="Спам">Спам</option>
                                                <option value="Оскорбления">Оскорбления</option>
                                                <option value="Реклама">Реклама</option>
                                                <option value="Нарушение правил">Нарушение правил</option>
                                                <option value="Не по теме">Не по теме</option>
                                                <option value="Другое">Другое</option>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-dark btn-sm w-100" onclick="return confirm('Удалить пост НЕМЕДЛЕННО? Это действие необратимо!')">
                                            <i class="bi bi-trash2 me-1"></i>Удалить сейчас
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleScheduleInput(postId) {
    const checkbox = document.getElementById('scheduleDelete' + postId);
    const input = document.getElementById('hoursInput' + postId);
    input.style.display = checkbox.checked ? 'block' : 'none';
}

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

function viewHiddenPost(postId, topicId) {
    // Создаём всплывающее окно для просмотра скрытого поста без его отмены скрытия
    const modalId = 'hiddenPostModal_' + postId;
    
    // Если модаль уже открыта, закрываем её
    const existingModal = document.getElementById(modalId);
    if (existingModal) {
        existingModal.remove();
        return;
    }
    
    // Загружаем пост через API
    fetch(`api/get_post.php?post_id=${postId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const post = data.post;
                const author = data.author;
                
                // Создаём HTML для модального окна
                const modalHtml = `
                    <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header bg-warning">
                                    <h6 class="modal-title" id="${modalId}Label">
                                        <i class="bi bi-eye-slash"></i> СКРЫТОЕ СООБЩЕНИЕ (только для просмотра)
                                    </h6>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="d-flex align-items-center mb-3 pb-3 border-bottom">
                                        <img src="${author.avatar_url || 'https://via.placeholder.com/40'}" alt="${author.username}" class="rounded-circle me-2" width="40" height="40">
                                        <div>
                                            <h6 class="mb-0"><strong>${author.username}</strong></h6>
                                            <small class="text-muted">${new Date(post.created_at).toLocaleString('ru-RU')}</small>
                                        </div>
                                    </div>
                                    <div class="post-content mb-3">
                                        ${post.content}
                                    </div>
                                    <div class="alert alert-info small">
                                        <strong>ℹ️ Статус:</strong> Пост скрыт <br/>
                                        <strong>Причина:</strong> ${post.hidden_reason || 'Не указана'}
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                // Добавляем модаль на страницу и открываем её
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = modalHtml;
                document.body.appendChild(tempDiv);
                
                const modal = new bootstrap.Modal(document.getElementById(modalId));
                modal.show();
                
                // Удаляем модаль после закрытия
                document.getElementById(modalId).addEventListener('hidden.bs.modal', function () {
                    this.remove();
                });
            } else {
                alert('Ошибка загрузки поста: ' + (data.error || 'Неизвестная ошибка'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка при загрузке поста');
        });
}
</script>

<!-- Модальные окна жалоб на посты -->
<?php foreach ($posts as $p) : ?>
<div class="modal fade" id="reportPostModal<?= $p['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning bg-opacity-10">
                <h5 class="modal-title"><i class="bi bi-exclamation-circle me-2"></i>Пожаловаться на сообщение</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form class="reportPostForm" method="POST">
                <div class="modal-body">
                    <p class="text-muted small mb-3">Сообщите модераторам, если это сообщение нарушает правила сообщества.</p>
                    <input type="hidden" name="post_id" value="<?= $p['id'] ?>">
                    <div class="mb-3">
                        <label class="form-label">Причина жалобы</label>
                        <textarea name="reason" class="form-control" rows="4" placeholder="Объясните, почему вы хотите пожаловаться на это сообщение..." required></textarea>
                        <small class="text-muted">Минимум 5 символов, максимум 500</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-warning">Отправить жалобу</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Модальное окно жалобы на тему -->
<div class="modal fade" id="reportTopicModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning bg-opacity-10">
                <h5 class="modal-title"><i class="bi bi-exclamation-circle me-2"></i>Пожаловаться на тему</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="reportTopicForm" method="POST">
                <div class="modal-body">
                    <p class="text-muted small mb-3">Сообщите модераторам, если эта тема нарушает правила сообщества.</p>
                    <div class="mb-3">
                        <label class="form-label">Причина жалобы</label>
                        <textarea name="reason" class="form-control" rows="4" placeholder="Объясните, почему вы хотите пожаловаться на эту тему..." required></textarea>
                        <small class="text-muted">Минимум 5 символов, максимум 500</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-warning">Отправить жалобу</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('reportTopicForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const reason = document.querySelector('#reportTopicForm textarea[name="reason"]').value;
    
    if (reason.length < 5) {
        alert('Причина должна содержать минимум 5 символов');
        return;
    }
    if (reason.length > 500) {
        alert('Причина не должна превышать 500 символов');
        return;
    }
    
    fetch('../api_complaints.php?action=report_topic', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'topic_id=<?= $topicId ?>&reason=' + encodeURIComponent(reason)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            bootstrap.Modal.getInstance(document.getElementById('reportTopicModal')).hide();
            document.getElementById('reportTopicForm').reset();
        } else {
            alert('Ошибка: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Ошибка при отправке жалобы');
    });
});
</script>

<script>
document.querySelectorAll('.reportPostForm').forEach(form => {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const postId = this.querySelector('input[name="post_id"]').value;
        const reason = this.querySelector('textarea[name="reason"]').value;
        
        if (reason.length < 5) {
            alert('Причина должна содержать минимум 5 символов');
            return;
        }
        if (reason.length > 500) {
            alert('Причина не должна превышать 500 символов');
            return;
        }
        
        fetch('../api_complaints.php?action=report_post', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'post_id=' + encodeURIComponent(postId) + '&reason=' + encodeURIComponent(reason)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                const modal = bootstrap.Modal.getInstance(document.getElementById('reportPostModal' + postId));
                if (modal) modal.hide();
                this.reset();
            } else {
                alert('Ошибка: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка при отправке жалобы');
        });
    });
});
</script>

</body>
</html>
