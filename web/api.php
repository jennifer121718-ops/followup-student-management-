<?php
declare(strict_types=1);
// PHP 8.1+ / PDO SQLite. No Composer or background service is required.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply(int $status, mixed $value): never {
    http_response_code($status);
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
function run(PDO $db, string $sql, array $args = []): PDOStatement {
    $stmt = $db->prepare($sql); $stmt->execute($args); return $stmt;
}
function userView(array $row): array {
    $result = array_intersect_key($row, array_flip(['id','email','role','name','prefecture','cohort','start_date','active']));
    $result['id'] = (int)$result['id']; $result['active'] = (int)$result['active']; return $result;
}
function passwordValue(mixed $password): string {
    if (!is_string($password) || strlen($password) < 12 || strlen($password) > 256) throw new InvalidArgumentException();
    // Prehash avoids bcrypt's 72-byte truncation and supports UTF-8 passwords.
    return hash('sha256', $password);
}
function credentials(array $data): array {
    $result = [];
    foreach (['email','name','prefecture','cohort','start_date'] as $field) {
        $value = $data[$field] ?? '';
        if (!is_string($value) || strlen($value) > 600) throw new InvalidArgumentException();
        $result[] = trim($value);
    }
    $result[0] = strtolower($result[0]);
    if (!filter_var($result[0], FILTER_VALIDATE_EMAIL) || $result[1] === '') throw new InvalidArgumentException();
    if ($result[4] !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $result[4]);
        if (!$date || $date->format('Y-m-d') !== $result[4]) throw new InvalidArgumentException();
    }
    return $result;
}
function idValue(mixed $value): int {
    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < 1) throw new InvalidArgumentException();
    return (int)$value;
}
function textValue(array $data, string $name): string {
    $value = $data[$name] ?? '';
    if (!is_string($value) || strlen($value) > 30000) throw new InvalidArgumentException();
    return $value;
}

try {
    $settings = require __DIR__ . '/settings.php';
    $private = $settings['private_dir'];
    if (!is_dir($private) && !mkdir($private, 0700, true) && !is_dir($private)) throw new RuntimeException('storage');
    $private = realpath($private);
    $public = realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
    // Refuse to store personal data anywhere under the web document root.
    if (!$private || !$public || $private === $public || str_starts_with($private, $public . DIRECTORY_SEPARATOR)) throw new RuntimeException('private storage');
    if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('PDO SQLite');
    $db = new PDO('sqlite:' . $private . '/students.sqlite3', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
    $db->exec("CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, email TEXT UNIQUE NOT NULL, password TEXT NOT NULL,role TEXT NOT NULL,name TEXT NOT NULL,prefecture TEXT DEFAULT '',cohort TEXT DEFAULT '',start_date TEXT DEFAULT '',active INTEGER DEFAULT 1);
    CREATE TABLE IF NOT EXISTS reports(student_id INTEGER REFERENCES users(id),month TEXT NOT NULL,sales REAL DEFAULT 0,gross_profit REAL DEFAULT 0,net_profit REAL DEFAULT 0,units INTEGER DEFAULT 0,target REAL DEFAULT 0,next_target REAL DEFAULT 0,current_goal TEXT DEFAULT '',next_goal TEXT DEFAULT '',activities TEXT DEFAULT '',successes TEXT DEFAULT '',challenges TEXT DEFAULT '',next_actions TEXT DEFAULT '',consultation TEXT DEFAULT '',submitted INTEGER DEFAULT 0,submitted_at TEXT,comment TEXT DEFAULT '',status TEXT DEFAULT '要確認',PRIMARY KEY(student_id,month));
    CREATE TABLE IF NOT EXISTS sessions(token TEXT PRIMARY KEY,user_id INTEGER REFERENCES users(id),csrf TEXT NOT NULL,expires INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS login_attempts(bucket TEXT PRIMARY KEY,count INTEGER NOT NULL,started INTEGER NOT NULL);");
    chmod($private . '/students.sqlite3', 0600);
    $local = PHP_SAPI === 'cli-server' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true) && in_array(parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST), ['localhost','127.0.0.1','::1'], true);
    $secure = !$local;
    if ($secure && ($_SERVER['HTTPS'] ?? '') !== 'on') reply(403, ['error'=>'HTTPSでアクセスしてください']);
    $method = $_SERVER['REQUEST_METHOD'];
    $route = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $pos = strpos($route, '/api');
    $route = $pos === false ? '' : substr($route, $pos);
    $empty = !(bool)$db->query('SELECT 1 FROM users LIMIT 1')->fetchColumn();
    $hashFile = $private . '/setup-hash.txt';
    if ($method === 'GET' && $route === '/api/setup') reply(200, ['needs_setup'=>$empty && ($local || is_file($hashFile)), 'requires_key'=>!$local]);
    if (!in_array($method, ['GET','POST'], true)) reply(405, ['error'=>'この操作は利用できません']);
    $data = [];
    if ($method === 'POST') {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $originHost = $origin ? parse_url($origin, PHP_URL_HOST) : null;
        $host = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        if ($origin && ($originHost !== $host || parse_url($origin, PHP_URL_PORT) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_PORT))) reply(403, ['error'=>'アクセスできません']);
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') reply(415, ['error'=>'JSON形式で送信してください']);
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) reply(413, ['error'=>'入力が長すぎます']);
        $body = file_get_contents('php://input', false, null, 0, 65537);
        if (strlen($body) > 65536) reply(413, ['error'=>'入力が長すぎます']);
        $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !str_starts_with(ltrim($body), '{')) throw new InvalidArgumentException();
    }
    if ($method === 'POST' && $route === '/api/setup') {
        $db->exec('BEGIN IMMEDIATE');
        if ($db->query('SELECT 1 FROM users LIMIT 1')->fetchColumn()) { $db->exec('ROLLBACK'); reply(403, ['error'=>'初期設定は完了済みです']); }
        if (!$local && (!is_file($hashFile) || !is_string($data['setup_key'] ?? null) || !hash_equals(trim(file_get_contents($hashFile)), hash('sha256', $data['setup_key'])))) { $db->exec('ROLLBACK'); reply(403, ['error'=>'初期設定キーを確認してください']); }
        $values = credentials($data);
        run($db, "INSERT INTO users(email,password,role,name) VALUES(?,?,'teacher',?)", [$values[0],password_hash(passwordValue($data['password'] ?? null),PASSWORD_DEFAULT),$values[1]]);
        $db->exec('COMMIT');
        if (is_file($hashFile)) unlink($hashFile);
        reply(200, ['ok'=>true]);
    }
    if ($method === 'POST' && $route === '/api/login') {
        $email = strtolower(trim(textValue($data,'email')));
        $bucket = hash('sha256', $email);
        $now = time();
        $db->exec('BEGIN IMMEDIATE');
        run($db,'DELETE FROM login_attempts WHERE started<?',[$now-900]);
        $attempt = run($db,'SELECT count FROM login_attempts WHERE bucket=?',[$bucket])->fetchColumn();
        if ($attempt !== false && (int)$attempt >= 20) { $db->exec('COMMIT'); reply(429,['error'=>'15分ほど待ってから再試行してください']); }
        $user = run($db,'SELECT * FROM users WHERE email=? AND active=1',[$email])->fetch();
        $password = $data['password'] ?? null;
        if (!is_string($password) || strlen($password)>256) throw new InvalidArgumentException();
        $digest = hash('sha256',$password);
        if (!$user || !password_verify($digest,$user['password'])) {
            run($db,'INSERT INTO login_attempts(bucket,count,started) VALUES(?,1,?) ON CONFLICT(bucket) DO UPDATE SET count=count+1',[$bucket,$now]);
            $db->exec('COMMIT'); reply(401,['error'=>'メールアドレスまたはパスワードが違います']);
        }
        run($db,'DELETE FROM login_attempts WHERE bucket=?',[$bucket]);
        run($db,'DELETE FROM sessions WHERE expires<?',[$now]);
        $token=bin2hex(random_bytes(32));$csrf=bin2hex(random_bytes(32));
        run($db,'INSERT INTO sessions(token,user_id,csrf,expires) VALUES(?,?,?,?)',[hash('sha256',$token),$user['id'],$csrf,$now+28800]);
        $db->exec('COMMIT');
        $cookiePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') ?: '/';
        setcookie('followup_session',$token,['expires'=>$now+28800,'path'=>$cookiePath,'secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
        reply(200,['ok'=>true]);
    }
    $token = hash('sha256', $_COOKIE['followup_session'] ?? '');
    $session = run($db,'SELECT * FROM sessions WHERE token=? AND expires>?',[$token,time()])->fetch();
    $user = $session ? run($db,'SELECT * FROM users WHERE id=? AND active=1',[$session['user_id']])->fetch() : false;
    if (!$user) reply(401,['error'=>'ログインしてください']);
    if ($method === 'GET') {
        if ($route === '/api/me') reply(200,['user'=>userView($user),'csrf'=>$session['csrf']]);
        if ($route === '/api/students') {
            if ($user['role'] !== 'teacher') reply(403,['error'=>'アクセスできません']);
            reply(200,array_map('userView',$db->query("SELECT * FROM users WHERE role='student' ORDER BY active DESC,name")->fetchAll()));
        }
        if ($route === '/api/reports') {
            if ($user['role'] === 'student') {
                if (isset($_GET['student_id']) && (string)$_GET['student_id'] !== (string)$user['id']) reply(403,['error'=>'アクセスできません']);
                $rows=run($db,'SELECT * FROM reports WHERE student_id=? ORDER BY month',[$user['id']])->fetchAll();
            } else $rows=$db->query('SELECT * FROM reports ORDER BY month')->fetchAll();
            foreach ($rows as &$row) { foreach (['student_id','units','submitted'] as $k) $row[$k]=(int)$row[$k]; foreach (['sales','gross_profit','net_profit','target','next_target'] as $k) $row[$k]=(float)$row[$k]; } unset($row);
            reply(200,$rows);
        }
        reply(404,['error'=>'見つかりません']);
    }
    if (!hash_equals($session['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) reply(403,['error'=>'操作を再試行してください']);
    if ($route === '/api/backup') {
        if ($user['role'] !== 'teacher') reply(403,['error'=>'アクセスできません']);
        $backup = tempnam($private, 'backup-');
        if ($backup === false) throw new RuntimeException('backup');
        chmod($backup,0600);
        // A reserved SQLite write lock keeps the rollback-journal DB stable while copied.
        $db->exec('BEGIN IMMEDIATE');
        $ok=copy($private.'/students.sqlite3',$backup);
        $db->exec('COMMIT');
        if (!$ok) { unlink($backup); throw new RuntimeException('backup'); }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="followup-backup-'.gmdate('Ymd-His').'.sqlite3"');
        header('Content-Length: '.filesize($backup));
        readfile($backup);unlink($backup);exit;
    }
    if ($route === '/api/logout') {
        run($db,'DELETE FROM sessions WHERE token=?',[$token]);
        setcookie('followup_session','',['expires'=>time()-3600,'path'=>rtrim(dirname($_SERVER['SCRIPT_NAME']),'/')?:'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
        reply(200,['ok'=>true]);
    }
    $teacherRoutes=['/api/students','/api/student/update','/api/student/profile','/api/student/password','/api/feedback'];
    if (in_array($route,$teacherRoutes,true) && $user['role'] !== 'teacher') reply(403,['error'=>'アクセスできません']);
    if ($route === '/api/students') {
        $values=credentials($data);
        run($db,"INSERT INTO users(email,password,role,name,prefecture,cohort,start_date) VALUES(?,?,'student',?,?,?,?)",[$values[0],password_hash(passwordValue($data['password']??null),PASSWORD_DEFAULT),...array_slice($values,1)]);
        reply(200,['ok'=>true]);
    }
    if (in_array($route,['/api/student/update','/api/student/profile','/api/student/password'],true)) {
        $id=idValue($data['id']??null);
        if (!run($db,"SELECT 1 FROM users WHERE id=? AND role='student'",[$id])->fetchColumn()) throw new InvalidArgumentException();
        if ($route === '/api/student/profile') {
            $values=credentials($data);
            run($db,"UPDATE users SET email=?,name=?,prefecture=?,cohort=?,start_date=? WHERE id=?",[...$values,$id]);
        } else {
            $db->exec('BEGIN IMMEDIATE');
            if ($route === '/api/student/update') {
                if (!is_bool($data['active']??null)) throw new InvalidArgumentException();
                run($db,'UPDATE users SET active=? WHERE id=?',[(int)$data['active'],$id]);
            } else run($db,'UPDATE users SET password=? WHERE id=?',[password_hash(passwordValue($data['password']??null),PASSWORD_DEFAULT),$id]);
            // Reactivation never resurrects a previous login session.
            run($db,'DELETE FROM sessions WHERE user_id=?',[$id]); $db->exec('COMMIT');
        }
        reply(200,['ok'=>true]);
    }
    if ($route === '/api/password') {
        $new=passwordValue($data['password']??null);
        $current=$data['current']??null;
        if (!is_string($current) || strlen($current)>256 || !password_verify(hash('sha256',$current),$user['password'])) throw new InvalidArgumentException();
        $db->exec('BEGIN IMMEDIATE');
        run($db,'UPDATE users SET password=? WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),$user['id']]);
        run($db,'DELETE FROM sessions WHERE user_id=? AND token<>?',[$user['id'],$token]);$db->exec('COMMIT');reply(200,['ok'=>true]);
    }
    if (!in_array($route,['/api/report','/api/feedback'],true)) reply(404,['error'=>'見つかりません']);
    $id=idValue($data['student_id']??$user['id']);
    if ($user['role'] === 'student' && $id !== (int)$user['id']) reply(403,['error'=>'アクセスできません']);
    if (!run($db,"SELECT 1 FROM users WHERE id=? AND role='student'",[$id])->fetchColumn()) throw new InvalidArgumentException();
    $month=$data['month']??'';
    if (!is_string($month) || !preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D',$month) || (int)substr($month,0,4)<1) throw new InvalidArgumentException();
    if ($route === '/api/feedback') {
        $status=$data['status']??'';$comment=textValue($data,'comment');
        if (!in_array($status,['順調','要確認','フォロー必要'],true)) throw new InvalidArgumentException();
        $db->exec('BEGIN IMMEDIATE');run($db,'INSERT OR IGNORE INTO reports(student_id,month) VALUES(?,?)',[$id,$month]);
        run($db,'UPDATE reports SET comment=?,status=? WHERE student_id=? AND month=?',[$comment,$status,$id,$month]);$db->exec('COMMIT');reply(200,['ok'=>true]);
    }
    $numeric=['sales','gross_profit','net_profit','units','target','next_target'];
    $text=['current_goal','next_goal','activities','successes','challenges','next_actions','consultation'];$values=[];
    foreach ($numeric as $key) {
        $n=$data[$key]??0;
        if (!is_numeric($n) || !is_finite((float)$n) || abs((float)$n)>1e12 || (in_array($key,['sales','units','target','next_target'],true) && (float)$n<0) || ($key==='units' && floor((float)$n)!=(float)$n)) throw new InvalidArgumentException();
        $values[]=(float)$n;
    }
    foreach ($text as $key) $values[]=textValue($data,$key);
    $submitted=$data['submitted']??false;if (!is_bool($submitted)) throw new InvalidArgumentException();
    $values[]=(int)$submitted;$values[]=$submitted?gmdate('Y-m-d\TH:i:s\Z'):null;$values[]=$id;$values[]=$month;
    $columns=[...$numeric,...$text,'submitted','submitted_at'];
    $db->exec('BEGIN IMMEDIATE');run($db,'INSERT OR IGNORE INTO reports(student_id,month) VALUES(?,?)',[$id,$month]);
    run($db,'UPDATE reports SET '.implode(',',array_map(fn($key)=>$key.'=?',$columns)).' WHERE student_id=? AND month=?',$values);$db->exec('COMMIT');reply(200,['ok'=>true]);
} catch (JsonException|InvalidArgumentException|TypeError $error) {
    // SQLite rolls back open transactions when the connection is closed.
    reply(400,['error'=>'入力内容を確認してください']);
} catch (PDOException $error) {
    if ($error->getCode()==='23000') reply(409,['error'=>'メールアドレスが登録済みです']);
    error_log('Followup database operation failed');reply(503,['error'=>'保存できませんでした。時間をおいて再試行してください']);
} catch (Throwable $error) {
    error_log('Followup initialization failed: '.get_class($error));reply(503,['error'=>'サーバー設定を確認してください（PHP 8.1以上・PDO SQLite・非公開保存先）']);
}
