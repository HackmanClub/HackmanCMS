<?php
require_once __DIR__ . '/ProjectTypeBase.php';

class GenericProject extends ProjectTypeBase {
    public static function typeSlug(): string    { return 'generic'; }
    public static function typeName(): string    { return 'Generic'; }
    public static function typeIcon(): string    { return 'bi-folder'; }
    public static function description(): string { return 'Generic project directory'; }
}
