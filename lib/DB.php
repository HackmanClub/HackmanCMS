<?php
class DB {
    private static ?PDO $instance = null;

    public static function connect(string $path): PDO {
        if (self::$instance === null) {
            self::$instance = new PDO('sqlite:' . $path, options: [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$instance->exec('PRAGMA foreign_keys = ON');
            self::$instance->exec('PRAGMA journal_mode = WAL');
        }
        return self::$instance;
    }

    public static function get(): PDO {
        return self::$instance ?? throw new RuntimeException('DB not connected');
    }

    public static function autoMigrate(string $sqlDir): array {
        $db = self::get();
        $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            version    TEXT PRIMARY KEY,
            applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $files = glob($sqlDir . '/*.sql');
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if ($db->query("SELECT 1 FROM schema_migrations WHERE version = " . $db->quote($version))->fetch()) {
                continue;
            }
            $db->exec(file_get_contents($file));
            $db->exec("INSERT INTO schema_migrations (version) VALUES (" . $db->quote($version) . ")");
            $applied[] = $version;
        }
        return $applied;
    }
}
