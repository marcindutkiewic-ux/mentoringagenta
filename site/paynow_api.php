<?php
// PAYNOW - płatności mentoringagenta.pl (licencja AgentOS)
// POST {variant, name, email, nip?, adres?, faktura?}
// Zwraca {redirectUrl, paymentId}
// KONFIGURACJA: skopiuj paynow_config.sample.php -> paynow_config.php i wpisz klucze z panelu Paynow.

// Guard: czytelny komunikat zanim klucze Paynow zostaną dodane na serwerze
if (!file_exists(__DIR__ . '/paynow_config.php')) { http_response_code(503); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error'=>'paynow_nieaktywny','info'=>'Płatności online są w przygotowaniu. Napisz na mentoring@mentoringagenta.pl']); exit; }
require __DIR__ . '/paynow_config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://mentoringagenta.pl');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'metoda']); exit; }

$in = json_decode(file_get_contents('php://input'), true);
$variant = isset($in['variant']) ? preg_replace('/[^a-z0-9_]/','', $in['variant']) : '';
$name = isset($in['name']) ? trim($in['name']) : '';
$email = isset($in['email']) ? trim($in['email']) : '';
$nip = preg_replace('/[^0-9]/','', $in['nip'] ?? '');
$adres = trim($in['adres'] ?? '');
$faktura = !empty($in['faktura']) || (isset($in['nip']) && $in['nip'] !== '');

// Cennik (grosze) — AgentOS
$VARIANTS = [
    'agentos'      => ['amount'=>150000, 'desc'=>'Licencja AgentOS — Mentoring Agenta'],
    'agentos_biuro'=> ['amount'=>150000, 'desc'=>'Licencja AgentOS (biuro) — 1 stanowisko'],
];

if (!isset($VARIANTS[$variant]) || !filter_var($email, FILTER_VALIDATE_EMAIL) || $name==='') {
    http_response_code(400);
    echo json_encode(['error'=>'nieprawidlowe dane', 'variant'=>$variant]);
    exit;
}

$desc = $VARIANTS[$variant]['desc'] . ' - ' . $name;
$amount = $VARIANTS[$variant]['amount'];
$externalId = 'MA-' . date('Ymd-His') . '-' . substr(md5($email.$name), 0, 6);

// Zapis zamówienia
$ordersFile = __DIR__ . '/paynow-orders.json';
$orders = [];
if (file_exists($ordersFile)) {
    $decoded = json_decode((string)file_get_contents($ordersFile), true);
    if (is_array($decoded)) { $orders = $decoded; }
}
$orders[] = [
    'ts' => date('c'),
    'typ' => 'paynow-start',
    'variant' => $variant,
    'name' => $name,
    'email' => $email,
    'amount' => $amount/100,
    'externalId' => $externalId,
    'status' => 'nowy',
    'nip' => $nip,
    'adres' => $adres,
    'faktura' => $faktura,
];
@file_put_contents($ordersFile, json_encode($orders, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX);

// Kontynuuj istniejącą płatność (nie twórz od nowa)
foreach ($orders as $l) {
    if (($l['email'] ?? '') === $email && ($l['variant'] ?? '') === $variant
        && ($l['paymentId'] ?? '') && ($l['payStatus'] ?? '') === 'NEW'
        && isset($l['redirectUrl']) && $l['redirectUrl']) {
        echo json_encode([
            'redirectUrl' => $l['redirectUrl'],
            'paymentId' => $l['paymentId'],
            'status' => 'NEW',
            'resumed' => true,
        ]);
        exit;
    }
}

$body = json_encode([
    'externalId' => $externalId,
    'amount' => $amount,
    'description' => $desc,
    'buyer' => ['email' => $email],
    'continueUrl' => 'https://mentoringagenta.pl/dziekujemy.html?v=' . urlencode($variant),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$sig = base64_encode(hash_hmac('sha256', $body, PAYNOW_SIGNATURE_KEY, true));

$ch = curl_init(PAYNOW_API . '/v1/payments');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Api-Key: ' . PAYNOW_API_KEY,
        'Signature: ' . $sig,
        'Idempotency-Key: ' . $externalId,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25,
]);
$res = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($http !== 201) {
    http_response_code(502);
    echo json_encode(['error'=>'paynow', 'http'=>$http, 'raw'=>substr((string)$res,0,200)]);
    exit;
}

$j = json_decode($res, true);
$paymentId = isset($j['paymentId']) ? $j['paymentId'] : '';

foreach ($orders as $i => $l) {
    if (($l['email'] ?? '') === $email && ($l['externalId'] ?? '') === $externalId) {
        $orders[$i]['paymentId'] = $paymentId;
        $orders[$i]['payStatus'] = 'NEW';
        $orders[$i]['redirectUrl'] = isset($j['redirectUrl']) ? $j['redirectUrl'] : '';
    }
}
file_put_contents($ordersFile, json_encode($orders, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX);

// Mail potwierdzenia (nie blokuje) — używa sprawdzonej wysyłki z marcindutkiewicz.pl
$ctx = stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\n",
    'content'=>json_encode(['email'=>$email,'name'=>$name,'variant'=>$variant,'kind'=>'confirm','redirectUrl'=>isset($j['redirectUrl'])?$j['redirectUrl']:'','paymentId'=>$paymentId,'source'=>'mentoringagenta']), 'ignore_errors'=>true, 'timeout'=>8]]);
@file_get_contents('https://marcindutkiewicz.pl/newsletter/order_mail.php', false, $ctx);

echo json_encode([
    'redirectUrl' => isset($j['redirectUrl']) ? $j['redirectUrl'] : '',
    'paymentId' => $paymentId,
    'status' => isset($j['status']) ? $j['status'] : '',
]);