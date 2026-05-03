<?php
class ProjectTypes {
    private static array $types = [];

    public static function load(): void {
        if (!empty(self::$types)) return;
        require_once ROOT . '/lib/project-types/ProjectTypeBase.php';
        foreach (glob(ROOT . '/lib/project-types/*.php') as $file) {
            if (basename($file) === 'ProjectTypeBase.php') continue;
            require_once $file;
        }
        foreach (get_declared_classes() as $class) {
            if (is_subclass_of($class, 'ProjectTypeBase')) {
                self::$types[$class::typeSlug()] = $class;
            }
        }
    }

    public static function all(): array   { return self::$types; }

    public static function get(string $slug): ?string {
        return self::$types[$slug] ?? null;
    }

    public static function detect(string $path): string {
        foreach (self::$types as $slug => $class) {
            if ($slug !== 'generic' && $class::detectFromPath($path)) return $slug;
        }
        return 'generic';
    }

    public static function forSelect(): array {
        return array_values(array_map(fn($class) => [
            'slug' => $class::typeSlug(),
            'name' => $class::typeName(),
            'icon' => $class::typeIcon(),
        ], self::$types));
    }
}
