<?php
// PAYNOW webhook — potwierdzenia płatności (mentoringagenta.pl)
// Guard: czytelny komunikat zanim klucze Paynow zostaną dodane na serwerze
if (!file_exists(__DIR__ . '/paynow_config.php')) { http_response_code(503); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error'=>'paynow_nieaktywny','info'=>'Płatności online są w przygotowaniu (brak pliku konfiguracji). Napisz na mentoring@mentoringagenta.pl']); exit; }
if (strpos(@file_get_contents(__DIR__ . '/paynow_config.php'), 'TU_WKLEJ') !== false) { http_response_code(503); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error'=>'paynow_nieaktywny','info'=>'Płatności online są w przygotowaniu (klucze nie zostały jeszcze wklejone).']); exit; }
require __DIR__ . '/paynow_config.php';
$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_SIGNATURE'] ?? '';
$calc = base64_encode(hash_hmac('sha256', $raw, PAYNOW_SIGNATURE_KEY, true));
if (!hash_equals($calc, $sig)) { http_response_code(400); echo 'bad signature'; exit; }
$j = json_decode($raw, true);
$paymentId = $j['paymentId'] ?? ''; $status = $j['status'] ?? '';
$ordersFile = __DIR__ . '/paynow-orders.php';
$__whRaw = file_exists($ordersFile) ? preg_replace('/^<\\?php exit; \\?>\\s*/', '', (string)file_get_contents($ordersFile)) : '';
$orders = [];
if (file_exists($ordersFile)) { $d = json_decode($__whRaw, true); if (is_array($d)) $orders = $d; }
foreach ($orders as $i => $o) {
    if (($o['paymentId'] ?? '') === $paymentId) {
        $orders[$i]['payStatus'] = $status;
        $orders[$i]['paidAt'] = date('c');
        if ($status === 'CONFIRMED') {
            // Mail z dostępem/licencją — przez sprawdzoną wysyłkę marcindutkiewicz.pl
            $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\n",
                'content'=>json_encode(['email'=>$o['email'] ?? '','name'=>$o['name'] ?? '','kind'=>'paid','variant'=>$o['variant'] ?? '','paymentId'=>$paymentId,'source'=>'mentoringagenta']), 'ignore_errors'=>true, 'timeout'=>8]]);
            @file_get_contents('https://marcindutkiewicz.pl/newsletter/order_mail.php', false, $ctx);
        }
    }
}
@file_put_contents($ordersFile, "<?php exit; ?>\n" . json_encode($orders, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX);
http_response_code(200); echo 'OK';
