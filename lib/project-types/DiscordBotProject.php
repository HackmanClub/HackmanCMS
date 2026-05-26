<?php
require_once __DIR__ . '/ProjectTypeBase.php';

class DiscordBotProject extends ProjectTypeBase {
    public static function typeSlug(): string    { return 'discord-bot'; }
    public static function typeName(): string    { return 'Discord Bot'; }
    public static function typeIcon(): string    { return 'bi-robot'; }
    public static function description(): string { return 'Discord bot managed via systemd'; }

    public static function tabs(): array {
        return ['dashboard', 'bot', 'botconfig', 'logs', 'files', 'notes', 'settings'];
    }

    public static function detectFromPath(string $path): bool {
        return file_exists($path . '/main.py')
            && is_dir($path . '/cogs')
            && file_exists($path . '/config.json');
    }
}
