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
CREATE TABLE IF NOT EXISTS station_reports_v2 (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    station_id INTEGER NOT NULL,
    fuel_statuses TEXT NOT NULL,
    queue_level TEXT,
    comment TEXT,
    user_agent TEXT,
    ip_hash TEXT,
    created_at TEXT,
    created_ts INTEGER
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

$fuelStatuses = $input['fuel_statuses'] ?? [];
if (!is_array($fuelStatuses)) {
    $fuelStatuses = [];
}

$cleanStatuses = [];
foreach ($fuelStatuses as $fuelKey => $value) {
    $value = (string)$value;
    $cleanStatuses[$fuelKey] = in_array($value, $allowedAvailability, true) ? $value : 'unknown';
}

$queueLevel = (string)($input['queue_level'] ?? 'unknown');
if (!in_array($queueLevel, $allowedQueue, true)) {
    $queueLevel = 'unknown';
}

$comment = trim((string)($input['comment'] ?? ''));
$comment = mb_substr($comment, 0, 300);

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ipHash = hash('sha256', $ip . '|fuel-map-local-salt');

$userAgent = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);

$createdAt = date('c');
$createdTs = time();

// Один отчёт по одной АЗС с одного IP не чаще раза в 2 минуты
$check = $db->prepare("
    SELECT COUNT(*)
    FROM station_reports_v2
    WHERE station_id = :station_id
      AND ip_hash = :ip_hash
      AND created_ts >= :min_ts
");
$check->execute([
    ':station_id' => $stationId,
    ':ip_hash' => $ipHash,
    ':min_ts' => $createdTs - 120,
]);

if ((int)$check->fetchColumn() > 0) {
    http_response_code(429);
    echo json_encode([
        'error' => 'Слишком часто. Попробуй через пару минут.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $db->prepare("
    INSERT INTO station_reports_v2
    (
        station_id,
        fuel_statuses,
        queue_level,
        comment,
        user_agent,
        ip_hash,
        created_at,
        created_ts
    )
    VALUES
    (
        :station_id,
        :fuel_statuses,
        :queue_level,
        :comment,
        :user_agent,
        :ip_hash,
        :created_at,
        :created_ts
    )
");

$stmt->execute([
    ':station_id' => $stationId,
    ':fuel_statuses' => json_encode($cleanStatuses, JSON_UNESCAPED_UNICODE),
    ':queue_level' => $queueLevel,
    ':comment' => $comment,
    ':user_agent' => $userAgent,
    ':ip_hash' => $ipHash,
    ':created_at' => $createdAt,
    ':created_ts' => $createdTs,
]);

echo json_encode([
    'ok' => true,
    'message' => 'Отчёт сохранён',
], JSON_UNESCAPED_UNICODE);