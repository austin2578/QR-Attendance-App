<?php
// api/attendance.php


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

// ---------- LIST ATTENDANCE FOR SESSION ----------
if ($action === 'list') {
  try {
    $teacher_id = (int)($_GET['teacher_id'] ?? 0);
    $class_id   = (int)($_GET['class_id'] ?? 0);
    $session_id = (int)($_GET['session_id'] ?? 0);
    if ($teacher_id <= 0 || $class_id <= 0 || $session_id <= 0) {
      json_err('Missing teacher_id, class_id, or session_id', 422);
    }

    ensure_class_owner($pdo, $class_id, $teacher_id);
    $s = $pdo->prepare("SELECT 1 FROM class_session WHERE session_id = ? AND class_id = ?");
    $s->execute([$session_id, $class_id]);
    if (!$s->fetchColumn()) json_err('Session not found for this class', 404);

    $q = $pdo->prepare("
      SELECT
        u.user_id      AS student_id,
        u.name         AS student_name,
        u.email        AS student_email,
        a.attendance_id,
        CASE WHEN a.attendance_id IS NULL THEN 'absent' ELSE 'present' END AS status,
        a.timestamp    AS checked_at
      FROM roster r
      JOIN app_user u
        ON u.user_id = r.student_id
      LEFT JOIN attendance a
        ON a.session_id = ? AND a.student_id = r.student_id
      WHERE r.class_id = ?
      ORDER BY u.name ASC
    ");
    $q->execute([$session_id, $class_id]);
    $rows = $q->fetchAll() ?: [];

    json_ok(['records' => $rows]);
  } // list attendance
catch (Throwable $e) {
    log_system_error(
        $pdo,
        'api/attendance.php:list',
        'Failed to load attendance',
        $e->getMessage()
    );
    json_err('Failed to load attendance', 500, ['detail' => $e->getMessage()]);
}
}

// ---------- OVERRIDE ATTENDANCE (within 24h) ----------
if ($action === 'override') {
  try {
    $data       = read_json();
    $teacher_id = (int)($data['teacher_id'] ?? 0);
    $class_id   = (int)($data['class_id'] ?? 0);
    $session_id = (int)($data['session_id'] ?? 0);
    $student_id = (int)($data['student_id'] ?? 0);
    $new_status = strtolower(trim((string)($data['status'] ?? '')));

    if ($teacher_id <= 0 || $class_id <= 0 || $session_id <= 0 || $student_id <= 0 || $new_status === '') {
      json_err('Missing required fields', 422);
    }
    if (!in_array($new_status, ['present', 'absent'], true)) {
      json_err('Invalid status; only present/absent allowed', 422);
    }

    ensure_class_owner($pdo, $class_id, $teacher_id);

    // Check session belongs to class and is within 24 hours
    $s = $pdo->prepare("SELECT session_date FROM class_session WHERE session_id = ? AND class_id = ?");
    $s->execute([$session_id, $class_id]);
    $session = $s->fetch();
    if (!$session) json_err('Session not found for this class', 404);

    $session_ts = $session['session_date'] ? strtotime($session['session_date']) : null;
    if ($session_ts && (time() - $session_ts) > 24 * 3600) {
      json_err('Edit window closed (older than 24 hours)', 409);
    }

    // Determine old status from attendance table
    $chk = $pdo->prepare("SELECT attendance_id FROM attendance WHERE session_id = ? AND student_id = ?");
    $chk->execute([$session_id, $student_id]);
    $attendance_id = $chk->fetchColumn();
    $old_status = $attendance_id ? 'present' : 'absent';

    if ($old_status === $new_status) {
      json_ok(['message' => 'No change', 'status' => $new_status]);
    }

    $pdo->beginTransaction();

    // Apply change: we keep "present" as having a row, "absent" as no row
    if ($new_status === 'present' && $old_status === 'absent') {
      $ins = $pdo->prepare("INSERT INTO attendance (session_id, student_id, status) VALUES (?, ?, 'present')");
      $ins->execute([$session_id, $student_id]);
    } elseif ($new_status === 'absent' && $old_status === 'present') {
      $del = $pdo->prepare("DELETE FROM attendance WHERE session_id = ? AND student_id = ?");
      $del->execute([$session_id, $student_id]);
    }

    // Audit log
    $audit = $pdo->prepare("
      INSERT INTO attendance_audit (session_id, student_id, teacher_id, old_status, new_status, note)
      VALUES (?, ?, ?, ?, ?, ?)
    ");
    $audit->execute([$session_id, $student_id, $teacher_id, $old_status, $new_status, null]);

    $pdo->commit();

    json_ok(['message' => 'Attendance updated', 'status' => $new_status]);
  } // override attendance
catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_system_error(
        $pdo,
        'api/attendance.php:override',
        'Failed to override attendance',
        $e->getMessage()
    );
    json_err('Failed to update attendance', 500, ['detail' => $e->getMessage()]);
}
}

json_err('Unknown action', 400);
