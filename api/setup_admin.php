<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';
$u=getenv('ADMIN_BOOTSTRAP_USERNAME') ?: '';
$p=getenv('ADMIN_BOOTSTRAP_PASSWORD') ?: '';
$t=getenv('ADMIN_BOOTSTRAP_TOTP_SECRET') ?: '';
if (!$u||!$p||!$t) { http_response_code(400); exit("Set ADMIN_BOOTSTRAP_USERNAME, ADMIN_BOOTSTRAP_PASSWORD, ADMIN_BOOTSTRAP_TOTP_SECRET"); }
if (!preg_match('/^[A-Za-z0-9_.-]{3,80}$/',$u) || strlen($p)<12 || !preg_match('/^[A-Z2-7]{16,64}$/',$t)) { http_response_code(422); exit("Invalid bootstrap values"); }
$pdo=db();
$exists=$pdo->prepare("SELECT id FROM admin_accounts WHERE username=?"); $exists->execute([$u]);
if ($exists->fetchColumn()) exit("Admin already exists");
$s=$pdo->prepare("INSERT INTO admin_accounts(username,password_hash,role,totp_secret_enc) VALUES(?,?,?,?)");
$s->execute([$u,password_hash($p,PASSWORD_ARGON2ID),'super_admin',encrypt_secret($t)]);
echo "Super Admin created. Remove/disable this setup endpoint after first use.\n";
