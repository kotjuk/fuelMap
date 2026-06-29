<?php

const DB_FILE = __DIR__ . '/fuel.sqlite';

// Пока используем найденный endpoint Роснефти.
// Если он не сработает — заменим на точный URL из твоего curl.
const ROSNEFT_URL = 'https://rn-brand-map.sitesoft.ru/api/v19/mapobject';

// Примерная зона Тульской области
const MIN_LAT = 53.0;
const MAX_LAT = 54.9;
const MIN_LNG = 35.7;
const MAX_LNG = 39.1;

function http_get_json(string $url): mixed
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 FuelMap/1.0',
            ]),
            'timeout' => 30,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);

    if ($raw === false) {
        throw new RuntimeException('Не удалось получить данные от Роснефти');
    }

    $json = json_decode($raw, true);

    if ($json === null) {
        throw new RuntimeException('Ответ не похож на JSON. Первые символы: ' . substr($raw, 0, 200));
    }

    return $json;
}

function find_stations(mixed $data): array
{
    $result = [];

    $walk = function ($node) use (&$walk, &$result) {
        if (!is_array($node)) {
            return;
        }

        $hasStationShape =
            isset($node['id']) &&
            isset($node['station_address']['coordinate']['lat']) &&
            isset($node['station_address']['coordinate']['lng']);

        if ($hasStationShape) {
            $result[] = $node;
            return;
        }

        foreach ($node as $child) {
            $walk($child);
        }
    };

    $walk($data);

    return $result;
}

function is_tula_area(array $station): bool
{
    $lat = (float)($station['station_address']['coordinate']['lat'] ?? 0);
    $lng = (float)($station['station_address']['coordinate']['lng'] ?? 0);
    $address = mb_strtolower($station['station_address']['address'] ?? '');

    if (str_contains($address, 'тульская')) {
        return true;
    }

    return $lat >= MIN_LAT && $lat <= MAX_LAT && $lng >= MIN_LNG && $lng <= MAX_LNG;
}

$db = new PDO('sqlite:' . DB_FILE);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec("
CREATE TABLE IF NOT EXISTS stations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL,
    external_id TEXT NOT NULL,
    brand TEXT,
    name TEXT,
    number TEXT,
    address TEXT,
    lat REAL,
    lng REAL,
    services TEXT,
    updated_at TEXT,
    UNIQUE(source, external_id)
);

CREATE TABLE IF NOT EXISTS prices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    station_id INTEGER NOT NULL,
    fuel_key TEXT NOT NULL,
    price REAL NOT NULL,
    price_update_date INTEGER,
    updated_at TEXT,
    UNIQUE(station_id, fuel_key)
);

CREATE TABLE IF NOT EXISTS user_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    station_id INTEGER NOT NULL,
    fuel_key TEXT,
    availability TEXT,
    queue_level TEXT,
    comment TEXT,
    created_at TEXT
);
");

echo "Забираю данные Роснефти...\n";

$data = http_get_json(ROSNEFT_URL);
$stations = find_stations($data);

echo "Найдено объектов: " . count($stations) . "\n";

$now = date('c');
$saved = 0;

foreach ($stations as $s) {
    if (($s['type'] ?? '') !== 'gas_station') {
        continue;
    }

    if (!is_tula_area($s)) {
        continue;
    }

    $externalId = (string)$s['id'];
    $brand = $s['brand'] ?? 'rosneft';
    $name = $s['name'] ?? null;
    $number = $s['number'] ?? null;
    $address = $s['station_address']['address'] ?? null;
    $lat = (float)$s['station_address']['coordinate']['lat'];
    $lng = (float)$s['station_address']['coordinate']['lng'];
    $services = json_encode($s['services'] ?? [], JSON_UNESCAPED_UNICODE);

    $stmt = $db->prepare("
        INSERT INTO stations 
            (source, external_id, brand, name, number, address, lat, lng, services, updated_at)
        VALUES 
            ('rosneft', :external_id, :brand, :name, :number, :address, :lat, :lng, :services, :updated_at)
        ON CONFLICT(source, external_id) DO UPDATE SET
            brand = excluded.brand,
            name = excluded.name,
            number = excluded.number,
            address = excluded.address,
            lat = excluded.lat,
            lng = excluded.lng,
            services = excluded.services,
            updated_at = excluded.updated_at
    ");

    $stmt->execute([
        ':external_id' => $externalId,
        ':brand' => $brand,
        ':name' => $name,
        ':number' => $number,
        ':address' => $address,
        ':lat' => $lat,
        ':lng' => $lng,
        ':services' => $services,
        ':updated_at' => $now,
    ]);

    $stationId = (int)$db->query("
        SELECT id FROM stations 
        WHERE source = 'rosneft' AND external_id = " . $db->quote($externalId)
    )->fetchColumn();

    foreach (($s['fuel_prices'] ?? []) as $fuel) {
        if (!isset($fuel['key'], $fuel['value'])) {
            continue;
        }

        $priceStmt = $db->prepare("
            INSERT INTO prices
                (station_id, fuel_key, price, price_update_date, updated_at)
            VALUES
                (:station_id, :fuel_key, :price, :price_update_date, :updated_at)
            ON CONFLICT(station_id, fuel_key) DO UPDATE SET
                price = excluded.price,
                price_update_date = excluded.price_update_date,
                updated_at = excluded.updated_at
        ");

        $priceStmt->execute([
            ':station_id' => $stationId,
            ':fuel_key' => $fuel['key'],
            ':price' => (float)$fuel['value'],
            ':price_update_date' => $s['price_update_date'] ?? null,
            ':updated_at' => $now,
        ]);
    }

    $saved++;
}

echo "Сохранено АЗС по Туле/области: {$saved}\n";
echo "База: " . DB_FILE . "\n";