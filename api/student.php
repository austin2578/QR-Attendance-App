<?php
// api/student.php


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

$action = $_GET['action'] ?? '';

// ---------- MY CLASSES ----------
if ($action === 'my_classes') {
    try {
        $student_id = req_int('student_id');
        if ($student_id <= 0) {
            json_err('Missing or invalid student_id', 422);
        }

        // Make sure user is a student (soft check)
        $chk = $pdo->prepare("SELECT role FROM app_user WHERE user_id = ?");
        $chk->execute([$student_id]);
        $role = $chk->fetchColumn();
        if ($role !== 'student') {
            json_err('Not a student account', 403);
        }

        // Classes where this student is on the roster,
        // plus counts of sessions and their present count.
        $sql = "
            SELECT
              c.class_id,
              c.class_name,
              t.name AS teacher_name,
              COUNT(DISTINCT s.session_id) AS total_sessions,
              COUNT(a.attendance_id)       AS present_count
            FROM roster r
            JOIN class c
              ON c.class_id = r.class_id
            JOIN app_user t
              ON t.user_id = c.teacher_id
            LEFT JOIN class_session s
              ON s.class_id = c.class_id
            LEFT JOIN attendance a
              ON a.session_id = s.session_id
             AND a.student_id = r.student_id
            WHERE r.student_id = :student_id
            GROUP BY c.class_id, c.class_name, t.name
            ORDER BY c.class_name ASC
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':student_id' => $student_id]);
        $rows = $st->fetchAll() ?: [];

        // Normalize nulls to ints
        foreach ($rows as &$row) {
            $row['total_sessions'] = (int)($row['total_sessions'] ?? 0);
            $row['present_count']  = (int)($row['present_count'] ?? 0);
        }

        json_ok(['classes' => $rows]);
    } // my_classes
catch (Throwable $e) {
    log_system_error(
        $pdo,
        'api/student.php:my_classes',
        'Failed to load student classes',
        $e->getMessage()
    );
    json_err('Failed to load classes', 500, ['detail' => $e->getMessage()]);
}
}

// ---------- ATTENDANCE BY CLASS ----------
if ($action === 'attendance') {
    try {
        $student_id = req_int('student_id');
        $class_id   = req_int('class_id');
        $date_from  = req_str('date_from');
        $date_to    = req_str('date_to');

        if ($student_id <= 0 || $class_id <= 0) {
            json_err('Missing student_id or class_id', 422);
        }

        // Check student exists and is student
        $chk = $pdo->prepare("SELECT role FROM app_user WHERE user_id = ?");
        $chk->execute([$student_id]);
        $role = $chk->fetchColumn();
        if ($role !== 'student') {
            json_err('Not a student account', 403);
        }

        // Ensure student is enrolled in this class
        $enr = $pdo->prepare("SELECT 1 FROM roster WHERE class_id = ? AND student_id = ?");
        $enr->execute([$class_id, $student_id]);
        if (!$enr->fetchColumn()) {
            json_err('Student is not enrolled in this class', 403);
        }

        // Fetch all sessions for this class + derived status for this student
        $where = " WHERE s.class_id = :class_id ";
        $params = [
            ':class_id'   => $class_id,
            ':student_id' => $student_id
        ];

        if ($date_from) {
            $where .= " AND s.session_date >= :from ";
            $params[':from'] = $date_from;
        }
        if ($date_to) {
            $where .= " AND s.session_date <= :to ";
            $params[':to'] = $date_to;
        }

        $sql = "
            SELECT
              s.session_id,
              s.session_date,
              s.description,
              CASE WHEN a.attendance_id IS NULL THEN 'absent' ELSE 'present' END AS status
            FROM class_session s
            LEFT JOIN attendance a
              ON a.session_id = s.session_id
             AND a.student_id = :student_id
            $where
            ORDER BY s.session_date ASC, s.session_id ASC
        ";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll() ?: [];

        $total = count($rows);
        $present = 0;
        foreach ($rows as $r) {
            if ($r['status'] === 'present') {
                $present++;
            }
        }
        $absent = max(0, $total - $present);

        json_ok([
            'sessions' => $rows,
            'summary'  => [
                'total_sessions' => $total,
                'present'        => $present,
                'absent'         => $absent,
            ]
        ]);
    } // attendance
catch (Throwable $e) {
    log_system_error(
        $pdo,
        'api/student.php:attendance',
        'Failed to load student attendance',
        $e->getMessage()
    );
    json_err('Failed to load attendance', 500, ['detail' => $e->getMessage()]);
}
}

// ---------- RECENT CHECK-INS ----------
if ($action === 'recent') {
    try {
        $student_id = req_int('student_id');
        if ($student_id <= 0) {
            json_err('Missing or invalid student_id', 422);
        }

        // Soft role check
        $chk = $pdo->prepare("SELECT role FROM app_user WHERE user_id = ?");
        $chk->execute([$student_id]);
        $role = $chk->fetchColumn();
        if ($role !== 'student') {
            json_err('Not a student account', 403);
        }

        $sql = "
            SELECT
              a.session_id,
              a.timestamp AS checked_at,
              s.description AS session_description,
              s.session_date,
              c.class_name
            FROM attendance a
            JOIN class_session s
              ON s.session_id = a.session_id
            JOIN class c
              ON c.class_id = s.class_id
            WHERE a.student_id = ?
            ORDER BY a.timestamp DESC
            LIMIT 10
        ";

        $st = $pdo->prepare($sql);
        $st->execute([$student_id]);
        $rows = $st->fetchAll() ?: [];

        json_ok(['checkins' => $rows]);
    } // attendance
catch (Throwable $e) {
    log_system_error(
        $pdo,
        'api/student.php:attendance',
        'Failed to load student attendance',
        $e->getMessage()
    );
    json_err('Failed to load attendance', 500, ['detail' => $e->getMessage()]);
}
}

json_err('Unknown action', 400);
