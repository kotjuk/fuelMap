<?php

header('Content-Type: application/json; charset=utf-8');

$dbFile = __DIR__ . '/../fuel.sqlite';

if (!file_exists($dbFile)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'База fuel.sqlite не найдена. Сначала запусти php collect_rosneft.php'
    ], JSON_UNESCAPED_UNICODE);
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

$sql = "
SELECT
    s.id,
    s.source,
    s.external_id,
    s.brand,
    s.name,
    s.number,
    s.address,
    s.lat,
    s.lng,
    s.services,
    s.updated_at,
    p.fuel_key,
    p.price,
    p.price_update_date
FROM stations s
LEFT JOIN prices p ON p.station_id = s.id
ORDER BY s.brand, s.number, p.fuel_key
";

$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$stations = [];

foreach ($rows as $row) {
    $id = $row['id'];

    if (!isset($stations[$id])) {
        $stations[$id] = [
            'id' => (int)$row['id'],
            'source' => $row['source'],
            'external_id' => $row['external_id'],
            'brand' => $row['brand'],
            'name' => $row['name'],
            'number' => $row['number'],
            'address' => $row['address'],
            'lat' => (float)$row['lat'],
            'lng' => (float)$row['lng'],
            'services' => json_decode($row['services'] ?: '[]', true),
            'updated_at' => $row['updated_at'],
            'prices' => [],
            'latest_report' => null,
        ];
    }

    if ($row['fuel_key'] !== null) {
        $stations[$id]['prices'][] = [
            'key' => $row['fuel_key'],
            'price' => (float)$row['price'],
            'price_update_date' => $row['price_update_date'] ? (int)$row['price_update_date'] : null,
        ];
    }
}

$reportRows = $db->query("
    SELECT r.*
    FROM station_reports_v2 r
    INNER JOIN (
        SELECT station_id, MAX(created_ts) AS max_ts
        FROM station_reports_v2
        GROUP BY station_id
    ) x
      ON x.station_id = r.station_id
     AND x.max_ts = r.created_ts
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($reportRows as $report) {
    $sid = $report['station_id'];

    if (!isset($stations[$sid])) {
        continue;
    }

    $stations[$sid]['latest_report'] = [
        'fuel_statuses' => json_decode($report['fuel_statuses'] ?: '{}', true),
        'queue_level' => $report['queue_level'],
        'comment' => $report['comment'],
        'created_at' => $report['created_at'],
        'created_ts' => $report['created_ts'],
    ];
}

echo json_encode([
    'items' => array_values($stations),
    'count' => count($stations),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);