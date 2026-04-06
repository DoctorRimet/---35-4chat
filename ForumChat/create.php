<?php
require_once __DIR__ . '/auth/auth_check.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Topic.php';
require_once __DIR__ . '/classes/Post.php';
require_once __DIR__ . '/classes/Category.php';
require_once __DIR__ . '/functions/markdown.php';

$db = new Database();
$conn = $db->getConnection();
$topicModel = new Topic($conn);
$postModel = new Post($conn);
$categoryModel = new Category($conn);

$currentUserId = $_SESSION['user_id'] ?? null;
$errors = [];
$title = '';
$description = '';
$category_id = '';
$categoriesStmt = $categoryModel->getAll();
$categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id = $_POST['category_id'] ?? null;

    if ($title === '') {
        $errors[] = 'Заголовок темы не может быть пустым.';
    }
    if (mb_strlen($description) < 10) {
        $errors[] = 'Текст темы должен содержать минимум 10 символов.';
    }
    if ($category_id !== null && $category_id !== '' && !ctype_digit((string)$category_id)) {
        $errors[] = 'Выберите корректную категорию.';
    }

    if (empty($errors)) {
        $topicModel->title = $title;
        $topicModel->description = $description;
        $topicModel->category_id = $category_id ?: null;
        $topicModel->author_id = $currentUserId;

        if ($topicModel->create()) {
            $postModel->topic_id = $topicModel->id;
            $postModel->author_id = $currentUserId;
            $postModel->content = $description;
            if ($postModel->create()) {
                header('Location: topic.php?id=' . $topicModel->id);
                exit;
            }
            $errors[] = 'Тема создана, но не удалось добавить первое сообщение.';
        } else {
            $errors[] = 'Не удалось создать тему. Попробуйте ещё раз.';
        }
    }
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
        .page-card { border-radius: 18px; box-shadow: 0 10px 30px rgba(0,0,0,.08); border: none; }
    </style>
</head>
<body>
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
                <li class="nav-item"><a class="nav-link" href="index.php"><i class="bi bi-house me-1"></i>Главная</a></li>
                <li class="nav-item"><a class="nav-link" href="home/profile.php"><i class="bi bi-person me-1"></i>Профиль</a></li>
                <li class="nav-item"><a class="nav-link active fw-semibold" href="create.php"><i class="bi bi-plus-circle me-1"></i>Создать тему</a></li>
            </ul>
            <div class="d-flex align-items-center gap-2">
                <a href="auth/logout.php" class="btn btn-sm btn-outline-danger rounded-3">
                    <i class="bi bi-box-arrow-right me-1"></i>Выйти
                </a>
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
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Заголовок темы</label>
                        <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($title) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Категория</label>
                        <select name="category_id" class="form-select">
                            <option value="">Без категории</option>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?= $category['id'] ?>" <?= $category_id == $category['id'] ? 'selected' : '' ?>><?= htmlspecialchars($category['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
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
                    <button type="submit" class="btn btn-primary">Создать тему</button>
                </form>
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
</script>
</body>
</html>
