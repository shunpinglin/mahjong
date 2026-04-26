<?php
// ════════════════════════════════════════════════════════
//  麻將益智配對 — 全球排行榜 API v6.0
//  平台：Synology NAS
//  儲存：SQLite3（NAS PHP內建支援，無寫入權限問題）
//  診斷：?action=ping
// ════════════════════════════════════════════════════════

error_reporting(0);
ini_set('display_errors', 0);
ob_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean(); http_response_code(204); exit;
}

// SQLite 資料庫放在 api.php 同目錄
define('DB_FILE',      __DIR__ . '/mahjong.db');
define('MAX_NAME_LEN', 12);
define('TOP_N',        10);
define('MAX_PER_PLAYER', 50);

// ── 資料庫連線與初始化 ──
function getDB() {
    static $db = null;
    if ($db) return $db;

    $db = new SQLite3(DB_FILE);
    $db->exec('PRAGMA journal_mode=WAL;');
    $db->exec('PRAGMA busy_timeout=5000;');
    $db->exec('
        CREATE TABLE IF NOT EXISTS records (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            name     TEXT    NOT NULL,
            level    INTEGER NOT NULL DEFAULT 1,
            score    INTEGER NOT NULL DEFAULT 0,
            time_sec INTEGER NOT NULL DEFAULT 0,
            stars    INTEGER NOT NULL DEFAULT 1,
            streak   INTEGER NOT NULL DEFAULT 0,
            layout   TEXT    NOT NULL DEFAULT "rect",
            created_at INTEGER NOT NULL DEFAULT (strftime("%s","now"))
        );
        CREATE INDEX IF NOT EXISTS idx_name  ON records(name);
        CREATE INDEX IF NOT EXISTS idx_score ON records(score DESC);
    ');
    return $db;
}

// ── 工具函數 ──
function sanitizeName($n) { return mb_substr(trim(strip_tags($n??'')), 0, MAX_NAME_LEN); }
function validateInt($v,$min,$max) { $i=intval($v??0); return ($i>=$min&&$i<=$max)?$i:null; }
function json_ok($d)    { ob_end_clean(); echo json_encode(['ok'=>true,'data'=>$d], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit; }
function json_error($c,$m) { ob_end_clean(); http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m], JSON_UNESCAPED_UNICODE); exit; }

// ── 排行榜計算 ──
function calcTotalBoard($db, $limit=TOP_N) {
    $res = $db->query("
        SELECT name,
               SUM(score)   AS total,
               COUNT(*)     AS levels,
               MAX(score)   AS best,
               MAX(streak)  AS streak,
               MIN(CASE WHEN stars>=1 AND time_sec>0 THEN time_sec END) AS bestTime
        FROM records
        GROUP BY name
        ORDER BY total DESC
        LIMIT $limit
    ");
    $rows = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = [
            'name'     => $r['name'],
            'score'    => (int)$r['total'],
            'levels'   => (int)$r['levels'],
            'best'     => (int)$r['best'],
            'streak'   => (int)$r['streak'],
            'bestTime' => $r['bestTime'] ? (int)$r['bestTime'] : null,
        ];
    }
    return $rows;
}

function calcFastestBoard($db, $limit=TOP_N) {
    $res = $db->query("
        SELECT name, MIN(time_sec) AS bt, level, stars, score
        FROM records WHERE stars>=1
        GROUP BY name ORDER BY bt ASC LIMIT $limit
    ");
    $rows = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = [
            'name'  => $r['name'],
            'time'  => (int)$r['bt'],
            'level' => (int)$r['level'],
            'stars' => (int)$r['stars'],
            'score' => (int)$r['score'],
        ];
    }
    return $rows;
}

function calcStreakBoard($db, $limit=TOP_N) {
    $res = $db->query("
        SELECT name, MAX(streak) AS bs, SUM(score) AS ts
        FROM records GROUP BY name ORDER BY bs DESC LIMIT $limit
    ");
    $rows = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = [
            'name'   => $r['name'],
            'streak' => (int)$r['bs'],
            'score'  => (int)$r['ts'],
        ];
    }
    return $rows;
}

// ── 初始化 NPC 資料（首次建立資料庫時） ──
function initNPC($db) {
    $count = (int)$db->querySingle("SELECT COUNT(*) FROM records");
    if ($count > 0) return; // 已有資料，不重複初始化

    $npcs = [
        ['🏆 麻將老師傅', 9850, 42, 680, 187, 8, 3],
        ['🌟 阿嬤快手',   8200, 36, 620, 215, 6, 3],
        ['🎯 龜策大師',   7100, 31, 590, 243, 5, 3],
        ['🐢 穩健達人',   5900, 26, 520, 278, 4, 3],
        ['🌸 花式玩家',   4800, 22, 460, 312, 4, 2],
        ['⚡ 閃電手',     3900, 18, 400, 156, 3, 3],
        ['🍀 幸運星',     3100, 14, 350, 389, 3, 2],
        ['🎋 竹籬大叔',   2400, 11, 310, 445, 2, 2],
        ['🌙 夜貓玩家',   1700,  8, 260, 512, 2, 2],
        ['🌱 新手上路',   1000,  5, 200, 598, 1, 1],
    ];

    $db->exec('BEGIN');
    foreach ($npcs as [$name, $target, $levels, $best, $bestTime, $maxStreak, $stars]) {
        // 第一筆：最佳記錄
        $db->exec("INSERT INTO records(name,level,score,time_sec,stars,streak,layout)
            VALUES('$name',12,$best,$bestTime,$stars,$maxStreak,'rect')");
        $remaining = $target - $best;

        // 其餘幾筆填滿總分
        $perLevel = $levels > 1 ? intval($remaining / ($levels - 1)) : 0;
        for ($i = 1; $i < $levels - 1; $i++) {
            $s = max(50, $perLevel);
            $t = $bestTime + $i * 30;
            $sk = max(1, $maxStreak - $i);
            $db->exec("INSERT INTO records(name,level,score,time_sec,stars,streak,layout)
                VALUES('$name',max(1,12-$i),$s,$t,1,$sk,'rect')");
            $remaining -= $s;
        }
        // 最後一筆補齊
        if ($remaining > 0) {
            $db->exec("INSERT INTO records(name,level,score,time_sec,stars,streak,layout)
                VALUES('$name',1,$remaining,999,1,1,'rect')");
        }
    }
    $db->exec('COMMIT');
}

// ════════════════════════════════════════════════════════
//  路由
// ════════════════════════════════════════════════════════
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

// ── ping ──
case 'ping':
    $dbOk = false; $dbErr = '';
    try {
        $db = getDB();
        initNPC($db);
        $db->exec("CREATE TABLE IF NOT EXISTS _test(x INTEGER)");
        $db->exec("INSERT INTO _test VALUES(1)");
        $db->exec("DELETE FROM _test");
        $dbOk = true;
    } catch (Exception $e) { $dbErr = $e->getMessage(); }

    $total   = $dbOk ? (int)$db->querySingle("SELECT COUNT(*) FROM records") : 0;
    $players = $dbOk ? (int)$db->querySingle("SELECT COUNT(DISTINCT name) FROM records") : 0;

    json_ok([
        'status'   => 'ok',
        'php'      => PHP_VERSION,
        'db_type'  => 'sqlite3',
        'db_file'  => DB_FILE,
        'db_ok'    => $dbOk,
        'db_error' => $dbErr,
        'records'  => $total,
        'players'  => $players,
        'db_msg'   => $dbOk
            ? "✓ SQLite3 · {$players} 位玩家 · {$total} 筆記錄（含NPC）"
            : "✗ SQLite3 失敗：$dbErr",
        'message'  => $dbOk ? '✓ 環境正常！全球排行榜就緒' : '⚠ 資料庫失敗',
        'sqlite3_ext' => class_exists('SQLite3'),
    ]);
    break;

// ── submit ──
case 'submit':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405,'請使用POST');

    $b      = json_decode(file_get_contents('php://input'),true) ?: $_POST;
    $name   = sanitizeName($b['name']   ?? '');
    $level  = validateInt($b['level']   ?? 0, 1, 99);
    $score  = validateInt($b['score']   ?? 0, 0, 999999);
    $time   = validateInt($b['time']    ?? 0, 1, 7200);
    $stars  = validateInt($b['stars']   ?? 1, 1, 3);
    $streak = validateInt($b['streak']  ?? 0, 0, 999);
    $layout = in_array($b['layout']??'',['rect','pyramid','turtle','dragon','flower'])
              ? $b['layout'] : 'rect';

    if (!$name||!$level||!$score||!$time) json_error(400,'缺少必要欄位');

    $db = getDB();
    initNPC($db);
    $esc = SQLite3::escapeString($name);

    // 清理超量記錄（保留最高分）
    $cnt = (int)$db->querySingle("SELECT COUNT(*) FROM records WHERE name='$esc'");
    if ($cnt >= MAX_PER_PLAYER) {
        $db->exec("DELETE FROM records WHERE name='$esc' AND id=(
            SELECT id FROM records WHERE name='$esc' ORDER BY score ASC LIMIT 1)");
    }

    // 插入新記錄
    $st = $db->prepare('INSERT INTO records(name,level,score,time_sec,stars,streak,layout)
        VALUES(:n,:l,:s,:t,:st,:sr,:ly)');
    $st->bindValue(':n', $name, SQLITE3_TEXT);
    $st->bindValue(':l', $level, SQLITE3_INTEGER);
    $st->bindValue(':s', $score, SQLITE3_INTEGER);
    $st->bindValue(':t', $time,  SQLITE3_INTEGER);
    $st->bindValue(':st',$stars, SQLITE3_INTEGER);
    $st->bindValue(':sr',$streak,SQLITE3_INTEGER);
    $st->bindValue(':ly',$layout,SQLITE3_TEXT);
    $st->execute();

    // 計算累積排名
    $board = calcTotalBoard($db, 999);
    $rank  = count($board) + 1;
    $myTotal = 0;
    foreach ($board as $i => $entry) {
        if ($entry['name'] === $name) {
            $rank    = $i + 1;
            $myTotal = $entry['score'];
            break;
        }
    }

    json_ok(['rank'=>$rank, 'totalScore'=>$myTotal,
             'totalPlayers'=>(int)$db->querySingle("SELECT COUNT(DISTINCT name) FROM records")]);
    break;

// ── board ──
case 'board':
    $tab = $_GET['tab'] ?? 'total';
    $db  = getDB();
    initNPC($db);

    if ($tab === 'total')        $top10 = calcTotalBoard($db);
    elseif ($tab === 'fastest')  $top10 = calcFastestBoard($db);
    elseif ($tab === 'streak')   $top10 = calcStreakBoard($db);
    else json_error(400,'無效榜單');

    $tp = (int)$db->querySingle("SELECT COUNT(DISTINCT name) FROM records");
    $tg = (int)$db->querySingle("SELECT COUNT(*) FROM records");
    json_ok(['tab'=>$tab,'top10'=>$top10,'totalPlayers'=>$tp,'totalGames'=>$tg,'updatedAt'=>date('Y-m-d H:i:s')]);
    break;

// ── my ──
case 'my':
    $name = sanitizeName($_GET['name']??'');
    if (!$name) json_error(400,'請提供名稱');
    $db = getDB();
    $board = calcTotalBoard($db, 999);
    $rank=null; $tot=0;
    foreach ($board as $i=>$e) {
        if ($e['name']===$name) { $rank=$i+1; $tot=$e['score']; break; }
    }
    $esc = SQLite3::escapeString($name);
    $bt  = $db->querySingle("SELECT MIN(time_sec) FROM records WHERE name='$esc' AND stars>=1");
    json_ok(['name'=>$name,'totalScore'=>(int)$tot,'totalRank'=>$rank,'bestTime'=>$bt?(int)$bt:null]);
    break;

// ── stats ──
case 'stats':
    $db = getDB();
    initNPC($db);
    json_ok([
        'totalPlayers' => (int)$db->querySingle("SELECT COUNT(DISTINCT name) FROM records"),
        'totalGames'   => (int)$db->querySingle("SELECT COUNT(*) FROM records"),
        'totalScore'   => (int)$db->querySingle("SELECT COALESCE(SUM(score),0) FROM records"),
    ]);
    break;

default:
    json_ok(['service'=>'麻將排行榜 API','version'=>'6.0-SQLite','tip'=>'?action=ping']);
}
