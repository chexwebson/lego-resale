<?php
declare(strict_types=1);
// CLI-only account provisioning. There is no self-registration screen on purpose:
// accounts are created by whoever controls the server.
// Usage: php create_user.php <username> <password> <owner|contributor> ["Display Name"]
require __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

[, $username, $password, $role, $displayName] = array_pad($argv, 5, null);
$username = trim((string)($username ?? ''));
$role = trim((string)($role ?? ''));

if ($username === '' || $password === null || $password === '' || !in_array($role, ROLES, true)) {
    fwrite(STDERR, "Usage: php create_user.php <username> <password> <owner|contributor> [\"Display Name\"]\n");
    exit(1);
}

ensure_dirs();
$pdo = db();

$existing = $pdo->prepare("SELECT user_id FROM users WHERE username=?");
$existing->execute([$username]);
$now = now_iso();

if ($id = $existing->fetchColumn()) {
    $pdo->prepare("UPDATE users SET password_hash=?, role=?, display_name=?, updated_at=? WHERE user_id=?")
        ->execute([password_hash($password, PASSWORD_DEFAULT), $role, $displayName ?: $username, $now, $id]);
    echo "Updated existing user '$username' ($role).\n";
} else {
    $id = uuidv4();
    $pdo->prepare("INSERT INTO users(user_id,username,password_hash,display_name,role,active,created_at,updated_at) VALUES(?,?,?,?,?,1,?,?)")
        ->execute([$id, $username, password_hash($password, PASSWORD_DEFAULT), $displayName ?: $username, $role, $now, $now]);
    echo "Created user '$username' ($role).\n";
}
