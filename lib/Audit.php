<?php
class Audit {
    public static function log(PDO $db, string $action, ?int $project_id = null, ?string $detail = null): void {
        try {
            $db->prepare('INSERT INTO audit_log (user_id, project_id, action, detail, ip) VALUES (?, ?, ?, ?, ?)')
               ->execute([$_SESSION['user_id'] ?? null, $project_id, $action, $detail, $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (Exception $e) { /* don't let audit failures break the main op */ }
    }
}
