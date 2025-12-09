<?php
// api/checkin.php
declare(strict_types=1);
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../backend/database.php"; // provides $pdo
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

$action = $_GET['action'] ?? '';

// POST { token, student_id }
if ($action === 'submit') {
  try {
    $data = read_json();
    $token      = trim((string)($data['token'] ?? ''));
    $student_id = (int)($data['student_id'] ?? 0);
    if ($token === '' || $student_id <= 0) {
      json_err('Missing token or student_id', 422);
    }

    // Ensure user exists and is a student
    $u = $pdo->prepare("SELECT role FROM app_user WHERE user_id = ?");
    $u->execute([$student_id]);
    $user = $u->fetch();
    if (!$user || $user['role'] !== 'student') {
      json_err('Only students can check in', 403);
    }

    // Find active token → session
    $t = $pdo->prepare("
      SELECT qt.session_id, cs.class_id, cs.status
      FROM qr_token qt
      JOIN class_session cs ON cs.session_id = qt.session_id
      WHERE qt.token_value = ? AND qt.expiration_time > NOW()
      ORDER BY qt.token_id DESC
      LIMIT 1
    ");
    $t->execute([$token]);
    $tok = $t->fetch();
    if (!$tok) json_err('Invalid or expired token', 410);
    if ($tok['status'] !== 'open') json_err('Session is closed', 409);

    $session_id = (int)$tok['session_id'];
    $class_id   = (int)$tok['class_id'];

    // Verify student is on roster for this class
    $r = $pdo->prepare("SELECT 1 FROM roster WHERE class_id = ? AND student_id = ?");
    $r->execute([$class_id, $student_id]);
    if (!$r->fetchColumn()) {
      json_err('You are not enrolled in this class', 403);
    }

    // Idempotent insert: if attendance already exists, return a friendly success
    $chk = $pdo->prepare("SELECT attendance_id FROM attendance WHERE session_id = ? AND student_id = ?");
    $chk->execute([$session_id, $student_id]);
    $existing = $chk->fetchColumn();

    if ($existing) {
      json_ok([
        'message'      => 'Already checked in',
        'session_id'   => $session_id,
        'attendance_id'=> (int)$existing,
        'status'       => 'present'
      ]);
    }

    // Insert attendance (default 'present')
$ip = $_SERVER['REMOTE_ADDR']      ?? null;
$ua = $_SERVER['HTTP_USER_AGENT']  ?? null;

$ins = $pdo->prepare("
    INSERT INTO attendance (session_id, student_id, status, ip_address, user_agent)
    VALUES (?, ?, 'present', ?, ?)
");
$ins->execute([$session_id, $student_id, $ip, $ua]);

    json_ok([
      'message'       => 'Check-in recorded',
      'session_id'    => $session_id,
      'attendance_id' => (int)$pdo->lastInsertId(),
      'status'        => 'present'
    ], 201);

  } catch (Throwable $e) {
        log_system_error($pdo, 'api/checkin.php:submit', 'Failed to submit check-in.', $e->getMessage());
        json_err('User-facing error message', 500, ['detail' => $e->getMessage()]);
    }
}

json_err('Unknown action', 400);
