<?php
/**
 * Простой тест API - просто возвращаем JSON
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$response = [
    'success' => true,
    'message' => '✓ API тест работает!',
    'timestamp' => date('Y-m-d H:i:s'),
    'request_method' => $_SERVER['REQUEST_METHOD'],
    'script_name' => $_SERVER['SCRIPT_NAME'],
    'query_string' => $_SERVER['QUERY_STRING'] ?? 'нет'
];

http_response_code(200);
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;

