<?php
/**
 * UPS Disposition Analytics Dashboard — SQLite Database API (ups_cache.db)
 * Положите этот файл (api.php) и файл базы (ups_cache.db) в одну папку с UPS_Dashboard.html на хостинге.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$dbFile = __DIR__ . '/ups_cache.db';

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA synchronous = NORMAL;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');

    // Автоматическое создание таблиц, если база ещё пустая
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS geocache (
            address_key TEXT PRIMARY KEY,
            lat REAL NOT NULL,
            lng REAL NOT NULL,
            updated_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS plz_coords (
            plz TEXT PRIMARY KEY,
            city TEXT NOT NULL,
            lat REAL NOT NULL,
            lng REAL NOT NULL
        );
        CREATE TABLE IF NOT EXISTS reports (
            report_key TEXT PRIMARY KEY,
            file_name TEXT,
            is_forecast INTEGER DEFAULT 0,
            data_json TEXT NOT NULL,
            updated_at INTEGER NOT NULL
        );
    ');

    $action = isset($_GET['action']) ? $_GET['action'] : 'init';

    if ($action === 'init') {
        $coords = [];
        $stmt = $pdo->query('SELECT address_key, lat, lng FROM geocache');
        while ($row = $stmt->fetch()) {
            $coords[$row['address_key']] = [
                'lat' => (float)$row['lat'],
                'lng' => (float)$row['lng']
            ];
        }

        $plz = [];
        $stmtPlz = $pdo->query('SELECT plz, city, lat, lng FROM plz_coords');
        while ($row = $stmtPlz->fetch()) {
            $plz[$row['plz']] = [
                'city' => $row['city'],
                'lat' => (float)$row['lat'],
                'lng' => (float)$row['lng']
            ];
        }

        $reportStmt = $pdo->prepare('SELECT file_name, is_forecast, data_json, updated_at FROM reports WHERE report_key = :key LIMIT 1');
        $reportStmt->execute([':key' => 'current_ups_data']);
        $repRow = $reportStmt->fetch();

        $report = null;
        if ($repRow && !empty($repRow['data_json'])) {
            $decoded = json_decode($repRow['data_json'], true);
            if (is_array($decoded)) {
                $report = [
                    'fileName' => $repRow['file_name'],
                    'isForecast' => (bool)$repRow['is_forecast'],
                    'updatedAt' => (int)$repRow['updated_at'],
                    'data' => $decoded
                ];
            }
        }

        echo json_encode([
            'ok' => true,
            'engine' => 'sqlite',
            'coordsCount' => count($coords),
            'coords' => $coords,
            'plz' => $plz,
            'report' => $report
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'coords') {
        $coords = [];
        $stmt = $pdo->query('SELECT address_key, lat, lng FROM geocache');
        while ($row = $stmt->fetch()) {
            $coords[$row['address_key']] = [
                'lat' => (float)$row['lat'],
                'lng' => (float)$row['lng']
            ];
        }
        echo json_encode(['ok' => true, 'coords' => $coords], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save_coords' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        $items = isset($body['items']) && is_array($body['items']) ? $body['items'] : [];
        $plzItems = isset($body['plz']) && is_array($body['plz']) ? $body['plz'] : [];

        $now = time();
        $saved = 0;
        if (!empty($items) || !empty($plzItems)) {
            $pdo->beginTransaction();
            if (!empty($items)) {
                $stmt = $pdo->prepare('INSERT OR REPLACE INTO geocache (address_key, lat, lng, updated_at) VALUES (:k, :lat, :lng, :ts)');
                foreach ($items as $k => $val) {
                    if (isset($val['lat']) && isset($val['lng'])) {
                        $stmt->execute([
                            ':k' => (string)$k,
                            ':lat' => (float)$val['lat'],
                            ':lng' => (float)$val['lng'],
                            ':ts' => $now
                        ]);
                        $saved++;
                    }
                }
            }
            if (!empty($plzItems)) {
                $stmtPlz = $pdo->prepare('INSERT OR REPLACE INTO plz_coords (plz, city, lat, lng) VALUES (:plz, :city, :lat, :lng)');
                foreach ($plzItems as $p => $val) {
                    if (isset($val['lat']) && isset($val['lng'])) {
                        $stmtPlz->execute([
                            ':plz' => (string)$p,
                            ':city' => isset($val['city']) ? (string)$val['city'] : '',
                            ':lat' => (float)$val['lat'],
                            ':lng' => (float)$val['lng']
                        ]);
                    }
                }
            }
            $pdo->commit();
        }
        echo json_encode(['ok' => true, 'saved' => $saved]);
        exit;
    }

    if ($action === 'get_report') {
        $key = isset($_GET['key']) ? $_GET['key'] : 'current_ups_data';
        $stmt = $pdo->prepare('SELECT file_name, is_forecast, data_json, updated_at FROM reports WHERE report_key = :k LIMIT 1');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch();
        if ($row) {
            echo json_encode([
                'ok' => true,
                'fileName' => $row['file_name'],
                'isForecast' => (bool)$row['is_forecast'],
                'updatedAt' => (int)$row['updated_at'],
                'data' => json_decode($row['data_json'], true)
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['ok' => true, 'data' => null]);
        }
        exit;
    }

    if ($action === 'save_report' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        $key = isset($body['key']) ? (string)$body['key'] : 'current_ups_data';
        $fileName = isset($body['fileName']) ? (string)$body['fileName'] : '';
        $isForecast = !empty($body['isForecast']) ? 1 : 0;
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];

        $stmt = $pdo->prepare('INSERT OR REPLACE INTO reports (report_key, file_name, is_forecast, data_json, updated_at) VALUES (:k, :fn, :fc, :json, :ts)');
        $stmt->execute([
            ':k' => $key,
            ':fn' => $fileName,
            ':fc' => $isForecast,
            ':json' => json_encode($data, JSON_UNESCAPED_UNICODE),
            ':ts' => time()
        ]);
        echo json_encode(['ok' => true, 'rows' => count($data)]);
        exit;
    }

    if ($action === 'clear_reports' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Очищаем только загруженные отчёты, сохраняя накопленный кэш координат geocache и plz_coords!
        $pdo->exec('DELETE FROM reports;');
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
