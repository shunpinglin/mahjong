<?php
// ════════════════════════════════════════════════════════
//  麻將益智配對 — 全球排行榜 API v3.0
//  平台：Railway
//  DB：Postgres（透過 pg_* 函數，若無則降回 /tmp SQLite）
//  診斷：?action=ping
// ════════════════════════════════════════════════════════

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

define('MAX_NAME_LEN', 12);
define('MAX_RECORDS',  50);
define('TOP_N',        10);

// ════════════════════════════════════════════════════════
//  DB 連線 — 優先 Postgres，降回 SQLite
// ════════════════════════════════════════════════════════

function usePostgres() {
    return function_exists('pg_connect') && getenv('DATABASE_URL');
}

function getPgConn() {
    static $conn = null;
    if ($conn && pg_connection_status($conn) === PGSQL_CONNECTION_OK) return $conn;
    $url   = getenv('DATABASE_URL');
    $parts = parse_url($url);
    $host  = $parts['host'];
    $port  = $parts['port'] ?? 5432;
    $db    = ltrim($parts['path'], '/');
    $user  = $parts['user'];
    $pass  = $parts['pass'];
    $conn  = pg_connect("host=$host port=$port dbname=$db user=$user password=$pass sslmode=require");
    if (!$conn) json_error(500, 'Postgres 連線失敗');
    // 建立資料表
    pg_query($conn, "
        CREATE TABLE IF NOT EXISTS records (
            id SERIAL PRIMARY KEY, name VARCHAR(20) NOT NULL,
            level SMALLINT NOT NULL DEFAULT 1, score INTEGER NOT NULL DEFAULT 0,
            time_sec INTEGER NOT NULL DEFAULT 0, stars SMALLINT NOT NULL DEFAULT 1,
            streak SMALLINT NOT NULL DEFAULT 0, layout VARCHAR(20) DEFAULT 'rect',
            ip_hash VARCHAR(64) DEFAULT '', created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        );
        CREATE INDEX IF NOT EXISTS idx_score  ON records(score DESC);
        CREATE INDEX IF NOT EXISTS idx_time   ON records(time_sec ASC);
        CREATE INDEX IF NOT EXISTS idx_streak ON records(streak DESC);
        CREATE INDEX IF NOT EXISTS idx_name   ON records(name);
        CREATE TABLE IF NOT EXISTS rate_limit (
            ip_hash VARCHAR(64), hit_time TIMESTAMPTZ NOT NULL DEFAULT NOW()
        );
        CREATE INDEX IF NOT EXISTS idx_rate ON rate_limit(ip_hash, hit_time);
    ");
    return $conn;
}

function getSqliteDB() {
    static $db = null;
    if ($db) return $db;
    $db = new SQLite3('/tmp/mahjong.db');
    $db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=3000;');
    $db->exec('CREATE TABLE IF NOT EXISTS records (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL,
        level INTEGER NOT NULL DEFAULT 1, score INTEGER NOT NULL DEFAULT 0,
        time_sec INTEGER NOT NULL DEFAULT 0, stars INTEGER NOT NULL DEFAULT 1,
        streak INTEGER NOT NULL DEFAULT 0, layout TEXT DEFAULT "rect",
        ip_hash TEXT DEFAULT "", created_at INTEGER NOT NULL DEFAULT (strftime("%s","now"))
    );
    CREATE INDEX IF NOT EXISTS idx_score ON records(score DESC);
    CREATE INDEX IF NOT EXISTS idx_name  ON records(name);
    CREATE TABLE IF NOT EXISTS rate_limit (ip_hash TEXT, hit_time INTEGER);');
    return $db;
}

// ════════════════════════════════════════════════════════
//  速率限制
// ════════════════════════════════════════════════════════
function checkRateLimit($ip) {
    $key = md5($ip);
    $now = time();
    try {
        if (usePostgres()) {
            $db = getPgConn();
            pg_query($db, "DELETE FROM rate_limit WHERE hit_time < NOW() - INTERVAL '1 hour'");
            $res = pg_query_params($db,
                "SELECT COUNT(*) FROM rate_limit WHERE ip_hash=$1 AND hit_time > NOW() - INTERVAL '1 minute'",
                [$key]);
            if ((int)pg_fetch_result($res,0,0) >= 10) return false;
            pg_query_params($db, "INSERT INTO rate_limit(ip_hash) VALUES($1)", [$key]);
        } else {
            $db = getSqliteDB();
            $db->exec("DELETE FROM rate_limit WHERE hit_time < " . ($now - 3600));
            $c = $db->querySingle("SELECT COUNT(*) FROM rate_limit WHERE ip_hash='$key' AND hit_time>" . ($now-60));
            if ((int)$c >= 10) return false;
            $db->exec("INSERT INTO rate_limit(ip_hash,hit_time) VALUES('$key',$now)");
        }
    } catch(Exception $e) { return true; }
    return true;
}

// ════════════════════════════════════════════════════════
//  工具函數
// ════════════════════════════════════════════════════════
function sanitizeName($n) { return mb_substr(trim(strip_tags($n??'')), 0, MAX_NAME_LEN); }
function validateInt($v,$min,$max) { $i=intval($v??0); return ($i>=$min&&$i<=$max)?$i:null; }
function json_ok($d) { echo json_encode(['ok'=>true,'data'=>$d],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit; }
function json_error($c,$m) { http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m],JSON_UNESCAPED_UNICODE); exit; }

// ════════════════════════════════════════════════════════
//  路由
// ════════════════════════════════════════════════════════
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

// ── 診斷 ──────────────────────────────────────────────
case 'ping':
    $pg = usePostgres();
    $db_ok = false;
    $db_msg = '';
    try {
        if ($pg) {
            $conn = getPgConn();
            pg_query($conn, 'SELECT 1');
            $db_ok  = true;
            $db_msg = '✓ PostgreSQL (永久儲存)';
        } else {
            getSqliteDB();
            $db_ok  = true;
            $db_msg = '⚠ SQLite /tmp (重啟後清空，排行榜無法跨裝置共享)';
        }
    } catch(Exception $e) { $db_msg = '✗ '.$e->getMessage(); }

    json_ok([
        'status'    => 'ok',
        'php'       => PHP_VERSION,
        'pg_func'   => function_exists('pg_connect'),
        'pdo_pgsql' => extension_loaded('pdo_pgsql'),
        'sqlite3'   => class_exists('SQLite3'),
        'db_type'   => $pg ? 'postgres' : 'sqlite',
        'db_ok'     => $db_ok,
        'db_msg'    => $db_msg,
        'message'   => $db_ok ? '✓ 環境正常' : '✗ DB連線失敗',
    ]);
    break;

// ── 提交記錄 ──────────────────────────────────────────
case 'submit':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405,'請使用POST');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!checkRateLimit($ip)) json_error(429,'提交過於頻繁');

    $b      = json_decode(file_get_contents('php://input'),true) ?: $_POST;
    $name   = sanitizeName($b['name']   ?? '');
    $level  = validateInt($b['level']   ?? 0, 1, 99);
    $score  = validateInt($b['score']   ?? 0, 0, 999999);
    $time   = validateInt($b['time']    ?? 0, 1, 7200);
    $stars  = validateInt($b['stars']   ?? 1, 1, 3);
    $streak = validateInt($b['streak']  ?? 0, 0, 999);
    $layout = in_array($b['layout']??'',['rect','pyramid','turtle','dragon','flower'])?$b['layout']:'rect';

    if (!$name||!$level||!$score||!$time) json_error(400,'缺少必要欄位');
    if ($score > $level*500+3000) json_error(400,'分數異常');

    $iph = md5($ip);

    if (usePostgres()) {
        $db = getPgConn();
        // 清理超量記錄
        $res = pg_query_params($db,"SELECT COUNT(*) FROM records WHERE name=$1",[$name]);
        if ((int)pg_fetch_result($res,0,0) >= MAX_RECORDS) {
            pg_query_params($db,
                "DELETE FROM records WHERE name=$1 AND id=(SELECT id FROM records WHERE name=$1 ORDER BY score ASC LIMIT 1)",
                [$name,$name]);
        }
        $res = pg_query_params($db,
            "INSERT INTO records(name,level,score,time_sec,stars,streak,layout,ip_hash) VALUES($1,$2,$3,$4,$5,$6,$7,$8) RETURNING id",
            [$name,$level,$score,$time,$stars,$streak,$layout,$iph]);
        $newId = (int)pg_fetch_result($res,0,0);
        $res2  = pg_query_params($db,"SELECT SUM(score) FROM records WHERE name=$1",[$name]);
        $tot   = (int)pg_fetch_result($res2,0,0);
        $res3  = pg_query($db,"SELECT COUNT(DISTINCT name)+1 FROM (SELECT name,SUM(score) t FROM records GROUP BY name HAVING SUM(score)>$tot) x");
        $rank  = (int)pg_fetch_result($res3,0,0);
    } else {
        $db  = getSqliteDB();
        $esc = $db->escapeString($name);
        if ((int)$db->querySingle("SELECT COUNT(*) FROM records WHERE name='$esc'") >= MAX_RECORDS)
            $db->exec("DELETE FROM records WHERE name='$esc' AND id=(SELECT id FROM records WHERE name='$esc' ORDER BY score ASC LIMIT 1)");
        $st = $db->prepare('INSERT INTO records(name,level,score,time_sec,stars,streak,layout,ip_hash) VALUES(:n,:l,:s,:t,:st,:sr,:ly,:ip)');
        $st->bindValue(':n',$name,SQLITE3_TEXT); $st->bindValue(':l',$level,SQLITE3_INTEGER);
        $st->bindValue(':s',$score,SQLITE3_INTEGER); $st->bindValue(':t',$time,SQLITE3_INTEGER);
        $st->bindValue(':st',$stars,SQLITE3_INTEGER); $st->bindValue(':sr',$streak,SQLITE3_INTEGER);
        $st->bindValue(':ly',$layout,SQLITE3_TEXT); $st->bindValue(':ip',$iph,SQLITE3_TEXT);
        $st->execute();
        $newId = (int)$db->lastInsertRowID();
        $tot   = (int)$db->querySingle("SELECT SUM(score) FROM records WHERE name='$esc'");
        $rank  = (int)$db->querySingle("SELECT COUNT(DISTINCT name)+1 FROM(SELECT name,SUM(score) t FROM records GROUP BY name HAVING t>$tot)");
    }
    json_ok(['id'=>$newId,'rank'=>$rank]);
    break;

// ── 排行榜 ────────────────────────────────────────────
case 'board':
    $tab  = $_GET['tab'] ?? 'total';
    $rows = [];

    if (usePostgres()) {
        $db = getPgConn();
        if ($tab==='total') {
            $res = pg_query($db,"SELECT name,SUM(score) ts,COUNT(*) lc,MAX(score) bs,MIN(CASE WHEN stars>=1 THEN time_sec END) bt,MAX(streak) bst FROM records GROUP BY name ORDER BY ts DESC LIMIT ".TOP_N);
            while ($r=pg_fetch_assoc($res)) $rows[]=['name'=>$r['name'],'score'=>(int)$r['ts'],'levels'=>(int)$r['lc'],'best'=>(int)$r['bs'],'bestTime'=>$r['bt']?(int)$r['bt']:null,'streak'=>(int)$r['bst']];
        } elseif ($tab==='fastest') {
            $res = pg_query($db,"SELECT name,MIN(time_sec) bt,level,stars,score FROM records WHERE stars>=1 GROUP BY name,level,stars,score ORDER BY bt ASC LIMIT ".TOP_N);
            while ($r=pg_fetch_assoc($res)) $rows[]=['name'=>$r['name'],'time'=>(int)$r['bt'],'level'=>(int)$r['level'],'stars'=>(int)$r['stars'],'score'=>(int)$r['score']];
        } elseif ($tab==='streak') {
            $res = pg_query($db,"SELECT name,MAX(streak) bs,SUM(score) ts FROM records GROUP BY name ORDER BY bs DESC LIMIT ".TOP_N);
            while ($r=pg_fetch_assoc($res)) $rows[]=['name'=>$r['name'],'streak'=>(int)$r['bs'],'score'=>(int)$r['ts']];
        } else json_error(400,'無效榜單');
        $tp = (int)pg_fetch_result(pg_query($db,"SELECT COUNT(DISTINCT name) FROM records"),0,0);
        $tg = (int)pg_fetch_result(pg_query($db,"SELECT COUNT(*) FROM records"),0,0);
    } else {
        $db = getSqliteDB();
        if ($tab==='total') {
            $res=$db->query("SELECT name,SUM(score) ts,COUNT(*) lc,MAX(score) bs,MIN(CASE WHEN stars>=1 THEN time_sec ELSE NULL END) bt,MAX(streak) bst FROM records GROUP BY name ORDER BY ts DESC LIMIT ".TOP_N);
            while($r=$res->fetchArray(SQLITE3_ASSOC)) $rows[]=['name'=>$r['name'],'score'=>(int)$r['ts'],'levels'=>(int)$r['lc'],'best'=>(int)$r['bs'],'bestTime'=>$r['bt']?(int)$r['bt']:null,'streak'=>(int)$r['bst']];
        } elseif ($tab==='fastest') {
            $res=$db->query("SELECT name,MIN(time_sec) bt,level,stars,score FROM records WHERE stars>=1 GROUP BY name ORDER BY bt ASC LIMIT ".TOP_N);
            while($r=$res->fetchArray(SQLITE3_ASSOC)) $rows[]=['name'=>$r['name'],'time'=>(int)$r['bt'],'level'=>(int)$r['level'],'stars'=>(int)$r['stars'],'score'=>(int)$r['score']];
        } elseif ($tab==='streak') {
            $res=$db->query("SELECT name,MAX(streak) bs,SUM(score) ts FROM records GROUP BY name ORDER BY bs DESC LIMIT ".TOP_N);
            while($r=$res->fetchArray(SQLITE3_ASSOC)) $rows[]=['name'=>$r['name'],'streak'=>(int)$r['bs'],'score'=>(int)$r['ts']];
        } else json_error(400,'無效榜單');
        $tp=(int)$db->querySingle("SELECT COUNT(DISTINCT name) FROM records");
        $tg=(int)$db->querySingle("SELECT COUNT(*) FROM records");
    }

    json_ok(['tab'=>$tab,'top10'=>$rows,'totalPlayers'=>$tp,'totalGames'=>$tg,'updatedAt'=>date('Y-m-d H:i:s')]);
    break;

// ── 個人查詢 ──────────────────────────────────────────
case 'my':
    $name = sanitizeName($_GET['name']??'');
    if (!$name) json_error(400,'請提供玩家名稱');

    if (usePostgres()) {
        $db  = getPgConn();
        $res = pg_query_params($db,"SELECT SUM(score) FROM records WHERE name=$1",[$name]);
        $tot = (int)pg_fetch_result($res,0,0);
        $rank= null;
        if ($tot) {
            $res2 = pg_query($db,"SELECT COUNT(DISTINCT name)+1 FROM(SELECT name,SUM(score) t FROM records GROUP BY name HAVING SUM(score)>$tot) x");
            $rank = (int)pg_fetch_result($res2,0,0);
        }
        $res3 = pg_query_params($db,"SELECT MIN(time_sec) FROM records WHERE name=$1 AND stars>=1",[$name]);
        $bt   = pg_fetch_result($res3,0,0);
    } else {
        $db  = getSqliteDB();
        $esc = $db->escapeString($name);
        $tot = (int)$db->querySingle("SELECT SUM(score) FROM records WHERE name='$esc'");
        $rank= $tot?(int)$db->querySingle("SELECT COUNT(DISTINCT name)+1 FROM(SELECT name,SUM(score) t FROM records GROUP BY name HAVING t>$tot)"):null;
        $bt  = $tot?$db->querySingle("SELECT MIN(time_sec) FROM records WHERE name='$esc' AND stars>=1"):null;
    }
    json_ok(['name'=>$name,'totalScore'=>(int)$tot,'totalRank'=>$rank,'bestTime'=>$bt?(int)$bt:null]);
    break;

// ── 統計 ──────────────────────────────────────────────
case 'stats':
    if (usePostgres()) {
        $db = getPgConn();
        $tp = (int)pg_fetch_result(pg_query($db,"SELECT COUNT(DISTINCT name) FROM records"),0,0);
        $tg = (int)pg_fetch_result(pg_query($db,"SELECT COUNT(*) FROM records"),0,0);
        $ts = (int)pg_fetch_result(pg_query($db,"SELECT COALESCE(SUM(score),0) FROM records"),0,0);
    } else {
        $db = getSqliteDB();
        $tp = (int)$db->querySingle("SELECT COUNT(DISTINCT name) FROM records");
        $tg = (int)$db->querySingle("SELECT COUNT(*) FROM records");
        $ts = (int)$db->querySingle("SELECT COALESCE(SUM(score),0) FROM records");
    }
    json_ok(['totalPlayers'=>$tp,'totalGames'=>$tg,'totalScore'=>$ts]);
    break;

default:
    json_ok(['service'=>'麻將排行榜 API','version'=>'3.0','tip'=>'?action=ping 診斷']);
}
