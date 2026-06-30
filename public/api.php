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
    $id = (int)$row['id'];

    if (!isset($stations[$id])) {
        $stations[$id] = [
            'id' => $id,
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
            'report_summary' => null,
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

// Последний отчёт — нужен для автоподстановки в форме
$latestRows = $db->query("
    SELECT r.*
    FROM station_reports_v2 r
    INNER JOIN (
        SELECT station_id, MAX(id) AS max_id
        FROM station_reports_v2
        GROUP BY station_id
    ) x ON x.max_id = r.id
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($latestRows as $report) {
    $sid = (int)$report['station_id'];

    if (!isset($stations[$sid])) {
        continue;
    }

    $stations[$sid]['latest_report'] = [
        'fuel_statuses' => json_decode($report['fuel_statuses'] ?: '{}', true),
        'queue_level' => $report['queue_level'],
        'comment' => $report['comment'],
        'created_at' => $report['created_at'],
        'created_ts' => (int)$report['created_ts'],
    ];
}

// Сводка за последние 30 минут
$nowTs = time();
$windowSeconds = 30 * 60;
$minTs = $nowTs - $windowSeconds;

$summaryRows = $db->prepare("
    SELECT *
    FROM station_reports_v2
    WHERE created_ts >= :min_ts
    ORDER BY created_ts DESC
");

$summaryRows->execute([
    ':min_ts' => $minTs,
]);

$reports = $summaryRows->fetchAll(PDO::FETCH_ASSOC);

$summaryByStation = [];

foreach ($reports as $report) {
    $sid = (int)$report['station_id'];

    if (!isset($stations[$sid])) {
        continue;
    }

    if (!isset($summaryByStation[$sid])) {
        $summaryByStation[$sid] = [
            'total_reports' => 0,
            'latest_created_at' => null,
            'latest_created_ts' => 0,
            'fuels' => [],
            'queue_counts' => [
                'none' => 0,
                'small' => 0,
                'medium' => 0,
                'big' => 0,
                'unknown' => 0,
            ],
        ];
    }

    $summaryByStation[$sid]['total_reports']++;

    $createdTs = (int)$report['created_ts'];

    if ($createdTs > $summaryByStation[$sid]['latest_created_ts']) {
        $summaryByStation[$sid]['latest_created_ts'] = $createdTs;
        $summaryByStation[$sid]['latest_created_at'] = $report['created_at'];
    }

    $statuses = json_decode($report['fuel_statuses'] ?: '{}', true);

    if (is_array($statuses)) {
        foreach ($statuses as $fuelKey => $status) {
            if (!isset($summaryByStation[$sid]['fuels'][$fuelKey])) {
                $summaryByStation[$sid]['fuels'][$fuelKey] = [
                    'yes' => 0,
                    'no' => 0,
                    'unknown' => 0,
                ];
            }

            if (!in_array($status, ['yes', 'no', 'unknown'], true)) {
                $status = 'unknown';
            }

            $summaryByStation[$sid]['fuels'][$fuelKey][$status]++;
        }
    }

    $queue = $report['queue_level'] ?: 'unknown';

    if (!isset($summaryByStation[$sid]['queue_counts'][$queue])) {
        $queue = 'unknown';
    }

    $summaryByStation[$sid]['queue_counts'][$queue]++;
}

foreach ($summaryByStation as $sid => $summary) {
    $fuelResult = [];

    foreach ($summary['fuels'] as $fuelKey => $counts) {
        $yes = (int)$counts['yes'];
        $no = (int)$counts['no'];
        $unknown = (int)$counts['unknown'];
        $knownTotal = $yes + $no;

        if ($knownTotal === 0) {
            $status = 'unknown';
            $votes = 0;
        } elseif ($yes > $no) {
            $status = 'yes';
            $votes = $yes;
        } elseif ($no > $yes) {
            $status = 'no';
            $votes = $no;
        } else {
            $status = 'unknown';
            $votes = $knownTotal;
        }

        $fuelResult[$fuelKey] = [
            'status' => $status,
            'yes' => $yes,
            'no' => $no,
            'unknown' => $unknown,
            'known_total' => $knownTotal,
            'votes' => $votes,
        ];
    }

    $queueCounts = $summary['queue_counts'];
    $queueWithoutUnknown = $queueCounts;
    unset($queueWithoutUnknown['unknown']);

    arsort($queueWithoutUnknown);
    $queueStatus = array_key_first($queueWithoutUnknown);

    if (!$queueStatus || $queueWithoutUnknown[$queueStatus] === 0) {
        $queueStatus = 'unknown';
    }

    $stations[$sid]['report_summary'] = [
        'window_minutes' => 30,
        'total_reports' => $summary['total_reports'],
        'latest_created_at' => $summary['latest_created_at'],
        'latest_created_ts' => $summary['latest_created_ts'],
        'fuels' => $fuelResult,
        'queue' => [
            'status' => $queueStatus,
            'counts' => $queueCounts,
        ],
    ];
}

echo json_encode([
    'items' => array_values($stations),
    'count' => count($stations),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);