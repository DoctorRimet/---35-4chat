<?php
/**
 * Parse Markdown-like syntax to HTML
 * Supports: bold, italic, code, links, quotes, lists
 */
function parseMarkdown($text) {
    // Already escaped during insert, so we just format
    
    // Convert **bold** to <strong>
    $text = preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $text);
    
    // Convert *italic* to <em>
    $text = preg_replace('/\*(.+?)\*/su', '<em>$1</em>', $text);
    
    // Convert `code` to <code>
    $text = preg_replace('/`(.+?)`/su', '<code style="background:#f4f4f4;padding:2px 4px;border-radius:3px;">$1</code>', $text);
    
    // Convert [text](url) to <a href>
    $text = preg_replace('/\[(.+?)\]\((.+?)\)/su', '<a href="$2" target="_blank" rel="noopener">$1</a>', $text);
    
    // Convert > quote to blockquote
    $lines = explode("\n", $text);
    $inBlockquote = false;
    $result = [];
    
    foreach ($lines as $line) {
        if (strpos(trim($line), '>') === 0) {
            $quoteText = substr(trim($line), 1);
            if (!$inBlockquote) {
                $result[] = '<blockquote style="border-left:3px solid #ccc;margin:10px 0;padding-left:10px;color:#666;">';
                $inBlockquote = true;
            }
            $result[] = '<p>' . trim($quoteText) . '</p>';
        } else {
            if ($inBlockquote) {
                $result[] = '</blockquote>';
                $inBlockquote = false;
            }
            if (!empty(trim($line))) {
                $result[] = $line;
            }
        }
    }
    
    if ($inBlockquote) {
        $result[] = '</blockquote>';
    }
    
    $text = implode("\n", $result);
    
    // Convert - list items to <ul><li>
    $lines = explode("\n", $text);
    $inList = false;
    $result = [];
    
    foreach ($lines as $line) {
        if (preg_match('/^\s*-\s+(.+)$/', $line, $matches)) {
            if (!$inList) {
                $result[] = '<ul style="margin:10px 0;padding-left:20px;">';
                $inList = true;
            }
            $result[] = '<li>' . trim($matches[1]) . '</li>';
        } else {
            if ($inList) {
                $result[] = '</ul>';
                $inList = false;
            }
            if (!empty(trim($line))) {
                $result[] = $line;
            }
        }
    }
    
    if ($inList) {
        $result[] = '</ul>';
    }
    
    $text = implode("\n", $result);
    
    return $text;
}
?>
