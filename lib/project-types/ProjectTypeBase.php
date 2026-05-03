<?php
abstract class ProjectTypeBase {
    abstract public static function typeSlug(): string;
    abstract public static function typeName(): string;
    abstract public static function typeIcon(): string;

    /** Tabs shown on the project page, in order */
    public static function tabs(): array {
        return ['dashboard', 'analytics', 'files', 'notes', 'settings'];
    }

    /** Whitelisted commands available in the command runner */
    public static function commands(): array {
        return [];
    }

    /** Return true if this type can be auto-detected from the given path */
    public static function detectFromPath(string $path): bool {
        return false;
    }

    public static function description(): string {
        return '';
    }
}
