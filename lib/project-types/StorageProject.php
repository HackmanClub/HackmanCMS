<?php
require_once __DIR__ . '/ProjectTypeBase.php';

class StorageProject extends ProjectTypeBase {
    public static function typeSlug(): string    { return 'storage'; }
    public static function typeName(): string    { return 'Storage'; }
    public static function typeIcon(): string    { return 'bi-hdd'; }
    public static function description(): string { return 'File storage directory'; }

    public static function tabs(): array {
        return ['dashboard', 'analytics', 'media', 'files', 'notes', 'settings'];
    }

    public static function detectFromPath(string $path): bool {
        return is_dir($path . '/uploads') || is_dir($path . '/files') || is_dir($path . '/storage');
    }
}
