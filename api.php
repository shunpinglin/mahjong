<?php
// ════════════════════════════════════════════════
//  麻將益智配對 — 全球排行榜 API v2.1
//  平台：Railway（SQLite3 模式，已確認可用）
//  診斷：?action=ping
// ════════════════════════════════════════════════

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); exit;
}

define('DB_PATH',      '/tmp/mahjong.db');
define('MAX_NAME_LEN', 12);
define('MAX_RECORDS',  50);
define('TOP_N',        10);

function getDB() {
    static $db = null;
    if ($db) return $db;
    try {
        $db = new SQLite3(DB_PATH);
        $db->exec('PRAGMA journal_mode=WAL;');
        $db->exec('PRAGMA busy_timeout=3000;');
        $db->exec('CREATE TABLE IF NOT EXISTS records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL, level INTEGER NOT NULL DEFAULT 1,
            score INTEGER NOT NULL DEFAULT 0, time_sec INTEGER NOT NULL DEFAULT 0,
            stars INTEGER NOT NULL DEFAULT 1, streak INTEGER NOT NULL DEFAULT 0,
            layout TEXT DEFAULT "rect", ip_hash TEXT DEFAULT "",
            created_at INTEGER NOT NULL DEFAULT (strftime("%s","now"))
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_score ON records(score DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_time  ON records(time_sec ASC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_name  ON records(name)');
        $db->exec('CREATE TABLE IF NOT EXISTS rate_limit (ip_hash TEXT, hit_time INTEGER)');
        return $db;
    } catch (Exception $e) { json_error(500,'DB錯誤：'.$e->getMessage()); }
}

function checkRateLimit($ip) {
    try {
        $db=$getDB=getDB(); $now=time(); $key=md5($ip);
        $db->exec("DELETE FROM rate_limit WHERE hit_time<".($now-3600));
        $c=(int)$db->querySingle("SELECT COUNT(*) FROM rate_limit WHERE ip_hash='$key' AND hit_time>".($now-60));
        if($c>=10) return false;
        $db->exec("INSERT INTO rate_limit(ip_hash,hit_time) VALUES('$key',$now)");
        return true;
    } catch(Exception $e){return true;}
}

function sanitizeName($n){return mb_substr(trim(strip_tags($n??'')),0,MAX_NAME_LEN);}
function validateInt($v,$min,$max){$i=intval($v??0);return($i>=$min&&$i<=$max)?$i:null;}
function json_ok($d){echo json_encode(['ok'=>true,'data'=>$d],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;}
function json_error($c,$m){http_response_code($c);echo json_encode(['ok'=>false,'error'=>$m],JSON_UNESCAPED_UNICODE);exit;}

$action=$_GET['action']??$_POST['action']??'';

switch($action){

case 'ping':
    getDB();
    json_ok(['status'=>'ok','php'=>PHP_VERSION,'sqlite3'=>class_exists('SQLite3'),
        'writable'=>is_writable(dirname(DB_PATH)),'message'=>'✓ 環境正常！Railway SQLite3 模式']);
    break;

case 'submit':
    if($_SERVER['REQUEST_METHOD']!=='POST') json_error(405,'請使用POST');
    $ip=$_SERVER['REMOTE_ADDR']??'0.0.0.0';
    if(!checkRateLimit($ip)) json_error(429,'提交過於頻繁');
    $b=json_decode(file_get_contents('php://input'),true)?:$_POST;
    $name=sanitizeName($b['name']??''); $level=validateInt($b['level']??0,1,99);
    $score=validateInt($b['score']??0,0,999999); $time=validateInt($b['time']??0,1,7200);
    $stars=validateInt($b['stars']??1,1,3); $streak=validateInt($b['streak']??0,0,999);
    $layout=in_array($b['layout']??'',['rect','pyramid','turtle','dragon','flower'])?$b['layout']:'rect';
    if(!$name||!$level||!$score||!$time) json_error(400,'缺少必要欄位');
    if($score>$level*500+3000) json_error(400,'分數異常');
    $db=getDB(); $esc=$db->escapeString($name);
    if((int)$db->querySingle("SELECT COUNT(*) FROM records WHERE name='$esc'")>=MAX_RECORDS)
        $db->exec("DELETE FROM records WHERE name='$esc' AND id=(SELECT id FROM records WHERE name='$esc' ORDER BY score ASC LIMIT 1)");
    $st=$db->prepare('INSERT INTO records(name,level,score,time_sec,stars,streak,layout,ip_hash) VALUES(:n,:l,:s,:t,:st,:sr,:ly,:ip)');
    $st->bindValue(':n',$name,SQLITE3_TEXT); $st->bindValue(':l',$level,SQLITE3_INTEGER);
    $st->bindValue(':s',$score,SQLITE3_INTEGER); $st->bindValue(':t',$time,SQLITE3_INTEGER);
    $st->bindValue(':st',$stars,SQLITE3_INTEGER); $st->bindValue(':sr',$streak,SQLITE3_INTEGER);
    $st->bindValue(':ly',$layout,SQLITE3_TEXT); $st->bindValue(':ip',md5($ip),SQLITE3_TEXT);
    $st->execute();
    $tot=(int)$db->querySingle("SELECT SUM(score) FROM records WHERE name='$esc'");
    $rank=(int)$db->querySingle("SELECT COUNT(DISTINCT name)+1 FROM(SELECT name,SUM(score) t FROM records GROUP BY name HAVING t>$tot)");
    json_ok(['id'=>(int)$db->lastInsertRowID(),'rank'=>$rank]);
    break;

case 'board':
    $tab=$_GET['tab']??'total'; $db=getDB(); $rows=[];
    if($tab==='total'){
        $res=$db->query("SELECT name,SUM(score) ts,COUNT(*) lc,MAX(score) bs,MIN(CASE WHEN stars>=1 THEN time_sec ELSE NULL END) bt,MAX(streak) bst FROM records GROUP BY name ORDER BY ts DESC LIMIT ".TOP_N);
        while($r=$res->fetchArray(SQLITE3_ASSOC)) $rows[]=['name'=>$r['name'],'score'=>(int)$r['ts'],'levels'=>(int)$r['lc'],'best'=>(int)$r['bs'],'bestTime'=>$r['bt']?(int)$r['bt']:null,'streak'=>(int)$r['bst']];
    } elseif($tab==='fastest'){
        $res=$db->query("SELECT name,MIN(time_sec) bt,level,stars,score FROM records WHERE stars>=1 GROUP BY name ORDER BY bt ASC LIMIT ".TOP_N);
        while($r=$res->fetchArray(SQLITE3_ASSOC)) $rows[]=['name'=>$r['name'],'time'=>(int)$r['bt'],'level'=>(int)$r['level'],'stars'=>(int)$r['stars'],'score'=>(int)$r['score']];
    } elseif($tab==='streak'){
        $res=$db->query("SELECT name,MAX(streak) bs,SUM(score) ts FROM records GROUP BY name ORDER BY bs DESC LIMIT ".TOP_N);
        while($r=$res->fetchArray(SQLITE3_ASSOC)) $rows[]=['name'=>$r['name'],'streak'=>(int)$r['bs'],'score'=>(int)$r['ts']];
    } else json_error(400,'無效榜單');
    $tp=(int)$db->querySingle("SELECT COUNT(DISTINCT name) FROM records");
    $tg=(int)$db->querySingle("SELECT COUNT(*) FROM records");
    json_ok(['tab'=>$tab,'top10'=>$rows,'totalPlayers'=>$tp,'totalGames'=>$tg,'updatedAt'=>date('Y-m-d H:i:s')]);
    break;

case 'my':
    $name=sanitizeName($_GET['name']??''); if(!$name) json_error(400,'請提供玩家名稱');
    $db=getDB(); $esc=$db->escapeString($name);
    $tot=(int)$db->querySingle("SELECT SUM(score) FROM records WHERE name='$esc'");
    $rank=$tot?(int)$db->querySingle("SELECT COUNT(DISTINCT name)+1 FROM(SELECT name,SUM(score) t FROM records GROUP BY name HAVING t>$tot)"):null;
    $bt=$tot?$db->querySingle("SELECT MIN(time_sec) FROM records WHERE name='$esc' AND stars>=1"):null;
    json_ok(['name'=>$name,'totalScore'=>$tot,'totalRank'=>$rank,'bestTime'=>$bt?(int)$bt:null]);
    break;

case 'stats':
    $db=getDB();
    json_ok(['totalPlayers'=>(int)$db->querySingle("SELECT COUNT(DISTINCT name) FROM records"),
        'totalGames'=>(int)$db->querySingle("SELECT COUNT(*) FROM records"),
        'totalScore'=>(int)$db->querySingle("SELECT COALESCE(SUM(score),0) FROM records")]);
    break;

default:
    json_ok(['service'=>'麻將排行榜 API','version'=>'2.1','tip'=>'?action=ping 診斷']);
}
