<?php
declare(strict_types=1);
// One-time, browser-runnable account bootstrap for servers with no CLI/SSH access.
// There is no login gate on this page — there's nothing to gate against on a fresh
// deploy. That is the same trust model this project already uses for one-shot
// upgrade scripts (see UPGRADE_alpha3.php in the changelog): upload it, run it once
// yourself, then delete it from the server immediately.
require __DIR__ . '/config.php';
ensure_dirs();
$pdo = db();

$message = null;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $role = (string)($_POST['role'] ?? '');
    $displayName = trim((string)($_POST['display_name'] ?? ''));

    if ($username === '' || $password === '' || !in_array($role, ROLES, true)) {
        $error = 'Username, password, and a valid role are required.';
    } else {
        $existing = $pdo->prepare("SELECT user_id FROM users WHERE username=?");
        $existing->execute([$username]);
        $now = now_iso();
        if ($id = $existing->fetchColumn()) {
            $pdo->prepare("UPDATE users SET password_hash=?, role=?, display_name=?, updated_at=? WHERE user_id=?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $role, $displayName ?: $username, $now, $id]);
            $message = "Updated existing user '$username' ($role).";
        } else {
            $id = uuidv4();
            $pdo->prepare("INSERT INTO users(user_id,username,password_hash,display_name,role,active,created_at,updated_at) VALUES(?,?,?,?,?,1,?,?)")
                ->execute([$id, $username, password_hash($password, PASSWORD_DEFAULT), $displayName ?: $username, $role, $now, $now]);
            $message = "Created user '$username' ($role).";
        }
    }
}

$userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Account setup - LEGO Resale</title><link rel="stylesheet" href="styles.css?v=<?= htmlspecialchars(APP_VERSION) ?>">
<style>.setupWrap{max-width:480px;margin:8vh auto;padding:0 20px}.warn-banner{background:#fff3cd;border:1px solid #e0c46c;border-radius:8px;padding:12px 14px;margin-bottom:16px;color:#6b5300}</style>
</head>
<body>
<div class="setupWrap">
<h1>LEGO Resale — account setup</h1>
<div class="warn-banner"><b>Delete this file from the server as soon as you're done.</b> It has no login gate — that's expected on a fresh deploy, but it should not stay on a public host.</div>
<div class="panel">
<p class="muted"><?= $userCount ?> account(s) currently exist.</p>
<?php if ($message): ?><p class="good"><?= htmlspecialchars($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="bad"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="post">
<label>Username <input name="username" required autofocus></label>
<label>Password <input name="password" type="password" required></label>
<label>Display name (optional) <input name="display_name"></label>
<label>Role
<select name="role">
<option value="owner">Owner — full access, incl. money and customers</option>
<option value="contributor">Contributor — inventory/photos only</option>
</select>
</label>
<div class="buttonRow"><button type="submit">Create / update account</button></div>
</form>
</div>
<p class="muted"><a href="login.php">Go to sign in</a></p>
</div>
</body></html>
