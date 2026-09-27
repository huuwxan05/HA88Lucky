<?php
/**
 * HA88Lucky Payment Demo/Sandbox module.
 * Intended to be merged into the existing api/index.php router.
 * Real-money settlement is intentionally NOT implemented here.
 */

declare(strict_types=1);

function payment_require_admin(PDO $pdo, array $admin, array $roles=['super_admin']): void {
    if (!in_array((string)($admin['role'] ?? ''), $roles, true)) {
        json_out(['error'=>'forbidden'], 403);
    }
}

function payment_order_code(string $prefix='NAP'): string {
    return $prefix . date('ymdHis') . strtoupper(bin2hex(random_bytes(3)));
}

function payment_qr_url(array $a, int $amount, string $content): string {
    // VietQR Quick Link format. Replace DEMO account with the admin-configured account.
    $bank = rawurlencode((string)$a['bank_code']);
    $no = rawurlencode((string)$a['account_number']);
    $template = rawurlencode((string)$a['qr_template']);
    return 'https://img.vietqr.io/image/'.$bank.'-'.$no.'-'.$template.'.png'
      .'?amount='.rawurlencode((string)$amount)
      .'&addInfo='.rawurlencode($content)
      .'&accountName='.rawurlencode((string)$a['account_name']);
}

function payment_routes(PDO $pdo, ?array $admin, ?array $player, string $action, string $method): bool {
    if ($action === 'payment_account_list') {
        if (!$admin) json_out(['error'=>'unauthorized'], 401);
        payment_require_admin($pdo, $admin);
        $rows = $pdo->query("SELECT id,bank_code,bank_name,account_name,account_number,bin,qr_template,transfer_prefix,active,created_at,updated_at FROM payment_accounts ORDER BY active DESC,id DESC")->fetchAll();
        json_out(['ok'=>true,'accounts'=>$rows]);
    }

    if ($action === 'payment_account_save') {
        if (!$admin) json_out(['error'=>'unauthorized'], 401);
        require_post(); require_csrf(); payment_require_admin($pdo, $admin);
        $b = body();
        $id = (int)($b['id'] ?? 0);
        $bankCode = trim((string)($b['bank_code'] ?? ''));
        $bankName = trim((string)($b['bank_name'] ?? ''));
        $accountName = trim((string)($b['account_name'] ?? ''));
        $accountNumber = trim((string)($b['account_number'] ?? ''));
        $bin = trim((string)($b['bin'] ?? ''));
        $template = trim((string)($b['qr_template'] ?? 'compact2'));
        $prefix = trim((string)($b['transfer_prefix'] ?? 'HA88'));
        $active = !empty($b['active']);
        if ($bankCode==='' || $bankName==='' || $accountName==='' || !preg_match('/^[0-9]{6,40}$/',$accountNumber)) json_out(['error'=>'invalid_input'],422);
        if ($id > 0) {
            $q=$pdo->prepare('UPDATE payment_accounts SET bank_code=?,bank_name=?,account_name=?,account_number=?,bin=?,qr_template=?,transfer_prefix=?,updated_at=now(),updated_by=? WHERE id=?');
            $q->execute([$bankCode,$bankName,$accountName,$accountNumber,$bin?:null,$template,$prefix,(int)$admin['id'],$id]);
        } else {
            $q=$pdo->prepare('INSERT INTO payment_accounts(bank_code,bank_name,account_name,account_number,bin,qr_template,transfer_prefix,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?) RETURNING id');
            $q->execute([$bankCode,$bankName,$accountName,$accountNumber,$bin?:null,$template,$prefix,(int)$admin['id'],(int)$admin['id']]);
            $id=(int)$q->fetchColumn();
        }
        if ($active) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE payment_accounts SET active=FALSE,updated_at=now(),updated_by=? WHERE id<>?')->execute([(int)$admin['id'],$id]);
                $pdo->prepare('UPDATE payment_accounts SET active=TRUE,updated_at=now(),updated_by=? WHERE id=?')->execute([(int)$admin['id'],$id]);
                $pdo->commit();
            } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
        }
        audit((int)$admin['id'],'PAYMENT_ACCOUNT_SAVE','PAYMENT_ACCOUNT',(string)$id,null,['active'=>$active,'bank_code'=>$bankCode]);
        json_out(['ok'=>true,'id'=>$id]);
    }

    if ($action === 'payment_account_activate') {
        if (!$admin) json_out(['error'=>'unauthorized'], 401);
        require_post(); require_csrf(); payment_require_admin($pdo, $admin);
        $id=(int)(body()['id']??0); if($id<=0) json_out(['error'=>'invalid_input'],422);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE payment_accounts SET active=FALSE,updated_at=now(),updated_by=?')->execute([(int)$admin['id']]);
            $pdo->prepare('UPDATE payment_accounts SET active=TRUE,updated_at=now(),updated_by=? WHERE id=?')->execute([(int)$admin['id'],$id]);
            $pdo->commit();
        } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
        audit((int)$admin['id'],'PAYMENT_ACCOUNT_ACTIVATE','PAYMENT_ACCOUNT',(string)$id,null,null);
        json_out(['ok'=>true]);
    }

    if ($action === 'payment_deposit_create') {
        if (!$player) json_out(['error'=>'unauthorized'],401);
        require_post(); require_csrf();
        $b=body(); $amount=(int)($b['amount']??0);
        if($amount<10000 || $amount>100000000) json_out(['error'=>'invalid_amount'],422);
        $a=$pdo->query('SELECT * FROM payment_accounts WHERE active=TRUE LIMIT 1')->fetch();
        if(!$a) json_out(['error'=>'payment_account_unavailable'],503);
        $order=payment_order_code('NAP');
        $content=$a['transfer_prefix'].' '.$order;
        $q=$pdo->prepare('INSERT INTO payment_deposit_orders(order_code,player_id,payment_account_id,amount,transfer_content) VALUES(?,?,?,?,?) RETURNING id');
        $q->execute([$order,(int)$player['id'],(int)$a['id'],$amount,$content]);
        json_out(['ok'=>true,'order_code'=>$order,'amount'=>$amount,'transfer_content'=>$content,'account'=>['bank_name'=>$a['bank_name'],'account_name'=>$a['account_name'],'account_number'=>$a['account_number'],'bin'=>$a['bin']],'qr_url'=>payment_qr_url($a,$amount,$content),'demo'=>true]);
    }

    if ($action === 'payment_deposit_demo_paid') {
        if (!$player) json_out(['error'=>'unauthorized'],401);
        require_post(); require_csrf();
        $order=trim((string)(body()['order_code']??''));
        if($order==='') json_out(['error'=>'invalid_order'],422);
        $pdo->beginTransaction();
        try {
            $q=$pdo->prepare("SELECT * FROM payment_deposit_orders WHERE order_code=? AND player_id=? FOR UPDATE");
            $q->execute([$order,(int)$player['id']]); $o=$q->fetch();
            if(!$o || $o['status']!=='PENDING') { if($pdo->inTransaction())$pdo->rollBack(); json_out(['error'=>'invalid_state'],409); }
            // Demo only: record PAID. Do NOT credit TEST or real wallet here.
            $pdo->prepare("UPDATE payment_deposit_orders SET status='PAID',paid_at=now(),updated_at=now(),provider_reference=? WHERE id=?")->execute(['DEMO-'.$order,(int)$o['id']]);
            $pdo->commit();
        } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
        json_out(['ok'=>true,'status'=>'PAID','demo'=>true]);
    }

    if ($action === 'payment_deposit_history') {
        if (!$player) json_out(['error'=>'unauthorized'],401);
        $q=$pdo->prepare('SELECT order_code,amount,transfer_content,status,created_at,paid_at FROM payment_deposit_orders WHERE player_id=? ORDER BY id DESC LIMIT 50');
        $q->execute([(int)$player['id']]); json_out(['ok'=>true,'orders'=>$q->fetchAll()]);
    }

    if ($action === 'payment_withdraw_create') {
        if (!$player) json_out(['error'=>'unauthorized'],401);
        require_post(); require_csrf();
        $b=body(); $amount=(int)($b['amount']??0);
        $bankCode=trim((string)($b['bank_code']??'')); $bankName=trim((string)($b['bank_name']??''));
        $accountName=trim((string)($b['account_name']??'')); $accountNumber=trim((string)($b['account_number']??''));
        if($amount<10000 || $amount>100000000 || $bankCode==='' || $bankName==='' || $accountName==='' || !preg_match('/^[0-9]{6,40}$/',$accountNumber)) json_out(['error'=>'invalid_input'],422);
        // Demo order only; real payout must be connected to a compliant provider and balance hold/settlement service.
        $order=payment_order_code('RUT');
        $q=$pdo->prepare('INSERT INTO payment_withdraw_orders(order_code,player_id,bank_code,bank_name,account_name,account_number,amount) VALUES(?,?,?,?,?,?,?)');
        $q->execute([$order,(int)$player['id'],$bankCode,$bankName,$accountName,$accountNumber,$amount]);
        json_out(['ok'=>true,'order_code'=>$order,'status'=>'PENDING','demo'=>true]);
    }

    if ($action === 'payment_withdraw_history') {
        if (!$player) json_out(['error'=>'unauthorized'],401);
        $q=$pdo->prepare('SELECT order_code,bank_name,account_name,account_number,amount,status,admin_note,created_at,reviewed_at FROM payment_withdraw_orders WHERE player_id=? ORDER BY id DESC LIMIT 50');
        $q->execute([(int)$player['id']]); json_out(['ok'=>true,'orders'=>$q->fetchAll()]);
    }

    if ($action === 'admin_withdraw_list') {
        if (!$admin) json_out(['error'=>'unauthorized'],401); payment_require_admin($pdo,$admin);
        $rows=$pdo->query('SELECT w.order_code,w.amount,w.bank_name,w.account_name,w.account_number,w.status,w.admin_note,w.created_at,w.reviewed_at,p.username FROM payment_withdraw_orders w JOIN players p ON p.id=w.player_id ORDER BY w.id DESC LIMIT 200')->fetchAll();
        json_out(['ok'=>true,'orders'=>$rows]);
    }

    if ($action === 'admin_withdraw_review') {
        if (!$admin) json_out(['error'=>'unauthorized'],401); require_post(); require_csrf(); payment_require_admin($pdo,$admin);
        $b=body(); $order=trim((string)($b['order_code']??'')); $status=(string)($b['status']??''); $note=trim((string)($b['admin_note']??''));
        if($order==='' || !in_array($status,['APPROVED','REJECTED','PAID'],true)) json_out(['error'=>'invalid_input'],422);
        $q=$pdo->prepare("UPDATE payment_withdraw_orders SET status=?,admin_note=?,reviewed_at=now(),reviewed_by=?,updated_at=now() WHERE order_code=? AND status='PENDING'");
        $q->execute([$status,$note,(int)$admin['id'],$order]);
        if($q->rowCount()!==1) json_out(['error'=>'invalid_state'],409);
        audit((int)$admin['id'],'WITHDRAW_REVIEW','WITHDRAW',$order,null,['status'=>$status]);
        json_out(['ok'=>true,'status'=>$status,'demo'=>true]);
    }

    return false;
}
