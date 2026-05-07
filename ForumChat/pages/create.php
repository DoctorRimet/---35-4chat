<?php
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Topic.php';
require_once __DIR__ . '/../classes/Post.php';
require_once __DIR__ . '/../classes/Category.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/NotificationManager.php';
require_once __DIR__ . '/../classes/AutoModerator.php';
require_once __DIR__ . '/../classes/PostModeration.php';
require_once __DIR__ . '/../classes/ForbiddenWords.php';
require_once __DIR__ . '/../functions/markdown.php';

    $db = new Database();
    $conn = $db->getConnection();
    $topicModel = new Topic($conn);
    $postModel = new Post($conn);
    $categoryModel = new Category($conn);
    $notificationManager = new NotificationManager($conn);
    $postModerator = new PostModeration($conn);
    $forbiddenWords = new ForbiddenWords($conn);
    $autoModerator = new AutoModerator($conn, $postModerator, $forbiddenWords);
    $userModel = new User($conn);

    $currentUserId = $_SESSION['user_id'] ?? null;

    // Проверка на блокировку пользователя
    $userBanned = false;
    $banInfo = null;
if ($currentUserId) {
    $currentUser = $userModel->getById($currentUserId);
    $userBanned = $userModel->isBanned($currentUser);
    $banInfo = $_SESSION['ban_info'] ?? null;
}

    // Проверка на блокировку пользователя
if ($currentUserId) {
    $currentUser = $userModel->getById($currentUserId);
    if ($userModel->isBanned($currentUser)) {
        $errors[] = 'Ваш аккаунт заблокирован. Создание контента невозможно.';
    }
}
    $errors = [];
    $title = '';
    $description = '';
    $category_id = '';
    $tags_input = '';
    $action = $_POST['action'] ?? 'publish';
    $draft_id = $_POST['draft_id'] ?? null;

    $categoriesStmt = $categoryModel->getAll();
    $categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Load draft if editing
if (isset($_GET['draft'])) {
    $draft_topic_id = (int)$_GET['draft'];
    $draft_topic = $topicModel->getById($draft_topic_id);
    if ($draft_topic && $draft_topic['author_id'] == $currentUserId && $draft_topic['status'] == 'draft') {
        $title = $draft_topic['title'];
        $description = $draft_topic['description'];
        $category_id = $draft_topic['category_id'];
        $draft_id = $draft_topic_id;
        $draftTags = $topicModel->getTagsByTopic($draft_topic_id);
        $tags_input = implode(', ', array_column($draftTags, 'name'));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверка на блокировку перед созданием контента
    if ($userBanned) {
        $errors[] = 'Ваш аккаунт заблокирован. Создание контента невозможно.';
    }

    if (!empty($errors)) {
        // Не продолжаем обработку если есть ошибки
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category_id = $_POST['category_id'] ?? null;
        $tags_input = trim($_POST['tags'] ?? '');
        $action = $_POST['action'] ?? 'publish';

        $tags = array_filter(array_map('trim', preg_split('/[,;]+/', $tags_input)), function ($tag) {
            return $tag !== '';
        });

        if ($action === 'save_draft') {
            // Save as draft
            if ($title === '') {
                $errors[] = 'Заголовок темы не может быть пустым для черновика.';
            } elseif (mb_strlen($title) > 255) {
                $errors[] = 'Заголовок не должен превышать 255 символов.';
            } elseif (mb_strlen($description) > 10000) {
                $errors[] = 'Описание не должно превышать 10000 символов.';
            }
            if (empty($errors)) {
                if ($draft_id) {
                    // Update existing draft
                    $topicModel->id = $draft_id;
                    $topicModel->title = $title;
                    $topicModel->description = $description;
                    $topicModel->category_id = $category_id ?: null;
                    if ($topicModel->update()) {
                        $topicModel->saveTagsForTopic($draft_id, $tags);
                        header('Location: create.php?draft=' . $draft_id);
                        exit;
                    }
                } else {
                    // Create new draft
                    $topicModel->title = $title;
                    $topicModel->description = $description;
                    $topicModel->category_id = $category_id ?: null;
                    $topicModel->author_id = $currentUserId;
                    $topicModel->status = 'draft';

                    if ($topicModel->create()) {
                        $topicModel->saveTagsForTopic($topicModel->id, $tags);
                        $postModel->topic_id = $topicModel->id;
                        $postModel->author_id = $currentUserId;
                        $postModel->content = $description;
                        $postModel->status = 'draft';
                        if ($postModel->create()) {
                            // Проверяем на запрещённые слова
                            $autoModerator->checkPostOnCreate($postModel->id, $description, $currentUserId);
                            header('Location: create.php?draft=' . $topicModel->id);
                            exit;
                        }
                    }
                }
                $errors[] = 'Не удалось сохранить черновик.';
            }
        } elseif ($action === 'preview') {
            // Just show preview, don't save
        } elseif ($action === 'publish') {
            // Publish logic
            if ($title === '') {
                $errors[] = 'Заголовок темы не может быть пустым.';
            } elseif (mb_strlen($title) > 255) {
                $errors[] = 'Заголовок не должен превышать 255 символов.';
            } elseif (mb_strlen($description) < 10) {
                $errors[] = 'Текст темы должен содержать минимум 10 символов.';
            } elseif (mb_strlen($description) > 10000) {
                $errors[] = 'Текст темы не должен превышать 10000 символов.';
            }
            if ($category_id !== null && $category_id !== '' && !ctype_digit((string)$category_id)) {
                $errors[] = 'Выберите корректную категорию.';
            }

            if (empty($errors)) {
                if ($draft_id) {
                    // Update existing draft topic
                    $topicModel->id = $draft_id;
                    $topicModel->title = $title;
                    $topicModel->description = $description;
                    $topicModel->category_id = $category_id ?: null;
                    $topicModel->status = 'open';
                    if ($topicModel->update()) {
                        $topicModel->saveTagsForTopic($draft_id, $tags);
                        // Update post status
                        $posts = $postModel->getByTopicId($draft_id)->fetchAll(PDO::FETCH_ASSOC);
                        if (!empty($posts)) {
                            $postModel->id = $posts[0]['id'];
                            $postModel->status = 'published';
                            $postModel->update();
                        }
                        header('Location: topic.php?id=' . $draft_id);
                        exit;
                    }
                } else {
                    // Create new topic
                    $topicModel->title = $title;
                    $topicModel->description = $description;
                    $topicModel->category_id = $category_id ?: null;
                    $topicModel->author_id = $currentUserId;
                    $topicModel->status = 'open';

                    if ($topicModel->create()) {
                        $topicModel->saveTagsForTopic($topicModel->id, $tags);
                        $postModel->topic_id = $topicModel->id;
                        $postModel->author_id = $currentUserId;
                        $postModel->content = $description;
                        $postModel->status = 'published';
                        if ($postModel->create()) {
                            // Проверяем на запрещённые слова
                            $autoModerator->checkPostOnCreate($postModel->id, $description, $currentUserId);
                            header('Location: topic.php?id=' . $topicModel->id);
                            exit;
                        }
                        $errors[] = 'Тема создана, но не удалось добавить первое сообщение.';
                    } else {
                        $errors[] = 'Не удалось создать тему. Попробуйте ещё раз.';
                    }
                }
            }
        }
    }
}
?>

<?php
// Переменные для навигации
$username = htmlspecialchars($_SESSION['username'] ?? 'Гость');
$is_guest = !isset($_SESSION['user_id']) || $_SESSION['user_id'] == 0;
$userRole = $_SESSION['user_role'] ?? 'user';
$currentUserAvatar = '';

// Получаем количество непрочитанных уведомлений
$unread_count = 0;
if (!$is_guest && isset($_SESSION['user_id'])) {
    $currentUserData = $userModel->getById($_SESSION['user_id']);
    $currentUserProfile = $userModel->getProfile($_SESSION['user_id']);

    // Ensure profile exists
    if (!$currentUserProfile) {
        $userModel->updateProfile($_SESSION['user_id'], null, null, null, null);
        $currentUserProfile = $userModel->getProfile($_SESSION['user_id']);
    }

    $currentUserAvatar = $currentUserProfile['avatar_url'] ?? $currentUserData['avatar_url'] ?? '';
    $unread_count = $notificationManager->getUnreadCount($_SESSION['user_id']);
}
?>

    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Создать тему — ForumChat</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
        <style>
            body { background-color: #f0f2f5; }
            .navbar-brand-icon { width: 36px; height: 36px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; }
            .navbar { box-shadow: 0 1px 8px rgba(0,0,0,.06); }
            .avatar-sm { width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #8b5cf6); display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: .75rem; font-weight: 700; flex-shrink: 0; }
            .page-card { border-radius: 18px; box-shadow: 0 10px 30px rgba(0,0,0,.08); border: none; }
        </style>
    </head>
    <body>
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
                    <li class="nav-item"><a class="nav-link" href="../index.php"><i class="bi bi-house me-1"></i>Главная</a></li>
                    <?php if (!$is_guest) : ?>
                    <li class="nav-item">
                        <a class="nav-link" href="../notifications.php">
                            <i class="bi bi-bell me-1"></i>Сообщения
                            <?php if ($unread_count > 0) : ?>
                                <span class="badge bg-danger ms-1"><?php echo $unread_count; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item"><a class="nav-link" href="../home/profile.php"><i class="bi bi-person me-1"></i>Профиль</a></li>
                    <li class="nav-item"><a class="nav-link active fw-semibold" href="create.php"><i class="bi bi-plus-circle me-1"></i>Создать тему</a></li>
                        <?php if (in_array($userRole, ['admin', 'moderator'])) : ?>
                    <li class="nav-item">
                        <a class="nav-link text-primary fw-semibold" href="admin_panel.php">
                            <i class="bi bi-shield-check me-1"></i>Модерация
                        </a>
                    </li>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>
                <div class="d-flex align-items-center gap-2">
                    <?php if (!$is_guest && $currentUserAvatar) : ?>
                        <img src="<?= htmlspecialchars($currentUserAvatar) ?>?t=<?= time() ?>" alt="<?= $username ?>" class="avatar-sm" style="object-fit:cover;">
                    <?php else : ?>
                        <div class="avatar-sm"><?= mb_strtoupper(mb_substr($username, 0, 1)) ?></div>
                    <?php endif; ?>
                    <span class="fw-semibold small"><?= $username ?></span>
                    <?php if ($is_guest) : ?>
                        <span class="badge bg-secondary">Гость</span>
                        <a href="../auth/login.php" class="btn btn-sm btn-outline-primary rounded-3">
                            <i class="bi bi-box-arrow-in-right me-1"></i>Войти
                        </a>
                    <?php else :
                        $roleLabel = 'Участник';
                        $badgeClass = 'bg-success bg-opacity-10 text-success';
                        if ($userRole === 'moderator') {
                            $roleLabel = 'Модератор';
                            $badgeClass = 'bg-warning bg-opacity-10 text-warning';
                        } elseif ($userRole === 'admin') {
                            $roleLabel = 'Админ';
                            $badgeClass = 'bg-primary bg-opacity-10 text-primary';
                        }
                        ?>
                        <span class="badge <?= $badgeClass ?>"><?= $roleLabel ?></span>
                        <a href="../auth/logout.php" class="btn btn-sm btn-outline-danger rounded-3">
                            <i class="bi bi-box-arrow-right me-1"></i>Выйти
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card page-card p-4">
                    <h3 class="mb-3">Создать тему</h3>
                    <p class="text-muted mb-4">Введите название новой темы форума.</p>
                    <?php if (!empty($errors)) : ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error) : ?>
                            <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <?php if ($userBanned && $banInfo) : ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Ваш аккаунт заблокирован!</strong>
                        <?php if ($banInfo['permanent']) : ?>
                            Вы не можете создавать новый контент.
                        <?php else : ?>
                            Вы не можете создавать новый контент до <?php echo date('d.m.Y H:i', strtotime($banInfo['ban_until'])); ?>.
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <form method="post" id="createForm">
                        <input type="hidden" name="action" id="formAction" value="publish">
                        <input type="hidden" name="draft_id" value="<?= htmlspecialchars($draft_id) ?>">
                        <div class="mb-3">
                            <label class="form-label">Заголовок темы</label>
                            <input type="text" name="title" id="topicTitle" class="form-control" value="<?= htmlspecialchars($title) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Категория</label>
                            <select name="category_id" id="topicCategory" class="form-select">
                                <option value="">Без категории</option>
                                <?php foreach ($categories as $category) : ?>
                                <option value="<?= $category['id'] ?>" <?= $category_id == $category['id'] ? 'selected' : '' ?>><?= htmlspecialchars($category['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Теги темы <span class="text-muted small">через запятую</span></label>
                            <input type="text" name="tags" id="topicTags" class="form-control" value="<?= htmlspecialchars($tags_input) ?>" placeholder="Пример: php, mysql, frontend">
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Текст темы</label>
                            <div class="btn-group btn-group-sm mb-2" role="group" style="display:flex;flex-wrap:wrap;">
                                <button type="button" class="btn btn-outline-secondary" onclick="insertMarkdown('topic-description', '**', '**')"><strong>Ж</strong></button>
                                <button type="button" class="btn btn-outline-secondary" onclick="insertMarkdown('topic-description', '*', '*')"><em>К</em></button>
                                <button type="button" class="btn btn-outline-secondary" onclick="insertMarkdown('topic-description', '`', '`')">Code</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="insertMarkdown('topic-description', '[', '](url)')">Link</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="insertMarkdown('topic-description', '> ', '')">Quote</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="insertMarkdown('topic-description', '- ', '')">List</button>
                            </div>
                            <textarea id="topic-description" name="description" class="form-control" rows="6" required><?= htmlspecialchars($description) ?></textarea>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-primary" onclick="showPreview()">Предпросмотр</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="saveDraft()">Сохранить черновик</button>
                            <button type="submit" class="btn btn-primary" onclick="setAction('publish')">Опубликовать</button>
                        </div>
                    </form>

                    <!-- Preview Modal -->
                    <div class="modal fade" id="previewModal" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Предпросмотр темы</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <h3 id="previewTitle"></h3>
                                    <div id="previewContent"></div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                                    <button type="button" class="btn btn-primary" onclick="setAction('publish'); document.getElementById('createForm').submit();">Опубликовать</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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

    function setAction(action) {
        document.getElementById('formAction').value = action;
    }

    function showPreview() {
        const title = document.getElementById('topicTitle').value;
        const description = document.getElementById('topic-description').value;
        
        document.getElementById('previewTitle').innerHTML = title ? '<h1>' + title + '</h1>' : '<h1>Без заголовка</h1>';
        // Simple markdown-like parsing for preview
        let parsedDesc = description.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        parsedDesc = parsedDesc.replace(/\*(.*?)\*/g, '<em>$1</em>');
        parsedDesc = parsedDesc.replace(/`(.*?)`/g, '<code>$1</code>');
        parsedDesc = parsedDesc.replace(/\n/g, '<br>');
        document.getElementById('previewContent').innerHTML = parsedDesc || 'Нет содержимого';
        
        const modal = new bootstrap.Modal(document.getElementById('previewModal'));
        modal.show();
    }

    function saveDraft() {
        setAction('save_draft');
        document.getElementById('createForm').submit();
    }

    // Autosave every 30 seconds
    let autosaveTimer;
    function startAutosave() {
        autosaveTimer = setInterval(() => {
            const title = document.getElementById('topicTitle').value;
            const description = document.getElementById('topic-description').value;
            
            if (title || description) {
                // Save to localStorage as temporary draft
                const draft = {
                    title: title,
                    description: description,
                    category: document.getElementById('topicCategory').value,
                    timestamp: Date.now()
                };
                localStorage.setItem('forumDraft', JSON.stringify(draft));
                console.log('Черновик сохранен в localStorage');
            }
        }, 30000);
    }

    function loadAutosave() {
        const draft = localStorage.getItem('forumDraft');
        if (draft) {
            const data = JSON.parse(draft);
            // Only load if recent (within 24 hours)
            if (Date.now() - data.timestamp < 24 * 60 * 60 * 1000) {
                document.getElementById('topicTitle').value = data.title || '';
                document.getElementById('topic-description').value = data.description || '';
                document.getElementById('topicCategory').value = data.category || '';
                console.log('Черновик загружен из localStorage');
            }
        }
    }

    // Prevent double submission
    let isSubmitting = false;
    document.getElementById('createForm').addEventListener('submit', function(e) {
        if (isSubmitting) {
            e.preventDefault();
            return false;
        }
        isSubmitting = true;
        // Clear autosave on successful submission
        localStorage.removeItem('forumDraft');
    });

    // Start autosave when page loads
    document.addEventListener('DOMContentLoaded', function() {
        loadAutosave();
        startAutosave();
    });
    </script>
</body>
</html>