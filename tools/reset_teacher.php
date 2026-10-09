<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$path=$argv[1]??'';
if (!is_file($path) || !stream_isatty(STDIN)) exit("SSHの対話端末で php reset_teacher.php /非公開保存先/students.sqlite3 を実行してください。\n");
fwrite(STDOUT,"講師メールアドレス: ");$email=strtolower(trim(fgets(STDIN)));
fwrite(STDOUT,"新しいパスワード（8文字以上・英大文字／英小文字／数字を含む）: ");
system('stty -echo',$status);
if ($status !== 0) exit("安全なパスワード入力を開始できませんでした。\n");
try { $password=rtrim(fgets(STDIN),"\r\n"); }
finally { system('stty echo'); fwrite(STDOUT,"\n"); }
if (preg_match_all('/./us', $password)<8 || strlen($password)>256 || !preg_match('/[A-Z]/',$password) || !preg_match('/[a-z]/',$password) || !preg_match('/[0-9]/',$password)) exit("8文字以上で、英大文字・英小文字・数字を含めてください。\n");
$db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$stmt=$db->prepare("SELECT id FROM users WHERE email=? AND role='teacher'");$stmt->execute([$email]);$id=$stmt->fetchColumn();
if (!$id) exit("講師アカウントが見つかりません。\n");
$db->beginTransaction();
$stmt=$db->prepare('UPDATE users SET password=? WHERE id=?');$stmt->execute([password_hash(hash('sha256',$password),PASSWORD_DEFAULT),$id]);
$stmt=$db->prepare('DELETE FROM sessions WHERE user_id=?');$stmt->execute([$id]);$db->commit();
fwrite(STDOUT,"パスワードを更新しました。既存のログインは無効になりました。\n");
