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
    $result = array_intersect_key($row, array_flip(['id','email','role','name','prefecture','cohort','start_date','active','locked']));
    $result['id'] = (int)$result['id']; $result['active'] = (int)$result['active']; $result['locked'] = (int)$result['locked']; return $result;
}
function passwordValue(mixed $password): string {
    if (!is_string($password) || preg_match_all('/./us', $password) < 8 || strlen($password) > 256 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) throw new InvalidArgumentException();
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

function queueNotification(PDO $db, array $settings, int $id, string $kind, string $period, string $old, string $comment): ?int {
    if (trim($comment)==='' || trim($comment)===trim($old)) return null;
    $student=run($db,"SELECT email FROM users WHERE id=? AND role='student' AND active=1",[$id])->fetch();
    if (!$student) return null;
    $label=$kind==='weekly'?'週報':'月報';
    $message="講師から".$label."へのコメントが届きました。\n\n下記のページにログインして、講師コメントをご確認ください。\n".$settings['app_url']."\n\nこのメールにはコメント本文を記載していません。\n";
    run($db,"INSERT INTO notifications(student_id,kind,period,recipient,subject,message,transport,state,created_at) VALUES(?,?,?,?,?,?,?,'pending',?)",[$id,$kind,$period,$student['email'],'【Followup】講師からコメントが届きました',$message,$settings['notification_transport'],time()]);
    return (int)$db->lastInsertId();
}

function sendNotification(PDO $db, array $settings, string $private, int $id): array {
    $event=run($db,'SELECT n.*,u.active,u.email FROM notifications n JOIN users u ON u.id=n.student_id WHERE n.id=?',[$id])->fetch();
    if (!$event) throw new InvalidArgumentException();
    if (!$event['active']) return ['id'=>$id,'state'=>'inactive'];
    // A changed address must receive the notification at its current binding.
    $recipient=$event['email'];
    if ($event['state']==='sent') return ['id'=>$id,'state'=>'sent'];
    $claimed=run($db,"UPDATE notifications SET state='sending',attempted_at=?,attempts=attempts+1,recipient=? WHERE id=? AND (state IN ('pending','failed') OR (state='sending' AND attempted_at<?))",[time(),$recipient,$id,time()-300])->rowCount();
    if (!$claimed) return ['id'=>$id,'state'=>'sending'];
    $success=deliverEmail($settings,$private,$recipient,$event['subject'],$event['message'],(string)$id);
    run($db,'UPDATE notifications SET state=?,sent_at=? WHERE id=?',[$success?'sent':'failed',$success?time():null,$id]);
    return ['id'=>$id,'state'=>$success?'sent':'failed','transport'=>$settings['notification_transport']];
}

function deliverEmail(array $settings, string $private, string $recipient, string $subject, string $message, string $reference): bool {
    try {
        $from=$settings['notification_from'];
        if (!filter_var($from,FILTER_VALIDATE_EMAIL) || !filter_var($recipient,FILTER_VALIDATE_EMAIL) || !str_starts_with($settings['app_url'],'https://')) return false;
        if ($settings['notification_transport']==='file' && PHP_SAPI==='cli-server') {
            if (getenv('FOLLOWUP_MAIL_TEST_FAIL')==='1' || is_file($private.'/simulate-mail-failure')) return false;
            $dir=$private.'/mail-preview';
            if (!is_dir($dir) && !mkdir($dir,0700)) return false;
            $file=$dir.'/'.$reference.'.json';
            $ok=file_put_contents($file,json_encode(['from'=>$from,'to'=>$recipient,'subject'=>$subject,'message'=>$message],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)!==false;
            if ($ok) chmod($file,0600);
            return $ok;
        }
        if ($settings['notification_transport']!=='mail') return false;
        $encoded='=?UTF-8?B?'.base64_encode($subject).'?=';
        $headers="From: Followup <".$from.">\r\nReply-To: ".$from."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64";
        return @mail($recipient,$encoded,chunk_split(base64_encode($message)),$headers,'-f'.escapeshellarg($from));
    } catch (Throwable $error) { return false; }
}

try {
    $settings = array_replace(['notification_from'=>'info@hmr-and-co.com','app_url'=>'https://hmr-and-co.com/followup/','notification_transport'=>PHP_SAPI==='cli-server'?'file':'mail'],require __DIR__ . '/settings.php');
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
    CREATE TABLE IF NOT EXISTS weekly_reports(student_id INTEGER REFERENCES users(id),week_start TEXT NOT NULL,activities TEXT DEFAULT '',challenges TEXT DEFAULT '',next_actions TEXT DEFAULT '',condition TEXT DEFAULT '順調',submitted INTEGER DEFAULT 0,submitted_at TEXT,comment TEXT DEFAULT '',status TEXT DEFAULT '要確認',PRIMARY KEY(student_id,week_start));
    CREATE TABLE IF NOT EXISTS notifications(id INTEGER PRIMARY KEY,student_id INTEGER REFERENCES users(id),kind TEXT NOT NULL,period TEXT NOT NULL,recipient TEXT NOT NULL,subject TEXT NOT NULL,message TEXT NOT NULL,transport TEXT NOT NULL,state TEXT NOT NULL,attempts INTEGER DEFAULT 0,created_at INTEGER NOT NULL,attempted_at INTEGER,sent_at INTEGER);
    CREATE TABLE IF NOT EXISTS password_resets(token TEXT PRIMARY KEY,user_id INTEGER REFERENCES users(id),expires INTEGER NOT NULL,used_at INTEGER);
    CREATE TABLE IF NOT EXISTS reset_requests(bucket TEXT PRIMARY KEY,count INTEGER NOT NULL,started INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS sessions(token TEXT PRIMARY KEY,user_id INTEGER REFERENCES users(id),csrf TEXT NOT NULL,expires INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS login_attempts(bucket TEXT PRIMARY KEY,count INTEGER NOT NULL,started INTEGER NOT NULL);");
    $columns=array_column($db->query('PRAGMA table_info(users)')->fetchAll(),'name');
    foreach (['locked','failed_attempts'] as $column) {
        if (!in_array($column,$columns,true)) {
            try { $db->exec('ALTER TABLE users ADD COLUMN '.$column.' INTEGER NOT NULL DEFAULT 0'); }
            catch (PDOException $error) { if (!in_array($column,array_column($db->query('PRAGMA table_info(users)')->fetchAll(),'name'),true)) throw $error; }
        }
    }
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
    if ($method==='POST' && $route==='/api/password/forgot') {
        $generic=['ok'=>true,'message'=>'アドレスが登録されている場合、再設定メールを送信します。届かない場合は迷惑メールフォルダを確認するか講師へご連絡ください。'];
        $email=strtolower(trim(textValue($data,'email')));
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) reply(200,$generic);
        $now=time();$buckets=['email:'.hash('sha256',$email)=>3,'ip:'.hash('sha256',$_SERVER['REMOTE_ADDR']??'')=>10];
        $db->exec('BEGIN IMMEDIATE');
        run($db,'DELETE FROM reset_requests WHERE started<?',[$now-3600]);
        foreach ($buckets as $bucket=>$limit) {
            $count=run($db,'SELECT count FROM reset_requests WHERE bucket=?',[$bucket])->fetchColumn();
            if ($count!==false && (int)$count >= $limit) { $db->exec('COMMIT');reply(200,$generic); }
        }
        foreach ($buckets as $bucket=>$limit) run($db,'INSERT INTO reset_requests(bucket,count,started) VALUES(?,1,?) ON CONFLICT(bucket) DO UPDATE SET count=count+1',[$bucket,$now]);
        $target=run($db,'SELECT id,email FROM users WHERE email=? AND active=1',[$email])->fetch();
        $reset=null;
        if ($target) {
            $reset=bin2hex(random_bytes(32));
            run($db,'DELETE FROM password_resets WHERE user_id=? OR expires<?',[$target['id'],$now]);
            run($db,'INSERT INTO password_resets(token,user_id,expires) VALUES(?,?,?)',[hash('sha256',$reset),$target['id'],$now+3600]);
        }
        $db->exec('COMMIT');
        if ($target && $reset) {
            $link=$settings['app_url'].'#reset='.$reset;
            $message="パスワード再設定の依頼を受け付けました。\n\n次のリンクから新しいパスワードを設定してください。リンクは1時間有効で、一度だけ使用できます。\n".$link."\n\n心当たりがなければ操作する必要はありません。\nアカウントがロックされている場合、パスワード変更後も講師によるロック解除が必要です。\n";
            deliverEmail($settings,$private,$target['email'],'【Followup】パスワードの再設定',$message,'reset-'.bin2hex(random_bytes(8)));
        }
        reply(200,$generic);
    }
    if ($method==='POST' && $route==='/api/password/reset') {
        $reset=$data['token']??'';
        if (!is_string($reset) || !preg_match('/^[a-f0-9]{64}$/D',$reset)) reply(400,['error'=>'再設定リンクが無効です。新しく再設定メールを依頼してください']);
        $password=passwordValue($data['password']??null);
        $db->exec('BEGIN IMMEDIATE');
        $target=run($db,'SELECT r.*,u.locked,u.email FROM password_resets r JOIN users u ON u.id=r.user_id WHERE r.token=? AND r.used_at IS NULL AND r.expires>? AND u.active=1',[hash('sha256',$reset),time()])->fetch();
        if (!$target) { $db->exec('ROLLBACK');reply(400,['error'=>'再設定リンクが期限切れ、または使用済みです。新しく再設定メールを依頼してください']); }
        run($db,'UPDATE users SET password=?,failed_attempts=CASE WHEN locked=0 THEN 0 ELSE failed_attempts END WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$target['user_id']]);
        run($db,'UPDATE password_resets SET used_at=? WHERE user_id=?',[time(),$target['user_id']]);
        run($db,'DELETE FROM sessions WHERE user_id=?',[$target['user_id']]);
        if (!$target['locked']) run($db,'DELETE FROM login_attempts WHERE bucket=?',[hash('sha256',$target['email'])]);
        $db->exec('COMMIT');reply(200,['ok'=>true,'locked'=>(bool)$target['locked']]);
    }
    if ($method === 'POST' && $route === '/api/login') {
        $email = strtolower(trim(textValue($data,'email')));
        $password = $data['password'] ?? null;
        if (!is_string($password) || strlen($password)>256) throw new InvalidArgumentException();
        $bucket = hash('sha256', $email);$now=time();
        $db->exec('BEGIN IMMEDIATE');
        $user=run($db,'SELECT * FROM users WHERE email=? AND active=1',[$email])->fetch();
        if ($user && $user['locked']) { $db->exec('COMMIT');reply(423,['error'=>'アカウントがロックされています。講師に解除を依頼してください']); }
        run($db,'DELETE FROM login_attempts WHERE started<?',[$now-900]);
        $attempt=run($db,'SELECT count FROM login_attempts WHERE bucket=?',[$bucket])->fetchColumn();
        if (!$user && $attempt!==false && (int)$attempt >= 10) { $db->exec('COMMIT');reply(429,['error'=>'しばらく待ってから再試行してください']); }
        if (!$user || !password_verify(hash('sha256',$password),$user['password'])) {
            run($db,'INSERT INTO login_attempts(bucket,count,started) VALUES(?,1,?) ON CONFLICT(bucket) DO UPDATE SET count=count+1',[$bucket,$now]);
            $locked=false;
            if ($user) {
                $failures=(int)$user['failed_attempts']+1;$locked=$failures>=10;
                run($db,'UPDATE users SET failed_attempts=?,locked=? WHERE id=?',[$failures,(int)$locked,$user['id']]);
                if ($locked) run($db,'DELETE FROM sessions WHERE user_id=?',[$user['id']]);
            }
            $db->exec('COMMIT');
            if ($locked) reply(423,['error'=>'10回連続で間違えたためロックしました。講師に解除を依頼してください']);
            reply(401,['error'=>'メールアドレスまたはパスワードが違います']);
        }
        run($db,'UPDATE users SET failed_attempts=0 WHERE id=?',[$user['id']]);
        run($db,'DELETE FROM login_attempts WHERE bucket=?',[$bucket]);
        run($db,'DELETE FROM sessions WHERE expires<?',[$now]);
        $token=bin2hex(random_bytes(32));$csrf=bin2hex(random_bytes(32));
        run($db,'INSERT INTO sessions(token,user_id,csrf,expires) VALUES(?,?,?,?)',[hash('sha256',$token),$user['id'],$csrf,$now+28800]);
        $db->exec('COMMIT');
        $cookiePath=rtrim(dirname($_SERVER['SCRIPT_NAME']),'/')?:'/';
        setcookie('followup_session',$token,['expires'=>$now+28800,'path'=>$cookiePath,'secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
        reply(200,['ok'=>true]);
    }
    $token = hash('sha256', $_COOKIE['followup_session'] ?? '');
    $session = run($db,'SELECT * FROM sessions WHERE token=? AND expires>?',[$token,time()])->fetch();
    $user = $session ? run($db,'SELECT * FROM users WHERE id=? AND active=1 AND locked=0',[$session['user_id']])->fetch() : false;
    if (!$user) reply(401,['error'=>'ログインしてください']);
    if ($method === 'GET') {
        if ($route === '/api/me') reply(200,['user'=>userView($user),'csrf'=>$session['csrf']]);
        if ($route === '/api/notifications') {
            if ($user['role']!=='teacher') reply(403,['error'=>'アクセスできません']);
            $rows=$db->query('SELECT id,student_id,kind,period,state,transport,created_at,attempted_at,sent_at FROM notifications ORDER BY id DESC')->fetchAll();
            foreach ($rows as &$row) { $row['id']=(int)$row['id'];$row['student_id']=(int)$row['student_id']; } unset($row);
            reply(200,$rows);
        }
        if ($route === '/api/weekly') {
            if ($user['role'] === 'student') {
                if (isset($_GET['student_id']) && (string)$_GET['student_id'] !== (string)$user['id']) reply(403,['error'=>'アクセスできません']);
                $rows=run($db,'SELECT * FROM weekly_reports WHERE student_id=? ORDER BY week_start',[$user['id']])->fetchAll();
            } else $rows=$db->query('SELECT * FROM weekly_reports ORDER BY week_start')->fetchAll();
            foreach ($rows as &$row) { $row['student_id']=(int)$row['student_id'];$row['submitted']=(int)$row['submitted']; } unset($row);
            reply(200,$rows);
        }
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
    if ($route==='/api/student/unlock') {
        if ($user['role']!=='teacher') reply(403,['error'=>'アクセスできません']);
        $id=idValue($data['id']??null);
        $target=run($db,"SELECT email FROM users WHERE id=? AND role='student'",[$id])->fetch();
        if (!$target) throw new InvalidArgumentException();
        $db->exec('BEGIN IMMEDIATE');
        run($db,'UPDATE users SET locked=0,failed_attempts=0 WHERE id=?',[$id]);
        run($db,'DELETE FROM login_attempts WHERE bucket=?',[hash('sha256',$target['email'])]);
        $db->exec('COMMIT');reply(200,['ok'=>true]);
    }
    if ($route === '/api/notification/retry') {
        if ($user['role']!=='teacher') reply(403,['error'=>'アクセスできません']);
        $id=idValue($data['id']??null);
        reply(200,['ok'=>true,'notification'=>sendNotification($db,$settings,$private,$id)]);
    }
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
    $teacherRoutes=['/api/students','/api/student/update','/api/student/profile','/api/student/password','/api/feedback','/api/weekly-feedback'];
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
            run($db,'DELETE FROM password_resets WHERE user_id=?',[$id]);
        } else {
            $db->exec('BEGIN IMMEDIATE');
            if ($route === '/api/student/update') {
                if (!is_bool($data['active']??null)) throw new InvalidArgumentException();
                run($db,'UPDATE users SET active=? WHERE id=?',[(int)$data['active'],$id]);
            } else run($db,'UPDATE users SET password=? WHERE id=?',[password_hash(passwordValue($data['password']??null),PASSWORD_DEFAULT),$id]);
            run($db,'DELETE FROM password_resets WHERE user_id=?',[$id]);
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
        run($db,'DELETE FROM password_resets WHERE user_id=?',[$user['id']]);
        run($db,'DELETE FROM sessions WHERE user_id=? AND token<>?',[$user['id'],$token]);$db->exec('COMMIT');reply(200,['ok'=>true]);
    }
    if (in_array($route,['/api/weekly','/api/weekly-feedback'],true)) {
        $id=idValue($data['student_id']??$user['id']);
        if ($user['role']==='student' && $id !== (int)$user['id']) reply(403,['error'=>'アクセスできません']);
        if (!run($db,"SELECT 1 FROM users WHERE id=? AND role='student'",[$id])->fetchColumn()) throw new InvalidArgumentException();
        $week=$data['week_start']??'';
        if (!is_string($week)) throw new InvalidArgumentException();
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$week);
        if (!$date || $date->format('Y-m-d')!==$week || $date->format('N')!=='1') throw new InvalidArgumentException();
        if ($route==='/api/weekly-feedback') {
            $status=$data['status']??'';$comment=textValue($data,'comment');
            if (!in_array($status,['順調','要確認','フォロー必要'],true)) throw new InvalidArgumentException();
            $db->exec('BEGIN IMMEDIATE');
            $old=run($db,'SELECT comment FROM weekly_reports WHERE student_id=? AND week_start=?',[$id,$week])->fetchColumn();
            run($db,'INSERT OR IGNORE INTO weekly_reports(student_id,week_start) VALUES(?,?)',[$id,$week]);
            run($db,'UPDATE weekly_reports SET comment=?,status=? WHERE student_id=? AND week_start=?',[$comment,$status,$id,$week]);
            $notification=queueNotification($db,$settings,$id,'weekly',$week,$old===false?'':$old,$comment);
            $db->exec('COMMIT');
            reply(200,['ok'=>true,'notification'=>$notification?sendNotification($db,$settings,$private,$notification):null]);
        } else {
            $activities=textValue($data,'activities');$challenges=textValue($data,'challenges');$actions=textValue($data,'next_actions');
            $condition=$data['condition']??'順調';$submitted=$data['submitted']??false;
            if (!in_array($condition,['順調','少し困っている','相談したい'],true) || !is_bool($submitted)) throw new InvalidArgumentException();
            $db->exec('BEGIN IMMEDIATE');
            run($db,'INSERT OR IGNORE INTO weekly_reports(student_id,week_start) VALUES(?,?)',[$id,$week]);
            run($db,'UPDATE weekly_reports SET activities=?,challenges=?,next_actions=?,condition=?,submitted=?,submitted_at=? WHERE student_id=? AND week_start=?',[$activities,$challenges,$actions,$condition,(int)$submitted,$submitted?gmdate('Y-m-d\TH:i:s\Z'):null,$id,$week]);
        }
        $db->exec('COMMIT');reply(200,['ok'=>true]);
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
        $db->exec('BEGIN IMMEDIATE');
        $old=run($db,'SELECT comment FROM reports WHERE student_id=? AND month=?',[$id,$month])->fetchColumn();
        run($db,'INSERT OR IGNORE INTO reports(student_id,month) VALUES(?,?)',[$id,$month]);
        run($db,'UPDATE reports SET comment=?,status=? WHERE student_id=? AND month=?',[$comment,$status,$id,$month]);
        $notification=queueNotification($db,$settings,$id,'monthly',$month,$old===false?'':$old,$comment);
        $db->exec('COMMIT');
        reply(200,['ok'=>true,'notification'=>$notification?sendNotification($db,$settings,$private,$notification):null]);
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
