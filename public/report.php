<?php

header('Content-Type: application/json; charset=utf-8');

$dbFile = __DIR__ . '/../fuel.sqlite';

if (!file_exists($dbFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'База не найдена'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec("
CREATE TABLE IF NOT EXISTS station_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    station_id INTEGER NOT NULL,
    fuel_92 TEXT,
    fuel_95 TEXT,
    fuel_95_pulsar TEXT,
    fuel_diesel TEXT,
    queue_level TEXT,
    comment TEXT,
    user_agent TEXT,
    ip_hash TEXT,
    created_at TEXT
);
");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Некорректный JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stationId = (int)($input['station_id'] ?? 0);

if ($stationId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Не указана АЗС'], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowedAvailability = ['yes', 'no', 'unknown'];
$allowedQueue = ['none', 'small', 'medium', 'big', 'unknown'];

function clean_availability($value, $allowed) {
    return in_array($value, $allowed, true) ? $value : 'unknown';
}

function clean_queue($value, $allowed) {
    return in_array($value, $allowed, true) ? $value : 'unknown';
}

$fuel92 = clean_availability($input['fuel_92'] ?? 'unknown', $allowedAvailability);
$fuel95 = clean_availability($input['fuel_95'] ?? 'unknown', $allowedAvailability);
$fuel95Pulsar = clean_availability($input['fuel_95_pulsar'] ?? 'unknown', $allowedAvailability);
$fuelDiesel = clean_availability($input['fuel_diesel'] ?? 'unknown', $allowedAvailability);
$queueLevel = clean_queue($input['queue_level'] ?? 'unknown', $allowedQueue);

$comment = trim((string)($input['comment'] ?? ''));
$comment = mb_substr($comment, 0, 300);

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ipHash = hash('sha256', $ip . '|fuel-map-local-salt');

$userAgent = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);

$now = date('c');

// Простая защита: один отчёт по одной АЗС с одного IP не чаще раза в 2 минуты
$check = $db->prepare("
    SELECT COUNT(*) 
    FROM station_reports
    WHERE station_id = :station_id
      AND ip_hash = :ip_hash
      AND created_at >= datetime('now', '-2 minutes')
");
$check->execute([
    ':station_id' => $stationId,
    ':ip_hash' => $ipHash,
]);

if ((int)$check->fetchColumn() > 0) {
    http_response_code(429);
    echo json_encode([
        'error' => 'Слишком часто. Попробуй через пару минут.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $db->prepare("
    INSERT INTO station_reports
        (
            station_id,
            fuel_92,
            fuel_95,
            fuel_95_pulsar,
            fuel_diesel,
            queue_level,
            comment,
            user_agent,
            ip_hash,
            created_at
        )
    VALUES
        (
            :station_id,
            :fuel_92,
            :fuel_95,
            :fuel_95_pulsar,
            :fuel_diesel,
            :queue_level,
            :comment,
            :user_agent,
            :ip_hash,
            :created_at
        )
");

$stmt->execute([
    ':station_id' => $stationId,
    ':fuel_92' => $fuel92,
    ':fuel_95' => $fuel95,
    ':fuel_95_pulsar' => $fuel95Pulsar,
    ':fuel_diesel' => $fuelDiesel,
    ':queue_level' => $queueLevel,
    ':comment' => $comment,
    ':user_agent' => $userAgent,
    ':ip_hash' => $ipHash,
    ':created_at' => $now,
]);

echo json_encode([
    'ok' => true,
    'message' => 'Отчёт сохранён',
], JSON_UNESCAPED_UNICODE);