<?php

namespace ForumChat;

class ForbiddenWords
{
    private $conn;
    private $table = 'forbidden_words';

    public function __construct($db)
    {
        $this->conn = $db;
    }

    /**
     * Добавить запрещённое слово
     */
    public function addWord($word, $created_by)
    {
        // Нормализуем слово
        $word = strtolower(trim($word));

        // Проверяем что слово не пусто
        if (empty($word)) {
            return false;
        }

        $sql = "INSERT INTO {$this->table} (word, created_by) 
                VALUES (:word, :created_by)
                ON DUPLICATE KEY UPDATE updated_at = NOW()";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':word', $word);
        $stmt->bindParam(':created_by', $created_by);

        return $stmt->execute();
    }

    /**
     * Удалить запрещённое слово
     */
    public function removeWord($word_id)
    {
        $sql = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $word_id);

        return $stmt->execute();
    }

    /**
     * Получить все запрещённые слова
     */
    public function getAllWords($limit = 100, $offset = 0)
    {
        $sql = "SELECT fw.*, u.username as created_by_name 
                FROM {$this->table} fw
                LEFT JOIN users u ON u.id = fw.created_by
                ORDER BY fw.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получить количество запрещённых слов
     */
    public function getTotalCount()
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table}";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] ?? 0;
    }

    /**
     * Проверить наличие запрещённых слов в тексте
     */
    public function checkContent($content)
    {
        // Получаем все запрещённые слова
        $words = $this->getCachedWords();

        if (empty($words)) {
            return [];
        }

        // Конвертируем текст в нижний регистр для проверки
        $contentLower = strtolower($content);

        // Исправленные структуры для поиска
        $foundWords = [];

        foreach ($words as $word_id => $word) {
            // Более продвинутая проверка с границами слов
            $pattern = '/\b' . preg_quote($word, '/') . '\b/iu';

            if (preg_match_all($pattern, $content, $matches)) {
                if (!isset($foundWords[$word_id])) {
                    $foundWords[$word_id] = [
                        'word' => $word,
                        'count' => 0
                    ];
                }
                $foundWords[$word_id]['count'] += count($matches[0]);
            }
        }

        return $foundWords;
    }

    /**
     * Получить кэшированный список слов
     */
    private function getCachedWords()
    {
        // Кэш в памяти сессии
        if (!isset($_SESSION['forbidden_words_cache'])) {
            $sql = "SELECT id, word FROM {$this->table}";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();

            $words = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $words[$row['id']] = $row['word'];
            }

            $_SESSION['forbidden_words_cache'] = $words;
        }

        return $_SESSION['forbidden_words_cache'];
    }

    /**
     * Очистить кэш запрещённых слов
     */
    public function clearCache()
    {
        if (isset($_SESSION['forbidden_words_cache'])) {
            unset($_SESSION['forbidden_words_cache']);
        }
    }

    /**
     * Проверить содержимое и получить детали
     */
    public function checkContentDetailed($content)
    {
        $foundWords = $this->checkContent($content);

        return [
            'has_forbidden' => !empty($foundWords),
            'words_found' => $foundWords,
            'violation_count' => count($foundWords),
            'total_occurrences' => array_sum(array_column($foundWords, 'count'))
        ];
    }

    /**
     * Получить слово по ID
     */
    public function getWord($word_id)
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $word_id);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Поиск слова в базе
     */
    public function searchWords($searchTerm, $limit = 20)
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE word LIKE :search
                ORDER BY word ASC
                LIMIT :limit";

        $searchTerm = '%' . strtolower($searchTerm) . '%';

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':search', $searchTerm);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получить статистику
     */
    public function getStatistics()
    {
        $sql = "SELECT 
                    COUNT(*) as total_words,
                    COUNT(DISTINCT created_by) as added_by_count,
                    MIN(created_at) as first_added,
                    MAX(created_at) as last_added
                FROM {$this->table}";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Импортировать список слов (из файла или массива)
     */
    public function importWords($words_array, $created_by)
    {
        $imported = 0;
        $failed = 0;

        foreach ($words_array as $word) {
            $word = trim($word);
            if (!empty($word)) {
                if ($this->addWord($word, $created_by)) {
                    $imported++;
                } else {
                    $failed++;
                }
            }
        }

        // Очищаем кэш после импорта
        $this->clearCache();

        return [
            'imported' => $imported,
            'failed' => $failed
        ];
    }
}
