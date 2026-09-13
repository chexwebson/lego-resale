<?php
declare(strict_types=1);

const APP_NAME = 'LEGO Resale';
const APP_VERSION = '1.0-alpha.3.13';
const DB_PATH = __DIR__ . '/storage/lego_resale.sqlite';
const PHOTO_ROOT = __DIR__ . '/storage/photos';
const IMPORT_ROOT = __DIR__ . '/storage/imports';
const BACKUP_ROOT = __DIR__ . '/storage/backups';
const POV_CACHE_TTL_HOURS = 168;
const POV_REQUEST_INTERVAL_SECONDS = 10;
const POV_MAX_RETRIES = 3;
const ROLE_OWNER = 'owner';
const ROLE_CONTRIBUTOR = 'contributor';
const ROLES = [ROLE_OWNER, ROLE_CONTRIBUTOR];

function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

function current_user(): ?array {
    start_session();
    if (empty($_SESSION['user_id'])) return null;
    return ['user_id' => $_SESSION['user_id'], 'username' => $_SESSION['username'], 'display_name' => $_SESSION['display_name'] ?? $_SESSION['username'], 'role' => $_SESSION['role']];
}

function require_login(): array {
    $u = current_user();
    if (!$u) json_response(['ok' => false, 'error' => 'Login required.'], 401);
    return $u;
}

function require_role(array $roles): array {
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) json_response(['ok' => false, 'error' => 'Not permitted for your role.'], 403);
    return $u;
}

function login_user(array $row): void {
    start_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $row['user_id'];
    $_SESSION['username'] = $row['username'];
    $_SESSION['display_name'] = $row['display_name'] ?: $row['username'];
    $_SESSION['role'] = $row['role'];
}

function logout_user(): void {
    start_session();
    $_SESSION = [];
    session_destroy();
}

function require_numeric(mixed $v, string $label): float {
    if (!is_numeric($v)) json_response(['ok' => false, 'error' => "$label must be a number."], 422);
    return (float)$v;
}

function optional_numeric(mixed $v, string $label): ?float {
    if ($v === null || $v === '') return null;
    return require_numeric($v, $label);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('PDO SQLite is not available on this server.');
    }
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    run_app_migrations($pdo);
    return $pdo;
}

function json_response(array $payload, int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function body_json(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function uuidv4(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function now_iso(): string {
    return (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
}

function clean_set_num(?string $s): ?string {
    if ($s === null) return null;
    $s = trim($s);
    return $s === '' ? null : $s; // authoritative string; DO NOT cast to integer
}

function split_set_num(string $full): array {
    if (preg_match('/^(.*)-([0-9]+)$/', $full, $m)) {
        return [$m[1], $m[2]];
    }
    return [$full, null];
}

function ensure_dirs(): void {
    foreach ([dirname(DB_PATH), PHOTO_ROOT, IMPORT_ROOT, BACKUP_ROOT] as $dir) {
        if (!is_dir($dir)) mkdir($dir, 0775, true);
    }
}

function pov_cache_is_fresh(?array $row): bool { if(!$row||empty($row['checked_at']))return false; try{$c=new DateTimeImmutable($row['checked_at']);$cut=(new DateTimeImmutable('now'))->modify('-'.POV_CACHE_TTL_HOURS.' hours');return $c>=$cut;}catch(Throwable $e){return false;} }
function queue_pov_for_set(PDO $pdo,string $setNum,bool $force=false): array { $setNum=clean_set_num($setNum)??''; if($setNum==='')return ['queued'=>false]; $q=$pdo->prepare("SELECT * FROM pov_cache WHERE set_num=?");$q->execute([$setNum]);$cache=$q->fetch()?:null; if(!$force&&pov_cache_is_fresh($cache))return ['queued'=>false,'reason'=>'fresh_cache']; $now=now_iso(); $e=$pdo->prepare("SELECT queue_id FROM pov_queue WHERE set_num=?");$e->execute([$setNum]);$id=$e->fetchColumn(); if($id){$pdo->prepare("UPDATE pov_queue SET status='pending',updated_at=?,next_attempt_at=NULL,last_error=NULL WHERE set_num=?")->execute([$now,$setNum]);return ['queued'=>true,'queue_id'=>$id];} $id=uuidv4();$pdo->prepare("INSERT INTO pov_queue(queue_id,set_num,status,attempts,created_at,updated_at) VALUES(?,?,'pending',0,?,?)")->execute([$id,$setNum,$now,$now]);return ['queued'=>true,'queue_id'=>$id]; }


function table_has_column(PDO $pdo, string $table, string $column): bool {
    foreach ($pdo->query("PRAGMA table_info(".$table.")") as $r) if (($r['name']??'') === $column) return true;
    return false;
}

function run_app_migrations(PDO $pdo): void {
    static $done=false; if($done)return; $done=true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
      customer_id TEXT PRIMARY KEY, name TEXT NOT NULL, organization TEXT NULL, email TEXT NULL, phone TEXT NULL,
      marketplace_profile TEXT NULL, notes TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_interests (
      interest_id TEXT PRIMARY KEY, customer_id TEXT NOT NULL, catalog_set_num TEXT NULL, unit_id TEXT NULL,
      status TEXT NOT NULL DEFAULT 'interested', target_price REAL NULL, notes TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
      FOREIGN KEY(customer_id) REFERENCES customers(customer_id) ON DELETE CASCADE,
      FOREIGN KEY(catalog_set_num) REFERENCES catalog_sets(set_num), FOREIGN KEY(unit_id) REFERENCES inventory_units(unit_id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_customer_interests_set ON customer_interests(catalog_set_num)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS listing_metrics (
      metric_id TEXT PRIMARY KEY, listing_id TEXT NOT NULL, observed_at TEXT NOT NULL,
      clicks INTEGER NOT NULL DEFAULT 0, saves INTEGER NOT NULL DEFAULT 0, shares INTEGER NOT NULL DEFAULT 0, inquiries INTEGER NOT NULL DEFAULT 0,
      notes TEXT NULL, FOREIGN KEY(listing_id) REFERENCES listings(listing_id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_listing_metrics_listing_date ON listing_metrics(listing_id,observed_at DESC)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
      user_id TEXT PRIMARY KEY, username TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, display_name TEXT NULL,
      role TEXT NOT NULL DEFAULT 'contributor', active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
    )");
    if(!table_has_column($pdo,'unit_photos','ai_review_requested')) $pdo->exec("ALTER TABLE unit_photos ADD COLUMN ai_review_requested INTEGER NOT NULL DEFAULT 0");
    if(!table_has_column($pdo,'sales','customer_id')) $pdo->exec("ALTER TABLE sales ADD COLUMN customer_id TEXT NULL REFERENCES customers(customer_id)");
    if(!table_has_column($pdo,'sales','projected_ask')) $pdo->exec("ALTER TABLE sales ADD COLUMN projected_ask REAL NULL");
    if(!table_has_column($pdo,'sales','projected_target')) $pdo->exec("ALTER TABLE sales ADD COLUMN projected_target REAL NULL");
    if(!table_has_column($pdo,'sales','projected_minimum')) $pdo->exec("ALTER TABLE sales ADD COLUMN projected_minimum REAL NULL");
    if(!table_has_column($pdo,'sales','projected_market')) $pdo->exec("ALTER TABLE sales ADD COLUMN projected_market REAL NULL");
    $pdo->prepare("INSERT INTO app_meta(meta_key,meta_value) VALUES('schema_version',?) ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value")->execute([APP_VERSION]);
}
