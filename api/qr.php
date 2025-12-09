<?php
// api/qr.php


declare(strict_types=1);
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../backend/database.php"; // $pdo
require_once __DIR__ . "/../backend/logging.php"; 
function json_ok(array $data = [], int $status = 200): void {
  if (ob_get_length()) { ob_clean(); }
  http_response_code($status);
  echo json_encode(['success' => true] + $data);
  exit;
}
function json_err(string $msg, int $status = 400, array $extra = []): void {
  if (ob_get_length()) { ob_clean(); }
  http_response_code($status);
  echo json_encode(['success' => false, 'error' => $msg] + $extra);
  exit;
}
function read_json(): array {
  $raw = file_get_contents('php://input') ?: '';
  $data = json_decode($raw, true);
  if (!is_array($data)) json_err('Invalid JSON', 400, ['raw' => substr($raw, 0, 200)]);
  return $data;
}
function ensure_class_owner(PDO $pdo, int $class_id, int $teacher_id): void {
  $st = $pdo->prepare("SELECT 1 FROM class WHERE class_id = ? AND teacher_id = ?");
  $st->execute([$class_id, $teacher_id]);
  if (!$st->fetchColumn()) json_err('Class not found for this teacher', 403);
}

$action = $_GET['action'] ?? '';

// ---------- CREATE QR TOKEN (one active per session) ----------
if ($action === 'create') {
  try {
    $data       = read_json();
    $teacher_id = (int)($data['teacher_id'] ?? 0);
    $class_id   = (int)($data['class_id'] ?? 0);
    $session_id = (int)($data['session_id'] ?? 0);
    $ttl_min    = (int)($data['ttl_minutes'] ?? 10);
    if ($ttl_min <= 0) $ttl_min = 10;
    if ($ttl_min > 120) $ttl_min = 120; // cap at 2 hours

    if ($teacher_id <= 0 || $class_id <= 0 || $session_id <= 0) {
      json_err('Missing teacher_id, class_id, or session_id', 422);
    }

    ensure_class_owner($pdo, $class_id, $teacher_id);

    // session must be open and belong to class
    $s = $pdo->prepare("SELECT status FROM class_session WHERE session_id = ? AND class_id = ?");
    $s->execute([$session_id, $class_id]);
    $row = $s->fetch();
    if (!$row) json_err('Session not found for this class', 404);
    if ($row['status'] !== 'open') json_err('Session is not open', 409);

    // Invalidate any previously active tokens for this session
    $upd = $pdo->prepare("UPDATE qr_token SET expiration_time = NOW() WHERE session_id = ? AND expiration_time > NOW()");
    $upd->execute([$session_id]);

    // generate token (48 hex chars)
    $token = bin2hex(random_bytes(24));
    $expires = date("Y-m-d H:i:s", time() + ($ttl_min * 60));

    $ins = $pdo->prepare("INSERT INTO qr_token (session_id, token_value, expiration_time) VALUES (?, ?, ?)");
    $ins->execute([$session_id, $token, $expires]);

    json_ok([
      'message'   => 'QR token created',
      'token_id'  => (int)$pdo->lastInsertId(),
      'token'     => $token,
      'expires'   => $expires,
      'ttl_min'   => $ttl_min,
    ], 201);
  } catch (Throwable $e) {
        log_system_error($pdo, 'api/qr.php:create', 'Failed to create QR.', $e->getMessage());
        json_err('User-facing error message', 500, ['detail' => $e->getMessage()]);
    }
}

// ---------- LATEST (convenience) ----------
if ($action === 'latest') {
  try {
    $teacher_id = (int)($_GET['teacher_id'] ?? 0);
    $class_id   = (int)($_GET['class_id'] ?? 0);
    $session_id = (int)($_GET['session_id'] ?? 0);
    if ($teacher_id <= 0 || $class_id <= 0 || $session_id <= 0) {
      json_err('Missing teacher_id, class_id, or session_id', 422);
    }

    ensure_class_owner($pdo, $class_id, $teacher_id);

    $stmt = $pdo->prepare("
      SELECT token_id, token_value AS token, expiration_time AS expires, created_at
      FROM qr_token
      WHERE session_id = ? AND expiration_time > NOW()
      ORDER BY token_id DESC
      LIMIT 1
    ");
    $stmt->execute([$session_id]);
    $tok = $stmt->fetch();
    if (!$tok) json_ok(['token' => null]);
    json_ok($tok);
  } catch (Throwable $e) {
        log_system_error($pdo, 'api/qr.php:latest', 'Failed retrieve latest token.', $e->getMessage());
        json_err('User-facing error message', 500, ['detail' => $e->getMessage()]);
    }
}

json_err('Unknown action', 400);
