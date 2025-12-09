<?php
// api/classes.php
declare(strict_types=1);
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../backend/database.php";  // $pdo
require_once __DIR__ . "/../backend/logging.php";   // log_system_error()

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
    if (!is_array($data)) {
        json_err('Invalid JSON', 400, ['raw' => substr($raw, 0, 200)]);
    }
    return $data;
}

function req_int(string $key, int $default = 0): int {
    return isset($_GET[$key]) ? (int)$_GET[$key] : $default;
}

/**
 * Ensure the class belongs to the given teacher.
 */
function ensure_class_owner(PDO $pdo, int $class_id, int $teacher_id): void {
    $st = $pdo->prepare("SELECT 1 FROM class WHERE class_id = ? AND teacher_id = ?");
    $st->execute([$class_id, $teacher_id]);
    if (!$st->fetchColumn()) {
        json_err('Class not found for this teacher', 403);
    }
}

$action = $_GET['action'] ?? '';

// ========== LIST CLASSES FOR TEACHER ==========
if ($action === 'list') {
    try {
        $teacher_id = req_int('teacher_id');
        if ($teacher_id <= 0) {
            json_err('Missing teacher_id', 422);
        }

        $sql = "
          SELECT
            c.class_id,
            c.class_name,
            COUNT(DISTINCT r.student_id)       AS student_count,
            COUNT(DISTINCT s.session_id)       AS session_count
          FROM class c
          LEFT JOIN roster r
            ON r.class_id = c.class_id
          LEFT JOIN class_session s
            ON s.class_id = c.class_id
          WHERE c.teacher_id = :teacher_id
          GROUP BY c.class_id, c.class_name
          ORDER BY c.class_name ASC
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':teacher_id' => $teacher_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        json_ok(['classes' => $rows]);
    } catch (Throwable $e) {
        log_system_error($pdo, 'api/classes.php:list', 'Failed to list classes', $e->getMessage());
        json_err('Failed to load classes', 500, ['detail' => $e->getMessage()]);
    }
}

// ========== CREATE CLASS ==========
if ($action === 'create') {
    try {
        $data       = read_json();
        $teacher_id = (int)($data['teacher_id'] ?? 0);
        $name       = trim((string)($data['class_name'] ?? ''));

        if ($teacher_id <= 0 || $name === '') {
            json_err('Missing teacher_id or class_name', 422);
        }

        // simple unique-per-teacher check (optional)
        $chk = $pdo->prepare("SELECT 1 FROM class WHERE teacher_id = ? AND class_name = ?");
        $chk->execute([$teacher_id, $name]);
        if ($chk->fetchColumn()) {
            json_err('You already have a class with this name.', 409);
        }

        $ins = $pdo->prepare("INSERT INTO class (class_name, teacher_id) VALUES (?, ?)");
        $ins->execute([$name, $teacher_id]);

        json_ok([
            'message'  => 'Class created',
            'class_id' => (int)$pdo->lastInsertId(),
        ], 201);
    } catch (Throwable $e) {
        log_system_error($pdo, 'api/classes.php:create', 'Failed to create class', $e->getMessage());
        json_err('Failed to create class', 500, ['detail' => $e->getMessage()]);
    }
}

// ========== RENAME CLASS ==========
if ($action === 'rename' || $action === 'update') {
    try {
        $data       = read_json();
        $teacher_id = (int)($data['teacher_id'] ?? 0);
        $class_id   = (int)($data['class_id'] ?? 0);
        $name       = trim((string)($data['class_name'] ?? ''));

        if ($teacher_id <= 0 || $class_id <= 0 || $name === '') {
            json_err('Missing teacher_id, class_id, or class_name', 422);
        }

        ensure_class_owner($pdo, $class_id, $teacher_id);

        $upd = $pdo->prepare("UPDATE class SET class_name = ? WHERE class_id = ? AND teacher_id = ?");
        $upd->execute([$name, $class_id, $teacher_id]);

        json_ok(['message' => 'Class name updated']);
    } catch (Throwable $e) {
        log_system_error($pdo, 'api/classes.php:rename', 'Failed to rename class', $e->getMessage());
        json_err('Failed to update class', 500, ['detail' => $e->getMessage()]);
    }
}

// ========== DELETE CLASS (AND RELATED DATA) ==========
if ($action === 'delete') {
    try {
        $data       = read_json();
        $teacher_id = (int)($data['teacher_id'] ?? 0);
        $class_id   = (int)($data['class_id'] ?? 0);

        if ($teacher_id <= 0 || $class_id <= 0) {
            json_err('Missing teacher_id or class_id', 422);
        }

        // verify ownership
        ensure_class_owner($pdo, $class_id, $teacher_id);

        $pdo->beginTransaction();

        // 1) delete attendance_audit rows for all sessions of this class (if table exists)
        try {
            $sqlAudit = "
              DELETE aa
              FROM attendance_audit aa
              JOIN class_session s ON s.session_id = aa.session_id
              WHERE s.class_id = ?
            ";
            $stmtAudit = $pdo->prepare($sqlAudit);
            $stmtAudit->execute([$class_id]);
        } catch (Throwable $ignore) {
            // if table doesn't exist or FK not set, ignore
        }

        // 2) delete attendance rows for all sessions of this class
        $sqlAtt = "
          DELETE a
          FROM attendance a
          JOIN class_session s ON s.session_id = a.session_id
          WHERE s.class_id = ?
        ";
        $stmtAtt = $pdo->prepare($sqlAtt);
        $stmtAtt->execute([$class_id]);

        // 3) delete qr_token rows for all sessions of this class
        $sqlQr = "
          DELETE q
          FROM qr_token q
          JOIN class_session s ON s.session_id = q.session_id
          WHERE s.class_id = ?
        ";
        $stmtQr = $pdo->prepare($sqlQr);
        $stmtQr->execute([$class_id]);

        // 4) delete sessions for this class
        $stmtSess = $pdo->prepare("DELETE FROM class_session WHERE class_id = ?");
        $stmtSess->execute([$class_id]);

        // 5) delete roster rows for this class
        $stmtRoster = $pdo->prepare("DELETE FROM roster WHERE class_id = ?");
        $stmtRoster->execute([$class_id]);

        // 6) finally delete the class itself
        $stmtClass = $pdo->prepare("DELETE FROM class WHERE class_id = ? AND teacher_id = ?");
        $stmtClass->execute([$class_id, $teacher_id]);

        $pdo->commit();

        json_ok(['message' => 'Class and related data deleted']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_system_error($pdo, 'api/classes.php:delete', 'Failed to delete class', $e->getMessage());
        json_err('Failed to delete class', 500, ['detail' => $e->getMessage()]);
    }
}

// Unknown action
json_err('Unknown action', 400);
