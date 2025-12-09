<?php
// api/roster.php
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

/**
 * Verify the class belongs to the teacher.
 */
function ensure_class_owner(PDO $pdo, int $class_id, int $teacher_id): void {
  $st = $pdo->prepare("SELECT 1 FROM class WHERE class_id = ? AND teacher_id = ?");
  $st->execute([$class_id, $teacher_id]);
  if (!$st->fetchColumn()) json_err('Class not found for this teacher', 403);
}

$action = $_GET['action'] ?? '';

// ---------- LIST ROSTER ----------
if ($action === 'list') {
  try {
    $teacher_id = (int)($_GET['teacher_id'] ?? 0);
    $class_id   = (int)($_GET['class_id'] ?? 0);
    if ($teacher_id <= 0 || $class_id <= 0) json_err('Missing teacher_id or class_id', 422);

    ensure_class_owner($pdo, $class_id, $teacher_id);

    $stmt = $pdo->prepare("
      SELECT r.student_id, u.name, u.email, r.date_enrolled
      FROM roster r
      JOIN app_user u ON u.user_id = r.student_id
      WHERE r.class_id = ?
      ORDER BY u.name ASC
    ");
    $stmt->execute([$class_id]);
    $rows = $stmt->fetchAll() ?: [];
    json_ok(['students' => $rows]); // success even if empty
  } catch (Throwable $e) {
        log_system_error($pdo, 'api/roster.php:list', 'Failed to list roster.', $e->getMessage());
        json_err('User-facing error message', 500, ['detail' => $e->getMessage()]);
    }
}

// ---------- ADD STUDENT BY EMAIL ----------
if ($action === 'add') {
  try {
    $data = read_json();
    $teacher_id = (int)($data['teacher_id'] ?? 0);
    $class_id   = (int)($data['class_id'] ?? 0);
    $email      = trim((string)($data['email'] ?? ''));

    if ($teacher_id <= 0 || $class_id <= 0 || $email === '') {
      json_err('Missing teacher_id, class_id, or email', 422);
    }

    ensure_class_owner($pdo, $class_id, $teacher_id);

    // find student user
    $u = $pdo->prepare("SELECT user_id, role FROM app_user WHERE email = ?");
    $u->execute([$email]);
    $student = $u->fetch();
    if (!$student || $student['role'] !== 'student') {
      json_err('No student account found with that email', 404);
    }
    $student_id = (int)$student['user_id'];

    // prevent duplicates
    $chk = $pdo->prepare("SELECT 1 FROM roster WHERE class_id = ? AND student_id = ?");
    $chk->execute([$class_id, $student_id]);
    if ($chk->fetchColumn()) {
      json_ok(['message' => 'Student already in class']); // idempotent
    }

    $ins = $pdo->prepare("INSERT INTO roster (class_id, student_id) VALUES (?, ?)");
    $ins->execute([$class_id, $student_id]);

    json_ok(['message' => 'Student added', 'student_id' => $student_id], 201);
  } catch (Throwable $e) {
        log_system_error($pdo, 'api/roster.php:add', 'Failed to add roster.', $e->getMessage());
        json_err('User-facing error message', 500, ['detail' => $e->getMessage()]);
    }
}

// ---------- REMOVE STUDENT ----------
if ($action === 'remove') {
  try {
    $data = read_json();
    $teacher_id = (int)($data['teacher_id'] ?? 0);
    $class_id   = (int)($data['class_id'] ?? 0);
    $student_id = (int)($data['student_id'] ?? 0);

    if ($teacher_id <= 0 || $class_id <= 0 || $student_id <= 0) {
      json_err('Missing teacher_id, class_id, or student_id', 422);
    }

    ensure_class_owner($pdo, $class_id, $teacher_id);

    $del = $pdo->prepare("DELETE FROM roster WHERE class_id = ? AND student_id = ?");
    $del->execute([$class_id, $student_id]);

    json_ok(['message' => 'Student removed']);
  } catch (Throwable $e) {
        log_system_error($pdo, 'api/roster.php:remove', 'Failed to remove roster.', $e->getMessage());
        json_err('User-facing error message', 500, ['detail' => $e->getMessage()]);
    }
}

json_err('Unknown action', 400);
