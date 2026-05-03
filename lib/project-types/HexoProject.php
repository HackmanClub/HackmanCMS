<?php
require_once __DIR__ . '/ProjectTypeBase.php';

class HexoProject extends ProjectTypeBase {
    public static function typeSlug(): string    { return 'hexo'; }
    public static function typeName(): string    { return 'Hexo'; }
    public static function typeIcon(): string    { return 'bi-hexagon-fill'; }
    public static function description(): string { return 'Hexo static site generator'; }

    public static function tabs(): array {
        return ['dashboard', 'analytics', 'posts', 'config', 'files',
                'run', 'themes', 'plugins', 'git',
                'notes', 'settings'];
    }

    public static function commands(): array {
        return [
            ['id' => 'generate', 'label' => 'Generate',   'cmd' => 'hexo generate'],
            ['id' => 'clean',    'label' => 'Clean',       'cmd' => 'hexo clean'],
            ['id' => 'deploy',   'label' => 'Deploy',      'cmd' => 'hexo deploy'],
            ['id' => 'version',  'label' => 'Hexo version','cmd' => 'hexo version'],
        ];
    }

    public static function detectFromPath(string $path): bool {
        return file_exists($path . '/_config.yml')
            && (is_dir($path . '/source') || is_dir($path . '/themes'));
    }
}
