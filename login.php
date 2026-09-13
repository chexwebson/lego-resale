<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
ensure_dirs();
$pdo = db();

if (current_user()) {
    header('Location: index.html');
    exit;
}

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $s = $pdo->prepare("SELECT * FROM users WHERE username=? AND active=1");
    $s->execute([$username]);
    $row = $s->fetch();
    if ($row && password_verify($password, $row['password_hash'])) {
        login_user($row);
        header('Location: index.html');
        exit;
    }
    $error = 'Incorrect username or password.';
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in - LEGO Resale</title><link rel="stylesheet" href="styles.css?v=<?= htmlspecialchars(APP_VERSION) ?>">
<style>.loginWrap{max-width:360px;margin:12vh auto;padding:0 20px}.loginWrap h1{font-size:20px}</style>
</head>
<body>
<div class="loginWrap">
<h1>LEGO Resale</h1>
<div class="panel">
<h3>Sign in</h3>
<?php if ($error): ?><p class="bad"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="post">
<label>Username <input name="username" autofocus required></label>
<label>Password <input name="password" type="password" required></label>
<div class="buttonRow"><button type="submit">Sign in</button></div>
</form>
</div>
</div>
</body></html>
