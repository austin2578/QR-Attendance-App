<?php
// backend/utils.php

function json_ok($data = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function json_error($msg, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function require_json_input() {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    if ($data === null) json_error("Invalid or missing JSON input", 400);
    return $data;
}
?>