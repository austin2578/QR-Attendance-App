<?php
// api/auth.php
require_once __DIR__ . "/../backend/database.php";
require_once __DIR__ . "/../backend/utils.php";
require_once __DIR__ . "/../backend/config.php";
require_once __DIR__ . "/../backend/logging.php"; 

header("Content-Type: application/json");
$action = $_GET['action'] ?? null;

/**
 * Log login attempts (F3.5 – auditing)
 * Does NOT throw; failures here must not break login.
 */
function log_login_attempt(PDO $pdo, ?int $user_id, string $email, bool $success, ?string $reason = null): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt = $pdo->prepare("
            INSERT INTO login_log (user_id, email, success, reason, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id,
            $email,
            $success ? 1 : 0,
            $reason,
            $ip,
            $ua
        ]);
    } catch (Throwable $e) {
        log_system_error(
            $pdo,
            'api/auth.php:login',
            'Exception during login',
            $e->getMessage()
        );
        // you may also already be logging login attempts here, keep that
        json_error("Login failed", 500, [
            "detail" => $e->getMessage(),
            "code"   => $e->getCode(),
        ]);
    }
}

if ($action === "register") {
    $data = require_json_input();
    if (empty($data['name']) || empty($data['email']) || empty($data['password']) || empty($data['role'])) {
        json_error("Missing fields (name, email, password, role required)", 422);
    }

    $name  = trim($data['name']);
    $email = trim($data['email']);
    $pass  = (string)$data['password'];
    $role  = trim($data['role']);

    if (!in_array($role, ['teacher', 'student'], true)) {
        json_error("Invalid role. Must be 'teacher' or 'student'.", 422);
    }

    // unique email
    $stmt = $pdo->prepare("SELECT user_id FROM app_user WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        json_error("Email already registered.", 409);
    }

    // hash password into password_hash column (matches your schema)
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        "INSERT INTO app_user (name, email, password_hash, role) VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$name, $email, $hash, $role]);

    echo json_encode([
        "success" => true,
        "message" => "Registration successful"
    ]);
    exit;
}

if ($action === "login") {
    $data = require_json_input();
    if (empty($data['email']) || empty($data['password'])) {
        json_error("Missing email or password", 422);
    }

    $email = trim($data['email']);
    $pass  = (string)$data['password'];

    try {
        // look up user by email
        $stmt = $pdo->prepare("SELECT user_id, name, email, password_hash, role FROM app_user WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // log failed attempt (no such email)
            log_login_attempt($pdo, null, $email, false, 'Invalid credentials (no user)');
            json_error("Invalid email or password.", 401);
        }

        if (!password_verify($pass, $user['password_hash'])) {
            // incorrect password
            log_login_attempt($pdo, (int)$user['user_id'], $email, false, 'Invalid credentials (bad password)');
            json_error("Invalid email or password.", 401);
        }

        // success: generate simple session token (same behavior as before)
        $token   = bin2hex(random_bytes(24));
        $expires = date("Y-m-d H:i:s", time() + 3600); // 1 hour, adjust as needed

        // log success
        log_login_attempt($pdo, (int)$user['user_id'], $email, true, null);

        // Return top-level fields (what your frontend expects)
        echo json_encode([
            "success" => true,
            "token"   => $token,
            "expires" => $expires,
            "user_id" => $user['user_id'],
            "role"    => $user['role'],
            "name"    => $user['name'],
        ]);
        exit;
    } catch (Throwable $e) {
        // log as failed with reason "exception"
        log_login_attempt($pdo, null, $email, false, 'Exception: ' . $e->getMessage());

        json_error("Login failed", 500, [
            "detail"   => $e->getMessage(),
            "code"     => $e->getCode(),
        ]);
    }
}

json_error("Unknown action", 400);

?>
