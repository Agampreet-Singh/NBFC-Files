<?php
// Telecom Service API — OTP gateway for the lab.
//   ?action=send     POST mobile              -> generates OTP, stores it, delivers an SMS
//   ?action=verify   POST mobile, otp         -> validates the OTP (real check)
//   ?action=messages GET  mobile              -> returns the SMS inbox (used by Messages app)
// LAB / isolated range only.

require __DIR__ . '/db.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');          // so the Messages app (other node) can poll
header('Access-Control-Allow-Headers: Content-Type');

$action = $_GET['action'] ?? '';
$pdo = tdb();
$now = date('Y-m-d H:i:s');

if ($action === 'send') {
    $mobile = preg_replace('/\D/', '', $_POST['mobile'] ?? '');
    if (strlen($mobile) < 10) { echo json_encode(['status' => 'error', 'message' => 'invalid mobile']); exit; }
    $service = trim($_POST['service'] ?? 'FinanceCorp');
    $service = preg_replace('/[^A-Za-z0-9 \-]/', '', substr($service, 0, 24)) ?: 'FinanceCorp';
    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $exp = date('Y-m-d H:i:s', time() + 300);        // 5-minute validity
    $pdo->prepare("INSERT INTO otps(mobile, otp, created_at, expires_at) VALUES(?,?,?,?)")
        ->execute([$mobile, $otp, $now, $exp]);
    $body = "Your $service OTP is $otp. Valid for 5 minutes. Do not share it with anyone.";
    $pdo->prepare("INSERT INTO messages(mobile, sender, body, created_at) VALUES(?,?,?,?)")
        ->execute([$mobile, $service, $body, $now]);
    echo json_encode(['status' => 'sent']);
    exit;
}

if ($action === 'verify') {
    $mobile = preg_replace('/\D/', '', $_POST['mobile'] ?? '');
    $otp    = preg_replace('/\D/', '', $_POST['otp'] ?? '');
    $st = $pdo->prepare("SELECT id FROM otps WHERE mobile=? AND otp=? AND used=0 AND expires_at>=? ORDER BY id DESC LIMIT 1");
    $st->execute([$mobile, $otp, $now]);
    $row = $st->fetch();
    if ($row) {
        $pdo->prepare("UPDATE otps SET used=1 WHERE id=?")->execute([$row['id']]);
        echo json_encode(['valid' => true]);
    } else {
        echo json_encode(['valid' => false]);
    }
    exit;
}

if ($action === 'messages') {
    $mobile = preg_replace('/\D/', '', $_GET['mobile'] ?? '');
    $st = $pdo->prepare("SELECT sender, body, created_at FROM messages WHERE mobile=? ORDER BY id DESC LIMIT 20");
    $st->execute([$mobile]);
    echo json_encode(['messages' => $st->fetchAll()]);
    exit;
}

if ($action === 'clear') {                               // clear this phone's inbox
    $mobile = preg_replace('/\D/', '', $_POST['mobile'] ?? '');
    $pdo->prepare("DELETE FROM messages WHERE mobile=?")->execute([$mobile]);
    echo json_encode(['status' => 'cleared']);
    exit;
}

echo json_encode(['status' => 'ok', 'service' => 'telecom-otp-gateway']);
