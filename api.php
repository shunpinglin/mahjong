<?php
// ═══════════════════════════════════════════════════
//  麻將益智配對 — 全球排行榜 API v2.0
//  平台：Railway + PostgreSQL
//  診斷：?action=ping
// ═══════════════════════════════════════════════════

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); exit;
}

// ── 設定（Railway 自動注入環境變數）──
define('MAX_NAME_LEN', 12);
define('MAX_RECORDS',  50);
define('TOP_N',        10);

// ── 資料庫連線（PostgreSQL）──
function getDB() {
    static $pdo = null;
    if ($pdo) return $pdo;

    // Railway 提供 DATABASE_URL 環境變數
    $url = getenv('DATABASE_URL');
    if (!$url) {
        json_error(500, '找不到 DATABASE_URL 環境變數，請在 Railway 設定');
    }

    try {
        // 解析 DATABASE_URL (格式: postgresql://user:pass@host:port/db)
        $parts = parse_url($url);
        $dsn   = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
            $parts['host'],
            $parts['port'] ?? 5432,
            ltrim($parts['path'], '/')
        );
        $pdo = new PDO($dsn, $parts['user'], $parts['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // 建立資料表（第一次執行時）
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS records (
                id         SERIAL PRIMARY KEY,
                name       VARCHAR(20)  NOT NULL,
                level      SMALLINT     NOT NULL DEFAULT 1,
                score      INTEGER      NOT NULL DEFAULT 0,
                time_sec   INTEGER      NOT NULL DEFAULT 0,
                stars      SMALLINT     NOT NULL DEFAULT 1,
                streak     SMALLINT     NOT NULL DEFAULT 0,
                layout     VARCHAR(20)  DEFAULT 'rect',
                ip_hash    VARCHAR(64)  DEFAULT '',
                created_at TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );
            CREATE INDEX IF NOT EXISTS idx_score  ON records(score DESC);
            CREATE INDEX IF NOT EXISTS idx_time   ON records(time_sec ASC);
            CREATE INDEX IF NOT EXISTS idx_streak ON records(streak DESC);
            CREATE INDEX IF NOT EXISTS idx_name   ON records(name);
        ");

        return $pdo;
    } catch (Exception $e) {
        json_error(500, 'DB連線失敗：' . $e->getMessage());
    }
}

// ── 速率限制（用 PostgreSQL 存）──
function checkRateLimit($ip) {
    try {
        $db  = getDB();
        $key = md5($ip);
        // 建立速率限制表（若不存在）
        $db->exec("
            CREATE TABLE IF NOT EXISTS rate_limit (
                ip_hash   VARCHAR(64) NOT NULL,
                hit_time  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );
            CREATE INDEX IF NOT EXISTS idx_rate ON rate_limit(ip_hash, hit_time);
        ");
        // 清除1小時前的記錄
        $db->exec("DELETE FROM rate_limit WHERE hit_time < NOW() - INTERVAL '1 hour'");
        // 計算最近1分鐘的請求數
        $stmt = $db->prepare("SELECT COUNT(*) FROM rate_limit WHERE ip_hash=? AND hit_time > NOW() - INTERVAL '1 minute'");
        $stmt->execute([$key]);
        if ((int)$stmt->fetchColumn() >= 10) return false;
        // 記錄此次請求
        $db->prepare("INSERT INTO rate_limit(ip_hash) VALUES(?)")->execute([$key]);
        return true;
    } catch (Exception $e) {
        return true; // 速率限制失敗時允許通過
    }
}

// ── 工具函數 ──
function sanitizeName($n) {
    return mb_substr(trim(strip_tags($n ?? '')), 0, MAX_NAME_LEN);
}
function validateInt($v, $min, $max) {
    $i = intval($v ?? 0);
    return ($i >= $min && $i <= $max) ? $i : null;
}
function json_ok($d) {
    echo json_encode(['ok'=>true,'data'=>$d], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    exit;
}
function json_error($c, $m) {
    http_response_code($c);
    echo json_encode(['ok'=>false,'error'=>$m], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 路由 ──
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

// ── 診斷 ──────────────────────────────────────────
case 'ping':
    $dbOk = false;
    $dbMsg = '';
    try {
        $db = getDB();
        $db->query("SELECT 1");
        $dbOk  = true;
        $dbMsg = '✓ PostgreSQL 連線正常';
    } catch (Exception $e) {
        $dbMsg = '✗ ' . $e->getMessage();
    }
    json_ok([
        'status'  => 'ok',
        'php'     => PHP_VERSION,
        'db'      => $dbOk,
        'db_msg'  => $dbMsg,
        'message' => $dbOk ? '✓ 環境正常！' : '⚠ 資料庫連線失敗',
    ]);
    break;

// ── 提交記錄 ──────────────────────────────────────
case 'submit':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, '請使用POST');

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!checkRateLimit($ip)) json_error(429, '提交過於頻繁');

    $b      = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $name   = sanitizeName($b['name']   ?? '');
    $level  = validateInt($b['level']   ?? 0,  1, 99);
    $score  = validateInt($b['score']   ?? 0,  0, 999999);
    $time   = validateInt($b['time']    ?? 0,  1, 7200);
    $stars  = validateInt($b['stars']   ?? 1,  1, 3);
    $streak = validateInt($b['streak']  ?? 0,  0, 999);
    $layout = in_array($b['layout'] ?? '', ['rect','pyramid','turtle','dragon','flower'])
              ? $b['layout'] : 'rect';

    if (!$name || !$level || !$score || !$time) json_error(400, '缺少必要欄位');
    if ($score > $level * 500 + 3000) json_error(400, '分數異常');

    $db = getDB();

    // 清理超量記錄
    $stmt = $db->prepare("SELECT COUNT(*) FROM records WHERE name=?");
    $stmt->execute([$name]);
    if ((int)$stmt->fetchColumn() >= MAX_RECORDS) {
        $db->prepare("DELETE FROM records WHERE name=? AND id=(SELECT id FROM records WHERE name=? ORDER BY score ASC LIMIT 1)")
           ->execute([$name, $name]);
    }

    // 插入記錄
    $stmt = $db->prepare("INSERT INTO records(name,level,score,time_sec,stars,streak,layout,ip_hash) VALUES(?,?,?,?,?,?,?,?) RETURNING id");
    $stmt->execute([$name, $level, $score, $time, $stars, $streak, $layout, md5($ip)]);
    $newId = $stmt->fetchColumn();

    // 計算排名
    $stmt = $db->prepare("SELECT SUM(score) FROM records WHERE name=?");
    $stmt->execute([$name]);
    $myTotal = (int)$stmt->fetchColumn();

    $stmt = $db->query("SELECT COUNT(DISTINCT name)+1 FROM (SELECT name,SUM(score) t FROM records GROUP BY name HAVING SUM(score)>$myTotal) x");
    $rank = (int)$stmt->fetchColumn();

    json_ok(['id'=>(int)$newId, 'rank'=>$rank]);
    break;

// ── 排行榜 ────────────────────────────────────────
case 'board':
    $tab = $_GET['tab'] ?? 'total';
    $db  = getDB();
    $rows = [];

    switch ($tab) {
        case 'total':
            $res = $db->query("
                SELECT name,
                    SUM(score)   AS total_score,
                    COUNT(*)     AS levels_cleared,
                    MAX(score)   AS best_single,
                    MIN(CASE WHEN stars>=1 THEN time_sec END) AS best_time,
                    MAX(streak)  AS best_streak
                FROM records GROUP BY name
                ORDER BY total_score DESC LIMIT " . TOP_N
            );
            foreach ($res->fetchAll() as $r) {
                $rows[] = [
                    'name'     => $r['name'],
                    'score'    => (int)$r['total_score'],
                    'levels'   => (int)$r['levels_cleared'],
                    'best'     => (int)$r['best_single'],
                    'bestTime' => $r['best_time'] ? (int)$r['best_time'] : null,
                    'streak'   => (int)$r['best_streak'],
                ];
            }
            break;

        case 'fastest':
            $res = $db->query("
                SELECT name, MIN(time_sec) best_time, level, stars, score
                FROM records WHERE stars>=1
                GROUP BY name, level, stars, score
                ORDER BY best_time ASC LIMIT " . TOP_N
            );
            foreach ($res->fetchAll() as $r) {
                $rows[] = [
                    'name'  => $r['name'],
                    'time'  => (int)$r['best_time'],
                    'level' => (int)$r['level'],
                    'stars' => (int)$r['stars'],
                    'score' => (int)$r['score'],
                ];
            }
            break;

        case 'streak':
            $res = $db->query("
                SELECT name, MAX(streak) best_streak, SUM(score) total_score
                FROM records GROUP BY name
                ORDER BY best_streak DESC LIMIT " . TOP_N
            );
            foreach ($res->fetchAll() as $r) {
                $rows[] = [
                    'name'   => $r['name'],
                    'streak' => (int)$r['best_streak'],
                    'score'  => (int)$r['total_score'],
                ];
            }
            break;

        default:
            json_error(400, '無效榜單類型');
    }

    $tp = (int)$db->query("SELECT COUNT(DISTINCT name) FROM records")->fetchColumn();
    $tg = (int)$db->query("SELECT COUNT(*) FROM records")->fetchColumn();
    json_ok(['tab'=>$tab,'top10'=>$rows,'totalPlayers'=>$tp,'totalGames'=>$tg,'updatedAt'=>date('Y-m-d H:i:s')]);
    break;

// ── 個人查詢 ──────────────────────────────────────
case 'my':
    $name = sanitizeName($_GET['name'] ?? '');
    if (!$name) json_error(400, '請提供玩家名稱');
    $db = getDB();

    $stmt = $db->prepare("SELECT SUM(score) FROM records WHERE name=?");
    $stmt->execute([$name]);
    $tot = (int)$stmt->fetchColumn();

    $rank = null;
    if ($tot) {
        $r = $db->query("SELECT COUNT(DISTINCT name)+1 FROM (SELECT name,SUM(score) t FROM records GROUP BY name HAVING SUM(score)>$tot) x");
        $rank = (int)$r->fetchColumn();
    }

    $stmt = $db->prepare("SELECT MIN(time_sec) FROM records WHERE name=? AND stars>=1");
    $stmt->execute([$name]);
    $bt = $stmt->fetchColumn();

    json_ok(['name'=>$name,'totalScore'=>$tot,'totalRank'=>$rank,'bestTime'=>$bt?(int)$bt:null]);
    break;

// ── 統計 ──────────────────────────────────────────
case 'stats':
    $db = getDB();
    json_ok([
        'totalPlayers' => (int)$db->query("SELECT COUNT(DISTINCT name) FROM records")->fetchColumn(),
        'totalGames'   => (int)$db->query("SELECT COUNT(*) FROM records")->fetchColumn(),
        'totalScore'   => (int)$db->query("SELECT COALESCE(SUM(score),0) FROM records")->fetchColumn(),
    ]);
    break;

default:
    json_ok(['service'=>'麻將排行榜 API','version'=>'2.0','tip'=>'?action=ping 診斷']);
}
