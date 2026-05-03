<?php
require_once __DIR__ . '/ProjectTypeBase.php';

class WebsiteProject extends ProjectTypeBase {
    public static function typeSlug(): string    { return 'website'; }
    public static function typeName(): string    { return 'Website'; }
    public static function typeIcon(): string    { return 'bi-globe'; }
    public static function description(): string { return 'Static or PHP website'; }

    public static function tabs(): array {
        return ['dashboard', 'analytics', 'files', 'git', 'notes', 'settings'];
    }

    public static function detectFromPath(string $path): bool {
        return file_exists($path . '/index.html') || file_exists($path . '/index.php');
    }
}
