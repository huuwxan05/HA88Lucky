<?php
declare(strict_types=1);

function envv(string $k, ?string $d=null): ?string { $v=getenv($k); return ($v===false||$v==='')?$d:$v; }

header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; connect-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Content-Type: application/json; charset=utf-8');

function json_out(array $data,int $status=200): never { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function db(): PDO {
    static $pdo=null; if($pdo instanceof PDO)return $pdo;
    $url=envv('DATABASE_URL'); if(!$url) throw new RuntimeException('DATABASE_URL is not configured');
    $pdo=new PDO($url,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]); return $pdo;
}
function body(): array { $d=json_decode(file_get_contents('php://input')?:'',true); return is_array($d)?$d:[]; }
function require_post(): void { if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')json_out(['error'=>'method_not_allowed'],405); }
function start_session(string $name): void {
    if(session_status()===PHP_SESSION_ACTIVE)return;
    session_name($name);
    session_start(['cookie_httponly'=>true,'cookie_secure'=>true,'cookie_samesite'=>'Strict','use_strict_mode'=>true,'cookie_path'=>'/']);
}
function csrf_token(): string { if(session_status()!==PHP_SESSION_ACTIVE)start_session('__Host-ha88_csrf'); if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function require_csrf(): void { $t=$_SERVER['HTTP_X_CSRF_TOKEN']??''; if(!$t||empty($_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],$t))json_out(['error'=>'csrf_failed'],403); }
function admin_session(): array {
    start_session('__Host-ha88_admin');
    if(!empty($_SESSION['admin'])){
        $now=time();
        if(!empty($_SESSION['admin_idle'])&&$now-(int)$_SESSION['admin_idle']>1800){$_SESSION=[];session_destroy();json_out(['error'=>'session_expired'],401);}
        if(!empty($_SESSION['admin_login_at'])&&$now-(int)$_SESSION['admin_login_at']>28800){$_SESSION=[];session_destroy();json_out(['error'=>'session_expired'],401);}
        $_SESSION['admin_idle']=$now;
    }
    return $_SESSION['admin']??[];
}
function require_admin(): array { $a=admin_session(); if(!$a||empty($a['id']))json_out(['error'=>'admin_auth_required'],401); return $a; }
function player_session(): array { start_session('__Host-ha88_player'); return $_SESSION['player']??[]; }
function require_player(): array { $p=player_session(); if(!$p||empty($p['id']))json_out(['error'=>'player_auth_required'],401); return $p; }
function audit(int $adminId,string $action,string $scope,?string $target=null,$old=null,$new=null,?string $reason=null):void{
 $s=db()->prepare('INSERT INTO admin_audit_log(admin_id,action,scope,target_id,old_value,new_value,reason) VALUES(?,?,?,?,?,?,?)');
 $s->execute([$adminId,$action,$scope,$target,$old===null?null:json_encode($old),$new===null?null:json_encode($new),$reason]);
}
function require_role(array $a,array $roles):void{if(!in_array(($a['role']??''),$roles,true))json_out(['error'=>'forbidden'],403);}
function client_ip():string{return substr($_SERVER['REMOTE_ADDR']??'0.0.0.0',0,64);}
function totp_key():string{$raw=getenv('APP_ADMIN_TOTP_KEY')?:'';$k=base64_decode($raw,true);if($k===false||strlen($k)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new RuntimeException('APP_ADMIN_TOTP_KEY is not configured correctly');return $k;}
function encrypt_secret(string $plain):string{$n=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);return base64_encode($n.sodium_crypto_secretbox($plain,$n,totp_key()));}
function decrypt_secret(string $packed):string{$r=base64_decode($packed,true);if($r===false||strlen($r)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new RuntimeException('Invalid encrypted secret');$n=substr($r,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$p=sodium_crypto_secretbox_open(substr($r,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$n,totp_key());if($p===false)throw new RuntimeException('Unable to decrypt secret');return $p;}
function totp_base32_decode(string $s):string{$s=strtoupper(preg_replace('/[^A-Z2-7]/','',$s));$map='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';for($i=0;$i<strlen($s);$i++){ $v=strpos($map,$s[$i]);if($v!==false)$bits.=str_pad(decbin($v),5,'0',STR_PAD_LEFT);} $o='';for($i=0;$i+8<=strlen($bits);$i+=8)$o.=chr(bindec(substr($bits,$i,8)));return $o;}
function verify_totp(string $secret,string $code):bool{if(!preg_match('/^\d{6}$/',$code))return false;$key=totp_base32_decode($secret);$c=(int)floor(time()/30);for($d=-1;$d<=1;$d++){ $x=$c+$d;$bin=pack('N2',($x>>32)&0xffffffff,$x&0xffffffff);$h=hash_hmac('sha1',$bin,$key,true);$o=ord($h[19])&15;$n=((ord($h[$o])&127)<<24)|((ord($h[$o+1])&255)<<16)|((ord($h[$o+2])&255)<<8)|(ord($h[$o+3])&255);if(hash_equals(str_pad((string)($n%1000000),6,'0',STR_PAD_LEFT),$code))return true;}return false;}
