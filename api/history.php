<?php
// api/history.php
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
function req_int(string $key, int $default = 0): int {
  return isset($_GET[$key]) ? (int)$_GET[$key] : $default;
}
function req_str(string $key, ?string $default = null): ?string {
  return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}
function ensure_class_owner(PDO $pdo, int $class_id, int $teacher_id): void {
  $st = $pdo->prepare("SELECT 1 FROM class WHERE class_id = ? AND teacher_id = ?");
  $st->execute([$class_id, $teacher_id]);
  if (!$st->fetchColumn()) json_err('Class not found for this teacher', 403);
}

$action = $_GET['action'] ?? '';

if ($action === 'sessions') {
  try {
    $teacher_id = req_int('teacher_id');
    $class_id   = req_int('class_id');
    $date_from  = req_str('date_from'); // "YYYY-MM-DD HH:MM:SS" or "YYYY-MM-DD"
    $date_to    = req_str('date_to');

    if ($teacher_id <= 0 || $class_id <= 0) {
      json_err('Missing teacher_id or class_id', 422);
    }

    ensure_class_owner($pdo, $class_id, $teacher_id);

    $where = " WHERE cs.class_id = :class_id ";
    $params = [':class_id' => $class_id];

    if ($date_from) {
      $where .= " AND cs.session_date >= :from ";
      $params[':from'] = $date_from;
    }
    if ($date_to) {
      $where .= " AND cs.session_date <= :to ";
      $params[':to'] = $date_to;
    }

    $sql = "
      SELECT
        cs.session_id,
        cs.session_date,
        cs.description,
        cs.status,
        COUNT(DISTINCT r.student_id)                      AS total_students,
        SUM(CASE WHEN a.status IS NOT NULL THEN 1 ELSE 0 END) AS present_count
      FROM class_session cs
      JOIN roster r
        ON r.class_id = cs.class_id
      LEFT JOIN attendance a
        ON a.session_id = cs.session_id
       AND a.student_id = r.student_id
      $where
      GROUP BY cs.session_id, cs.session_date, cs.description, cs.status
      ORDER BY cs.session_date DESC, cs.session_id DESC
    ";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll() ?: [];

    $sessions = [];
    $totals = [
      'total_sessions' => 0,
      'total_present'  => 0,
      'total_absent'   => 0
    ];

    foreach ($rows as $row) {
      $total   = (int)$row['total_students'];
      $present = (int)$row['present_count'];
      $absent  = max(0, $total - $present);
      $sessions[] = [
        'session_id'   => (int)$row['session_id'],
        'session_date' => $row['session_date'],
        'description'  => $row['description'],
        'status'       => $row['status'],
        'total'        => $total,
        'present'      => $present,
        'absent'       => $absent,
      ];
      $totals['total_sessions']++;
      $totals['total_present'] += $present;
      $totals['total_absent']  += $absent;
    }

    json_ok([
      'sessions' => $sessions,
      'totals'   => $totals
    ]);
  } catch (Throwable $e) {
        log_system_error($pdo, 'api/history.php:list', 'Failed to list history.', $e->getMessage());
        json_err('User-facing error message', 500, ['detail' => $e->getMessage()]);
    }
}

json_err('Unknown action', 400);
