<?php
// api/export.php

declare(strict_types=1);

require_once __DIR__ . "/../backend/database.php"; // $pdo
require_once __DIR__ . "/../backend/logging.php";

/**
 * Sends CSV headers and prints CSV rows.
 *
 * @param string $filename
 * @param array $headers  List of column names
 * @param iterable $rows  Each row is an array of column values
 */
function send_csv(string $filename, array $headers, iterable $rows): void {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    // BOM for Excel UTF-8 friendliness (optional)
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

function req_int(string $key, int $default = 0): int {
    return isset($_GET[$key]) ? (int)$_GET[$key] : $default;
}
function req_str(string $key, ?string $default = null): ?string {
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

/**
 * Ensure the class belongs to the given teacher.
 */
function ensure_class_owner(PDO $pdo, int $class_id, int $teacher_id): void {
    $st = $pdo->prepare("SELECT 1 FROM class WHERE class_id = ? AND teacher_id = ?");
    $st->execute([$class_id, $teacher_id]);
    if (!$st->fetchColumn()) {
        http_response_code(403);
        echo "Forbidden: class not found for this teacher.";
        exit;
    }
}

$action = $_GET['action'] ?? '';

// ========== TEACHER: SESSION CSV ==========
if ($action === 'session_csv') {
    try {
        $teacher_id = req_int('teacher_id');
        $class_id   = req_int('class_id');
        $session_id = req_int('session_id');

        if ($teacher_id <= 0 || $class_id <= 0 || $session_id <= 0) {
            http_response_code(422);
            echo "Missing teacher_id, class_id, or session_id.";
            exit;
        }

        ensure_class_owner($pdo, $class_id, $teacher_id);

        // Confirm session belongs to class
        $chk = $pdo->prepare("SELECT description, session_date FROM class_session WHERE session_id = ? AND class_id = ?");
        $chk->execute([$session_id, $class_id]);
        $session = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            http_response_code(404);
            echo "Session not found for this class.";
            exit;
        }

        // Fetch roster + attendance for that session
        $sql = "
          SELECT
            u.name  AS student_name,
            u.email AS student_email,
            CASE WHEN a.attendance_id IS NULL THEN 'absent' ELSE a.status END AS status,
            a.timestamp AS checked_at
          FROM roster r
          JOIN app_user u
            ON u.user_id = r.student_id
          LEFT JOIN attendance a
            ON a.session_id = :session_id AND a.student_id = r.student_id
          WHERE r.class_id = :class_id
          ORDER BY u.name ASC
        ";

        $st = $pdo->prepare($sql);
        $st->execute([
            ':session_id' => $session_id,
            ':class_id'   => $class_id
        ]);

        $rows = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [
                $row['student_name'],
                $row['student_email'],
                $row['status'],
                $row['checked_at'] ?? ''
            ];
        }

        $filename = sprintf(
            "session_%d_class_%d_%s.csv",
            $session_id,
            $class_id,
            date('Ymd_His')
        );

        send_csv($filename, ['Student Name', 'Email', 'Status', 'Checked At'], $rows);
    } catch (Throwable $e) {
        log_system_error($pdo, 'api/export.php:session_csv', 'Failed to generate session CSV', $e->getMessage());
        if (ob_get_length()) { ob_clean(); }
        http_response_code(500);
        echo "Error generating session CSV.";
        exit;
    }
}

// ========== TEACHER: CLASS HISTORY CSV ==========
if ($action === 'class_csv') {
    try {
        $teacher_id     = req_int('teacher_id');
        $class_id       = req_int('class_id');
        $include_absent = req_int('include_absent', 0) === 1;
        $date_from      = req_str('date_from');
        $date_to        = req_str('date_to');

        if ($teacher_id <= 0 || $class_id <= 0) {
            http_response_code(422);
            echo "Missing teacher_id or class_id.";
            exit;
        }

        ensure_class_owner($pdo, $class_id, $teacher_id);

        $where = " WHERE s.class_id = :class_id ";
        $params = [':class_id' => $class_id];

        if ($date_from) {
            $where .= " AND s.session_date >= :from ";
            $params[':from'] = $date_from;
        }
        if ($date_to) {
            $where .= " AND s.session_date <= :to ";
            $params[':to'] = $date_to;
        }

        // If include_absent: cross join roster × sessions; else only present rows
        if ($include_absent) {
            $sql = "
              SELECT
                s.session_date,
                s.description,
                u.name  AS student_name,
                u.email AS student_email,
                CASE WHEN a.attendance_id IS NULL THEN 'absent' ELSE a.status END AS status,
                a.timestamp AS checked_at
              FROM class_session s
              JOIN roster r
                ON r.class_id = s.class_id
              JOIN app_user u
                ON u.user_id = r.student_id
              LEFT JOIN attendance a
                ON a.session_id = s.session_id AND a.student_id = r.student_id
              $where
              ORDER BY s.session_date ASC, u.name ASC
            ";
        } else {
            $sql = "
              SELECT
                s.session_date,
                s.description,
                u.name  AS student_name,
                u.email AS student_email,
                a.status AS status,
                a.timestamp AS checked_at
              FROM class_session s
              JOIN attendance a
                ON a.session_id = s.session_id
              JOIN app_user u
                ON u.user_id = a.student_id
              $where
              ORDER BY s.session_date ASC, u.name ASC
            ";
        }

        $st = $pdo->prepare($sql);
        $st->execute($params);

        $rows = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [
                $row['session_date'],
                $row['description'],
                $row['student_name'],
                $row['student_email'],
                $row['status'],
                $row['checked_at'] ?? ''
            ];
        }

        $filename = sprintf(
            "class_%d_history_%s.csv",
            $class_id,
            date('Ymd_His')
        );

        send_csv(
            $filename,
            ['Session Date', 'Description', 'Student Name', 'Email', 'Status', 'Checked At'],
            $rows
        );
    } catch (Throwable $e) {
        log_system_error($pdo, 'api/export.php:class_csv', 'Failed to generate class history CSV', $e->getMessage());
        if (ob_get_length()) { ob_clean(); }
        http_response_code(500);
        echo "Error generating class history CSV.";
        exit;
    }
}

// ========== STUDENT: MY ATTENDANCE CSV ==========
if ($action === 'student_csv') {
    try {
        $student_id = req_int('student_id');
        $class_id   = req_int('class_id');
        $date_from  = req_str('date_from');
        $date_to    = req_str('date_to');

        if ($student_id <= 0 || $class_id <= 0) {
            http_response_code(422);
            echo "Missing student_id or class_id.";
            exit;
        }

        // Check that this user is a student
        $chk = $pdo->prepare("SELECT role, name FROM app_user WHERE user_id = ?");
        $chk->execute([$student_id]);
        $rowUser = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$rowUser || $rowUser['role'] !== 'student') {
            http_response_code(403);
            echo "Not a student account.";
            exit;
        }
        $studentName = $rowUser['name'];

        // Ensure student is enrolled in this class
        $enr = $pdo->prepare("SELECT 1 FROM roster WHERE class_id = ? AND student_id = ?");
        $enr->execute([$class_id, $student_id]);
        if (!$enr->fetchColumn()) {
            http_response_code(403);
            echo "Student is not enrolled in this class.";
            exit;
        }

        // Grab class name (nice for filename)
        $cl = $pdo->prepare("SELECT class_name FROM class WHERE class_id = ?");
        $cl->execute([$class_id]);
        $className = $cl->fetchColumn() ?: ('class_' . $class_id);

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
            s.session_date,
            s.description,
            CASE WHEN a.attendance_id IS NULL THEN 'absent' ELSE a.status END AS status,
            a.timestamp AS checked_at
          FROM class_session s
          LEFT JOIN attendance a
            ON a.session_id = s.session_id AND a.student_id = :student_id
          $where
          ORDER BY s.session_date ASC, s.session_id ASC
        ";

        $st = $pdo->prepare($sql);
        $st->execute($params);

        $rows = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [
                $row['session_date'],
                $row['description'],
                $row['status'],
                $row['checked_at'] ?? ''
            ];
        }

        $safeClass   = preg_replace('/[^A-Za-z0-9_-]+/', '_', $className);
        $safeStudent = preg_replace('/[^A-Za-z0-9_-]+/', '_', $studentName);

        $filename = sprintf(
            "student_%d_%s_%s_%s.csv",
            $student_id,
            $safeStudent,
            $safeClass,
            date('Ymd_His')
        );

        send_csv(
            $filename,
            ['Session Date', 'Description', 'Status', 'Checked At'],
            $rows
        );
    } catch (Throwable $e) {
        log_system_error($pdo, 'api/export.php:student_csv', 'Failed to generate student CSV', $e->getMessage());
        if (ob_get_length()) { ob_clean(); }
        http_response_code(500);
        echo "Error generating student CSV.";
        exit;
    }
}

// Unknown / unsupported action
http_response_code(400);
echo "Unknown or missing action.";
exit;
