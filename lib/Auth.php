<?php
class Auth {
    public static function check(): bool {
        return !empty($_SESSION['user_id']);
    }

    public static function requireLogin(): void {
        if (!self::check()) {
            header('Location: /login');
            exit;
        }
    }

    public static function login(PDO $db, string $username, string $password): bool {
        $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $username;
        return true;
    }

    public static function logout(): void {
        $_SESSION = [];
        session_destroy();
    }

    public static function currentUser(): ?array {
        if (!self::check()) return null;
        return ['id' => $_SESSION['user_id'], 'username' => $_SESSION['username']];
    }

    public static function createUser(PDO $db, string $username, string $password): int {
        $stmt = $db->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
        $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT)]);
        return (int)$db->lastInsertId();
    }

    public static function hasUsers(PDO $db): bool {
        return (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    }
}
