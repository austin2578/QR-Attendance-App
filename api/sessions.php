<?php
// api/sessions.php
 

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

// ---------- LIST ----------
if ($action === 'list') {
  try {
    $teacher_id = (int)($_GET['teacher_id'] ?? 0);
    $class_id   = (int)($_GET['class_id'] ?? 0);
    if ($teacher_id <= 0 || $class_id <= 0) {
      json_err('Missing teacher_id or class_id', 422);
    }

    ensure_class_owner($pdo, $class_id, $teacher_id);

    $stmt = $pdo->prepare("
      SELECT session_id, class_id, description, session_date, status
      FROM class_session
      WHERE class_id = ?
      ORDER BY session_date DESC, session_id DESC
    ");
    $stmt->execute([$class_id]);
    $rows = $stmt->fetchAll() ?: [];

    json_ok(['sessions' => $rows]);
  } // list 
  catch (Throwable $e) {
    log_system_error(
        $pdo,
        'api/sessions.php:list',
        'Failed to list sessions',
        $e->getMessage()
    );
    json_err('Failed to load sessions', 500, ['detail' => $e->getMessage()]); 
  }
}

// ---------- CREATE ----------
if ($action === 'create') {
  try {
    $data       = read_json();
    $teacher_id = (int)($data['teacher_id'] ?? 0);
    $class_id   = (int)($data['class_id'] ?? 0);
    $desc       = trim((string)($data['description'] ?? ''));
    $session_dt = trim((string)($data['session_date'] ?? ''));

    if ($teacher_id <= 0 || $class_id <= 0 || $session_dt === '') {
      json_err('Missing teacher_id, class_id, or session_date', 422);
    }

    ensure_class_owner($pdo, $class_id, $teacher_id);

    $stmt = $pdo->prepare("
      INSERT INTO class_session (class_id, description, session_date, status)
      VALUES (?, ?, ?, 'open')
    ");
    $stmt->execute([$class_id, $desc, $session_dt]);

    json_ok([
      'message'    => 'Session created',
      'session_id' => (int)$pdo->lastInsertId(),
    ], 201);
  } // create
catch (Throwable $e) {
    log_system_error(
        $pdo,
        'api/sessions.php:create',
        'Failed to create session',
        $e->getMessage()
    );
    json_err('Failed to create session', 500, ['detail' => $e->getMessage()]);
}
}

// ---------- STATUS (open/close) ----------
if ($action === 'status') {
  try {
    $data       = read_json();
    $teacher_id = (int)($data['teacher_id'] ?? 0);
    $class_id   = (int)($data['class_id'] ?? 0);
    $session_id = (int)($data['session_id'] ?? 0);
    $status     = strtolower(trim((string)($data['status'] ?? '')));

    if ($teacher_id <= 0 || $class_id <= 0 || $session_id <= 0 || $status === '') {
      json_err('Missing required fields', 422);
    }
    if (!in_array($status, ['open', 'closed'], true)) {
      json_err('Invalid status. Must be open or closed.', 422);
    }

    ensure_class_owner($pdo, $class_id, $teacher_id);

    $s = $pdo->prepare("SELECT 1 FROM class_session WHERE session_id = ? AND class_id = ?");
    $s->execute([$session_id, $class_id]);
    if (!$s->fetchColumn()) json_err('Session not found for this class', 404);

    $u = $pdo->prepare("UPDATE class_session SET status = ? WHERE session_id = ?");
    $u->execute([$status, $session_id]);

    json_ok(['message' => 'Status updated']);
  } // status
catch (Throwable $e) {
    log_system_error(
        $pdo,
        'api/sessions.php:status',
        'Failed to update session status',
        $e->getMessage()
    );
    json_err('Failed to update status', 500, ['detail' => $e->getMessage()]);
}
}

// ---------- DELETE ----------
if ($action === 'delete') {
  try {
    $data       = read_json();
    $teacher_id = (int)($data['teacher_id'] ?? 0);
    $class_id   = (int)($data['class_id'] ?? 0);
    $session_id = (int)($data['session_id'] ?? 0);

    if ($teacher_id <= 0 || $class_id <= 0 || $session_id <= 0) {
      json_err('Missing teacher_id, class_id, or session_id', 422);
    }

    ensure_class_owner($pdo, $class_id, $teacher_id);

    // confirm session belongs to class
    $chk = $pdo->prepare("SELECT 1 FROM class_session WHERE session_id = ? AND class_id = ?");
    $chk->execute([$session_id, $class_id]);
    if (!$chk->fetchColumn()) {
      json_err('Session not found for this class', 404);
    }

    $pdo->beginTransaction();

    // Delete children in a safe order (FK constraints)
    // 1) attendance_audit
    try {
      $pdo->prepare("DELETE FROM attendance_audit WHERE session_id = ?")
          ->execute([$session_id]);
    } // delete
catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_system_error(
        $pdo,
        'api/sessions.php:delete',
        'Failed to delete session',
        $e->getMessage()
    );
    json_err('Failed to delete session', 500, ['detail' => $e->getMessage()]);
}

    // 2) attendance
    $pdo->prepare("DELETE FROM attendance WHERE session_id = ?")
        ->execute([$session_id]);

    // 3) qr_token
    $pdo->prepare("DELETE FROM qr_token WHERE session_id = ?")
        ->execute([$session_id]);

    // 4) the session itself
    $pdo->prepare("DELETE FROM class_session WHERE session_id = ? AND class_id = ?")
        ->execute([$session_id, $class_id]);

    $pdo->commit();

    json_ok(['message' => 'Session deleted']);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    json_err('Failed to delete session', 500, ['detail' => $e->getMessage()]);
  }
}

json_err('Unknown action', 400);
