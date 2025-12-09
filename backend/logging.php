<?php
// backend/logging.php
declare(strict_types=1);

/**
 * Log a system-level error without breaking the main flow.
 *
 * @param PDO    $pdo
 * @param string $context  Short identifier, e.g. 'api/sessions.php:delete'
 * @param string $message  Human-readable message
 * @param string|null $details  Optional detailed info (stack, JSON, etc.)
 */
function log_system_error(PDO $pdo, string $context, string $message, ?string $details = null): void
{
    try {
        $ip = $_SERVER['REMOTE_ADDR']  ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt = $pdo->prepare("
            INSERT INTO system_log (level, context, message, details, ip_address, user_agent)
            VALUES ('ERROR', ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $context,
            mb_substr($message, 0, 255),
            $details,
            $ip,
            $ua
        ]);
    } catch (Throwable $e) {
        // Swallow logging failures: we never want this to break the main request
    }
}
