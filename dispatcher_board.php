<?php
declare(strict_types=1);

/**
 * dispatcher_board.php
 *
 * Das zentrale Dispositions-Tableau (Dispatcher Board) von TransportBoss.
 * Implementiert das neue Drei-Spalten-Layout:
 * - Links: Strategie-Monitor (unzugewiesener Lagerbestand pro lizensierter Stadt)
 * - Mitte: Kompakte, zweizeilige LKW-Auswahlbuttons (aufsteigend sortiert)
 * - Rechts: Geteilter Detail-Arbeitsbereich (Oben: Aktive Tour / unten: Vorschlagskette)
 *
 * @author TransportBoss Development
 * @version 2.0.0
 */

require_once 'db_connect.php';
require_once 'classes/TruckRepository.php';
require_once 'classes/DriverRepository.php';
require_once 'classes/OrderRepository.php';
require_once 'classes/TopologyEngine.php';
require_once 'classes/DistanceService.php';

// Initialisierung der System-Services
$truckRepo = new TruckRepository($pdo);
$driverRepo = new DriverRepository($pdo);
$orderRepo = new OrderRepository($pdo);
$distanceService = new DistanceService($pdo);
$topologyEngine = new TopologyEngine($pdo, $distanceService);

// -------------------------------------------------------------
// DYNAMISCHE SLOT-BERECHNUNG (4 Grundwert + floor(Verwaltung / 10))
// -------------------------------------------------------------
// Berechnet das tagesaktuelle Slot-Limit auf Basis der angestellten Disponenten (PH 4.2)
$employedDispatchers = $pdo->query("SELECT skill_val FROM dispatchers WHERE is_employed = 1")->fetchAll(PDO::FETCH_ASSOC);
$maxDispoSlots = 4;
foreach ($employedDispatchers as $disp) {
    $maxDispoSlots += (int)floor((int)$disp['skill_val'] / 10);
}

// In der Config-Tabelle persistent aktualisieren, damit alle Module das gleiche Limit nutzen
$stmtUpdateCfg = $pdo->prepare("INSERT INTO config (cfg_key, cfg_value) VALUES ('max_dispo_slots', :val) ON DUPLICATE KEY UPDATE cfg_value = :val");
$stmtUpdateCfg->execute(['val' => (string)$maxDispoSlots]);

// Fokus-System des Fahrzeug-Tableaus (Singleton-Fokus in der DB sichern)
$focusTruckId = null;
if (isset($_GET['focus_truck_id'])) {
    $focusTruckId = (int)$_GET['focus_truck_id'];
    $pdo->exec("UPDATE trucks SET is_focussed = 0");
    $pdo->exec("UPDATE trucks SET is_focussed = 1 WHERE id = $focusTruckId");
} else {
    $firstTruck = $pdo->query("SELECT id FROM trucks WHERE assigned_driver_id IS NOT NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $focusTruckId = $firstTruck ? (int)$firstTruck['id'] : null;
}

// Entladen-Aktion (Kaskaden-Storno eines Tour-Auftrags)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unload_job') {
    $orderId = (int)$_POST['order_id'];
    
    // 1. Hole Fahrzeug-ID und Zuweisungs-Zeitstempel des zu entladenden Auftrags
    $stmtInfo = $pdo->prepare("SELECT assigned_truck_id, assigned_at FROM orders WHERE id = ?");
    $stmtInfo->execute([$orderId]);
    $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);
    
    if ($info && $info['assigned_truck_id'] && $info['assigned_at']) {
        // 2. Kaskaden-Storno: Entkopple diesen Auftrag sowie alle nachfolgend geplanten Aufträge des LKW
        $stmtCascade = $pdo->prepare("
            UPDATE orders 
            SET assigned_truck_id = NULL, 
                assigned_at = NULL 
            WHERE assigned_truck_id = :truck_id 
              AND is_archived = 0 
              AND assigned_at >= :assigned_at
        ");
        $stmtCascade->execute([
            'truck_id' => (int)$info['assigned_truck_id'],
            'assigned_at' => $info['assigned_at']
        ]);
    }
    
    header("Location: dispatcher_board.php?focus_truck_id=$focusTruckId#truck-$focusTruckId");
    exit;
}

// Aktiv/Inaktiv-Planungsstatus eines Fahrzeugs umschalten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_planning') {
    $truckId = (int)$_POST['truck_id'];
    $newState = (int)$_POST['state'];
    $stmtToggle = $pdo->prepare("UPDATE trucks SET is_active_planning = ? WHERE id = ?");
    $stmtToggle->execute([$newState, $truckId]);
    header("Location: dispatcher_board.php?focus_truck_id=$focusTruckId#truck-$focusTruckId");
    exit;
}

// "Abgearbeitet"-Aktion (Job erfolgreich beendet -> LKW-Standort wird zur Zielstadt)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_job') {
    $orderId = (int)$_POST['order_id'];
    $truckId = (int)$_POST['truck_id'];
    
    // Zielort des abgeschlossenen Auftrags ermitteln
    $stmtOrder = $pdo->prepare("SELECT to_city_id FROM orders WHERE id = ?");
    $stmtOrder->execute([$orderId]);
    $toCityId = $stmtOrder->fetchColumn();
    
    if ($toCityId) {
        $pdo->beginTransaction();
        try {
            // 1. Physischen LKW-Standort auf Zielort setzen (PH 4.3.5)
            $stmtUpdateTruck = $pdo->prepare("UPDATE trucks SET current_city_id = ? WHERE id = ?");
            $stmtUpdateTruck->execute([$toCityId, $truckId]);
            
            // 2. Auftrag archivieren (erfolgreich beendet)
            $stmtArchiveOrder = $pdo->prepare("UPDATE orders SET is_archived = 1, completed_at = NOW() WHERE id = ?");
            $stmtArchiveOrder->execute([$orderId]);
            
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
        }
    }
    header("Location: dispatcher_board.php?focus_truck_id=$focusTruckId#truck-$focusTruckId");
    exit;
}

// Archivieren von "Geisteraufträgen"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'archive_pool_order') {
    $orderId = (int)$_POST['order_id'];
    $stmt = $pdo->prepare("UPDATE orders SET is_archived = 1, completed_at = NOW() WHERE id = ?");
    $stmt->execute([$orderId]);
    header("Location: dispatcher_board.php?focus_truck_id=$focusTruckId");
    exit;
}

// Alle disponiblen Fahrzeuge laden (Sortiert nach wenigsten geplanten Tourstopps)
$allTrucks = $pdo->query("
    SELECT t.*, COUNT(o.id) AS job_count
    FROM trucks t
    LEFT JOIN orders o ON t.id = o.assigned_truck_id AND o.is_archived = 0
    GROUP BY t.id
    ORDER BY job_count ASC, t.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Alle Fahrer für schnellen Map-Lookup laden (Vermeidet N+1 Queries)
$allDrivers = $driverRepo->getAllEmployed();
$driverMap = [];
foreach ($allDrivers as $d) {
    $driverMap[$d['ingame_driver_id']] = $d;
}

// KORREKTUR: Städte für Sidebar laden - Zählt und summiert nun ausschließlich Lageraufträge (is_accepted = 1)
$cities = $pdo->query("
    SELECT
        c.id,
        c.name,
        COUNT(o.id) AS total_jobs,
        COALESCE(SUM(o.weight_remaining), 0) AS total_weight
    FROM cities c
    LEFT JOIN orders o ON c.id = o.from_city_id AND o.is_accepted = 1 AND o.is_archived = 0 AND o.weight_remaining > 0 AND o.assigned_truck_id IS NULL
    GROUP BY c.id, c.name
    ORDER BY total_jobs ASC, c.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// --- HELFER-FUNKTIONEN FÜR DEN ALGORITHMUS ---

function isFreightCompatible(string $vehicleType, string $freightType): bool {
    // Hilfsfunktion zur internen Normalisierung von Ingame-Frachtbezeichnungen
    $normalize = function(string $type): string {
        $lower = strtolower($type);
        if (str_contains($lower, 'silo')) return 'Silo';
        if (str_contains($lower, 'flüssig') || str_contains($lower, 'tank')) return 'Tankwagen';
        if (str_contains($lower, 'kühl')) return 'Kühlwagen';
        if (str_contains($lower, 'schütt')) return 'Schüttgut';
        if (str_contains($lower, 'kurier')) return 'Kurier';
        if (str_contains($lower, 'pritsche')) return 'Pritsche';
        if (str_contains($lower, 'iso')) return 'ISO-Container';
        if (str_contains($lower, 'schwer')) return 'Schwertransport';
        if (str_contains($lower, 'koffer')) return 'Koffer';
        if (str_contains($lower, 'plane')) return 'Plane';
        if (str_contains($lower, 'stück')) return 'Stückgut';
        return $type;
    };

    $vType = $normalize($vehicleType);
    $fType = $normalize($freightType);

    if ($vType === $fType) {
        return true;
    }

    // Ihre vollständige fahrzeugübergreifende Kompatibilitätsmatrix (PH 3.3)
    $matrix = [
        'Kurier' => ['Kurier', 'Stückgut', 'Pritsche', 'Plane', 'Koffer'],
        'Stückgut' => ['Stückgut', 'Kurier', 'Pritsche', 'Plane', 'Koffer'],
        'Schüttgut' => ['Schüttgut'],
        'Pritsche' => ['Pritsche', 'Schüttgut'],
        'Plane' => ['Plane', 'Stückgut', 'Pritsche'],
        'Koffer' => ['Koffer', 'Stückgut', 'Pritsche', 'Plane'],
        'Kühlwagen' => ['Kühlwagen', 'Stückgut', 'Pritsche', 'Plane', 'Koffer'],
        'Silo' => ['Silo'],
        'Tankwagen' => ['Tankwagen'],
        'Schwertransport' => ['Schwertransport'],
        'ISO-Container' => ['ISO-Container'],
        'Super-Liner' => ['Super-Liner', 'Stückgut', 'Pritsche', 'Plane', 'Koffer']
    ];

    return in_array($fType, $matrix[$vType] ?? [], true);
}

function get3CityNeighborhood(PDO $pdo, int $cityId): array {
    $stmtNear = $pdo->prepare("
        SELECT city_b_id AS city_id, distance_km FROM distances WHERE city_a_id = :cityId
        UNION
        SELECT city_a_id AS city_id, distance_km FROM distances WHERE city_b_id = :cityId
        ORDER BY distance_km ASC
        LIMIT 2
    ");
    $stmtNear->execute(['cityId' => $cityId]);
    $nearCities = $stmtNear->fetchAll(PDO::FETCH_ASSOC);

    $allowedIds = [$cityId];
    foreach ($nearCities as $nc) {
        $allowedIds[] = (int)$nc['city_id'];
    }
    return $allowedIds;
}

function getCitiesDistance(PDO $pdo, int $cityA, int $cityB): int {
    if (intval($cityA) === intval($cityB)) return 0;
    $cityMin = min($cityA, $cityB);
    $cityMax = max($cityA, $cityB);
    $stmt = $pdo->prepare("SELECT distance_km FROM distances WHERE city_a_id = ? AND city_b_id = ?");
    $stmt->execute([$cityMin, $cityMax]);
    $dist = $stmt->fetchColumn();
    return $dist !== false ? (int)$dist : 999;
}


// --- TOPOLOGY ENGINE: ROUND-ROBIN DISPATCHER & SPLITTING-ALGORITHMUS ---

$activeTrucks = [];
foreach ($allTrucks as $t) {
    if ((int)$t['is_active_planning'] === 1 && !empty($t['assigned_driver_id'])) {
        $activeTrucks[] = $t;
    }
}

$virtualEndpoints = [];
$suggestedChains = [];
$truckDriversAdr = [];

foreach ($activeTrucks as $t) {
    // Letzten geplanten Endpunkt ermitteln
    $lastOrderCity = $pdo->query("
        SELECT to_city_id 
        FROM orders 
        WHERE assigned_truck_id = " . (int)$t['id'] . " AND is_archived = 0 
        ORDER BY assigned_at DESC LIMIT 1
    ")->fetchColumn();
    
    $virtualEndpoints[$t['id']] = $lastOrderCity ? (int)$lastOrderCity : (int)$t['current_city_id'];
    $suggestedChains[$t['id']] = [];

    $adrPermit = 0;
    if (!empty($t['assigned_driver_id']) && isset($driverMap[$t['assigned_driver_id']])) {
        $adrPermit = (int)$driverMap[$t['assigned_driver_id']]['adr_permit'];
    }
    $truckDriversAdr[$t['id']] = $adrPermit;
}

// Gesamten Pool an unverplanten Aufträgen laden (Lager + Börse)
$rawOrders = $pdo->query("
    SELECT o.*, c1.name AS from_city_name, c2.name AS to_city_name
    FROM orders o
    JOIN cities c1 ON o.from_city_id = c1.id
    JOIN cities c2 ON o.to_city_id = c2.id
    WHERE o.is_archived = 0 AND o.assigned_truck_id IS NULL
    ORDER BY o.is_accepted DESC, o.revenue DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Virtueller Arbeits-Pool zur Einhaltung der Exklusivität und des Gewichtssplittings
$virtualOrderPool = [];
foreach ($rawOrders as $ro) {
    $virtualOrderPool[] = [
        'id' => (int)$ro['id'],
        'ingame_order_id' => $ro['ingame_order_id'],
        'freight_type' => $ro['freight_type'],
        'commodity' => $ro['commodity'],
        'is_adr' => (int)$ro['is_adr'],
        'weight_total' => (int)$ro['weight_total'],
        'weight_remaining' => (int)$ro['weight_remaining'],
        'revenue' => (float)$ro['revenue'],
        'from_city_id' => (int)$ro['from_city_id'],
        'to_city_id' => (int)$ro['to_city_id'],
        'from_city_name' => $ro['from_city_name'],
        'to_city_name' => $ro['to_city_name'],
        'is_accepted' => (int)$ro['is_accepted']
    ];
}

$warehouseCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE is_accepted = 1 AND is_archived = 0")->fetchColumn();
$freeMarketSlots = max(0, $maxDispoSlots - $warehouseCount);
$marketOrdersCount = 0;
$maxRounds = 6;

// --- FLOTTENWEITER PROXIMITY-OPTIMIERER (VERMEIDET LEERFAHRTEN GLOBAL) ---
$maxTotalSteps = count($activeTrucks) * 6; // Maximale Gesamt-Schritte für die aktive Flotte
$marketOrdersCount = 0;

for ($step = 0; $step < $maxTotalSteps; $step++) {
    $bestCandidate = null;
    $bestTruckKey = null;

    // Finde flottenweit den absolut nächsten und wirtschaftlichsten Anschluss-Transport
    foreach ($activeTrucks as $truckKey => $t) {
        $truckId = $t['id'];
        
        // Jedes Fahrzeug wird auf maximal 6 geplante Schritte begrenzt
        if (count($suggestedChains[$truckId]) >= 6) {
            continue;
        }

        $currentEndpoint = $virtualEndpoints[$truckId];
        $driverAdr = $truckDriversAdr[$truckId];
        $neighborhood = get3CityNeighborhood($pdo, $currentEndpoint);

        foreach ($virtualOrderPool as $index => $op) {
            if ($op['weight_remaining'] <= 0) continue;
            if (!isFreightCompatible($t['vehicle_type'], $op['freight_type'])) continue;
            if ($op['is_adr'] === 1 && $driverAdr === 0) continue;
            if (!in_array($op['from_city_id'], $neighborhood, true)) continue;
            
            // Slot-Limitierung für Börsenaufträge einhalten
            if ($op['is_accepted'] === 0 && $marketOrdersCount >= $freeMarketSlots) {
                continue;
            }

            $emptyRunDist = getCitiesDistance($pdo, $currentEndpoint, $op['from_city_id']);
            $profitability = $op['revenue'] / $op['weight_total'];

            // Globales Ranking: Niedrigste Leerfahrt-Distanz steht über allem
            if ($bestCandidate === null 
                || $emptyRunDist < $bestCandidate['empty_run_dist'] 
                || ($emptyRunDist === $bestCandidate['empty_run_dist'] && $profitability > $bestCandidate['profitability'])) {
                
                $bestCandidate = [
                    'pool_index' => $index,
                    'order' => $op,
                    'empty_run_dist' => $emptyRunDist,
                    'profitability' => $profitability
                ];
                $bestTruckKey = $truckKey;
            }
        }
    }

    // Wenn flottenweit ein optimaler nächster Schritt gefunden wurde, weise ihn zu
    if ($bestCandidate !== null && $bestTruckKey !== null) {
        $t = $activeTrucks[$bestTruckKey];
        $truckId = $t['id'];
        $poolIndex = $bestCandidate['pool_index'];
        $orderToLoad = &$virtualOrderPool[$poolIndex];

        // Teillieferungs-Splitting berechnen
        $loadedWeight = min($orderToLoad['weight_remaining'], (int)$t['capacity_t']);
        $isSplit = $orderToLoad['weight_remaining'] > (int)$t['capacity_t'];

        $orderToLoad['weight_remaining'] -= $loadedWeight;

        // In die Kette des jeweiligen LKW einhängen
        $suggestedChains[$truckId][] = [
            'order' => $orderToLoad,
            'loaded_weight' => $loadedWeight,
            'is_split' => $isSplit,
            'empty_run_dist' => $bestCandidate['empty_run_dist'],
            'status' => $orderToLoad['is_accepted'] ? 'warehouse' : 'market'
        ];

        // Virtuellen Endpunkt dieses LKW aktualisieren
        $virtualEndpoints[$truckId] = $orderToLoad['to_city_id'];

        if ($orderToLoad['is_accepted'] === 0) {
            $marketOrdersCount++;
        }
    } else {
        // Keine weiteren kompatiblen Zuweisungen für irgendeinen LKW mehr möglich
        break;
    }
}

// Ermittlung absoluter Inkompatibilitäten für das visuelle Alarm-System (PH 4.4.5)
$activeTruckAlerts = [];
foreach ($activeTrucks as $at) {
    $hasAnyCompatible = false;
    $driverHasAdr = isset($driverMap[$at['assigned_driver_id']]) ? (bool)$driverMap[$at['assigned_driver_id']]['adr_permit'] : false;
    
    // Typen-Alias-Mapping
    $mappedFreightTypes = [$at['vehicle_type']];
    if ($at['vehicle_type'] === 'Plane') $mappedFreightTypes[] = 'Plane(Wetterschutz)';
    elseif ($at['vehicle_type'] === 'Koffer') $mappedFreightTypes[] = 'Kofferwagen';
    elseif ($at['vehicle_type'] === 'Kühlwagen') $mappedFreightTypes[] = 'Kühlwaren';
    elseif ($at['vehicle_type'] === 'Tankwagen') $mappedFreightTypes[] = 'Flüssigkeiten';
    elseif ($at['vehicle_type'] === 'Silo') $mappedFreightTypes[] = 'Silotransport Silo';
    elseif ($at['vehicle_type'] === 'Schüttgut') $mappedFreightTypes[] = 'Schüttgutt Schüttgut';
    
    foreach ($virtualOrderPool as $o) {
        if ($o['weight_remaining'] > 0 && isFreightCompatible($at['vehicle_type'], $o['freight_type'])) {
            if ($o['is_adr'] === 0 || $driverHasAdr) {
                $hasAnyCompatible = true;
                break;
            }
        }
    }
    $activeTruckAlerts[$at['id']] = !$hasAnyCompatible;
}

// Fokus-Fahrzeug laden
$focusTruck = null;
if ($focusTruckId) {
    $stmtF = $pdo->prepare("SELECT * FROM trucks WHERE id = ?");
    $stmtF->execute([$focusTruckId]);
    $focusTruck = $stmtF->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Dispatcher Board - TransportBoss</title>
    <link rel="stylesheet" href="main.css">
    <style>
        /* Spezifisches 3-Spalten Workspace-Design (PH 1.4.2) */
        .board-layout {
            display: flex;
            gap: 15px;
            height: calc(100vh - 120px);
            overflow: hidden;
        }
        .board-sidebar {
            width: 250px;
            background-color: #1e1e1e;
            border-right: 1px solid #333;
            overflow-y: auto;
            padding-right: 5px;
        }
        .board-middle {
            width: 320px;
            background-color: #1e1e1e;
            border-right: 1px solid #333;
            overflow-y: auto;
            padding: 0 5px;
        }
        .board-right-workspace {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 15px;
            overflow-y: auto;
            padding-left: 5px;
        }
        .detail-top-half, .detail-bottom-half {
            flex: 1;
            background-color: #252525;
            border: 1px solid #444;
            border-radius: 5px;
            padding: 15px;
            overflow-y: auto;
            box-sizing: border-box;
        }
        /* Kompakte, zweizeilige LKW-Auswahlbuttons (Mitte) */
        .truck-btn {
            background-color: #252525;
            border: 1px solid #444;
            border-radius: 4px;
            padding: 8px 12px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.15s ease-in-out;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .truck-btn:hover {
            background-color: #2d2d2d;
            border-color: #555;
        }
        .truck-btn-active {
            border-left: 4px solid #27ae60;
        }
        .truck-btn-inactive {
            opacity: 0.65;
        }
        .truck-btn-focussed {
            border-color: #f39c12;
            box-shadow: 0 0 8px rgba(243, 156, 18, 0.25);
            background-color: #2d2d2d;
        }
        .btn-row-1, .btn-row-2 {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn-row-1 {
            font-size: 0.9em;
            font-weight: 600;
        }
        .btn-row-2 {
            font-size: 0.8em;
            color: #aaaaaa;
        }
    </style>
</head>
<body>
    <?php require_once 'nav.php'; ?>
    <div class="fluid-container">
        <h1 class="accent-text">Dispatcher Board</h1>
        <div class="board-layout">
            
            <!-- SPALTE 1 (LINKS): Sidebar (Strategic Monitor) -->
            <div class="board-sidebar">
                <h2 class="accent-text" style="font-size: 1.2em; margin-bottom: 10px;">Strategie-Monitor</h2>
                <input type="text" id="cityFilter" class="filter-input" placeholder="Städte filtern (Multisearch)..." style="margin-bottom: 10px; padding: 6px;">
                <table class="data-table" id="sidebarTable" style="font-size: 0.85em;">
                    <thead>
                        <tr>
                            <th>Stadt</th>
                            <th>Jobs (Lager)</th>
                            <th>Bestand (t)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cities as $city): ?>
                            <tr>
                                <td><?= htmlspecialchars($city['name']) ?></td>
                                <td><?= $city['total_jobs'] > 0 ? $city['total_jobs'] : '-' ?></td>
                                <td class="<?= $city['total_weight'] > 0 ? '' : 'status-missing' ?>">
                                    <?php if ($city['total_weight'] > 0): ?>
                                        <?= number_format((float)$city['total_weight'], 0, ',', '.') ?> t
                                    <?php else: ?>
                                        <small style="color: #e74c3c; font-weight: bold;">FEHLT</small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- SPALTE 2 (MITTE): Kompakte LKW-Auswahlbuttons -->
            <div class="board-middle">
                <h2 class="accent-text" style="font-size: 1.2em; margin-bottom: 10px;">Fuhrpark</h2>
                
                <?php foreach ($allTrucks as $truck): ?>
                <?php 
                $truckSuggestions = $suggestedChains[$truck['id']] ?? [];
                
                // Bestimme Warnfarbe bei vollständiger Inkompatibilität
                $isAlert = false;
                if ((int)$truck['is_active_planning'] === 1 && empty($truckSuggestions)) {
                    $isAlert = true;
                }
                $isFocussed = ($truck['id'] == $focusTruckId);
                $driver = $driverMap[$truck['assigned_driver_id']] ?? null;
                
                $alertStyle = $isAlert ? 'border: 2px solid #e74c3c; box-shadow: 0 0 10px rgba(231, 76, 60, 0.3); background-color: rgba(231, 76, 60, 0.05);' : '';
                ?>
                <!-- Zweizeiliger kompakter LKW-Button -->
                <div id="truck-<?= $truck['id'] ?>"
                     class="truck-btn <?= $truck['is_active_planning'] ? 'truck-btn-active' : 'truck-btn-inactive' ?> <?= $isFocussed ? 'truck-btn-focussed' : '' ?>" 
                     onclick="selectTruck(<?= $truck['id'] ?>)"
                     style="<?= $alertStyle ?>">
                    
                    <!-- Reihe 1: Aktiv-Checkbox, Typ & Kapazität, geplante Jobs -->
                    <div class="btn-row-1">
                        <div style="display: flex; align-items: center; gap: 5px;">
                            <form method="post" style="display: inline;" onclick="event.stopPropagation();">
                                <input type="hidden" name="action" value="toggle_planning">
                                <input type="hidden" name="truck_id" value="<?= $truck['id'] ?>">
                                <input type="hidden" name="state" value="<?= $truck['is_active_planning'] ? 0 : 1 ?>">
                                <input type="checkbox" <?= $truck['is_active_planning'] ? 'checked' : '' ?> onchange="this.form.submit()" style="margin: 0; cursor: pointer;">
                            </form>
                            <span><?= htmlspecialchars($truck['vehicle_type']) ?> (<?= $truck['capacity_t'] ?>t)</span>
                        </div>
                        <span class="badge-jobs" style="color: #f39c12; font-weight: bold;"><?= $truck['job_count'] ?? 0 ?> Jobs</span>
                    </div>

                    <!-- Reihe 2: Fahrername, ADR & virtuelles Tourende -->
                    <div class="btn-row-2">
                        <div>
                            <?php if ($driver): ?>
                                <span><?= htmlspecialchars($driver['last_name'] . ', ' . substr($driver['first_name'], 0, 1) . '.') ?></span>
                                <?= $driver['adr_permit'] ? '<span class="adr-badge" style="color: #e74c3c; font-weight: bold; font-size: 0.85em;">[ADR]</span>' : '' ?>
                            <?php else: ?>
                                <span class="text-warning" style="color: #e74c3c; font-style: italic;">Unbesetzt</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php
                            $lastOrder = $orderRepo->getLastOrderForTruck((int)$truck['id']);
                            if ($lastOrder) {
                                $tourEndCity = $pdo->query("SELECT name FROM cities WHERE id = " . (int)$lastOrder['to_city_id'])->fetchColumn();
                                echo '<span class="text-orange" style="color: #f39c12;">➔ ' . htmlspecialchars($tourEndCity) . '</span>';
                            } else {
                                $currentCity = $pdo->query("SELECT name FROM cities WHERE id = " . (int)$truck['current_city_id'])->fetchColumn();
                                echo '<span class="text-blue" style="color: #3498db;">➔ POS: ' . htmlspecialchars($currentCity) . '</span>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- SPALTE 3 (RECHTS): Geteilter Detail-Arbeitsbereich für den Fokus-LKW -->
            <div class="board-right-workspace">
                <?php if ($focusTruck): ?>
                    
                    <!-- OBERE HÄLFTE: Geplante Tour -->
                    <div class="detail-top-half" onclick="event.stopPropagation();">
                        <h3 class="accent-text" style="margin-top: 0; margin-bottom: 10px;">
                            Geplante Tour für LKW ID: <?= htmlspecialchars($focusTruck['ingame_vehicle_id']) ?> (<?= htmlspecialchars($focusTruck['vehicle_type']) ?>)
                        </h3>
                        <table class="suggestion-table" style="font-size: 0.85em;">
                            <thead>
                                <tr>
                                    <th>Erledigt?</th> <!-- GANZ LINKS -->
                                    <th>Typ</th>
                                    <th>Route</th>
                                    <th>Distanz</th>
                                    <th>Tonnage</th>
                                    <th>Erlös</th>
                                    <th>Aktionen</th> <!-- GANZ RECHTS -->
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Korrektur: Nutzt nun die korrekte Fokus-Variable $focusTruck
                                $assignedOrders = $pdo->query("
                                    SELECT o.*, c1.name AS from_city_name, c2.name AS to_city_name
                                    FROM orders o
                                    JOIN cities c1 ON o.from_city_id = c1.id
                                    JOIN cities c2 ON o.to_city_id = c2.id
                                    WHERE o.assigned_truck_id = " . (int)$focusTruck['id'] . "
                                    AND o.is_archived = 0
                                    ORDER BY o.assigned_at ASC
                                ")->fetchAll(PDO::FETCH_ASSOC);

                                if (empty($assignedOrders)) {
                                    echo '<tr><td colspan="7" class="text-center text-muted-italic" style="padding: 20px;">Keine Tour geplant</td></tr>';
                                } else {
                                    $currentCityId = (int)$focusTruck['current_city_id'];
                                    foreach ($assignedOrders as $index => $order) {
                                        $orderFromId = (int)$order['from_city_id'];
                                        $orderToId = (int)$order['to_city_id'];

                                        // Leerfahrten-Berechnung
                                        if ($orderFromId !== $currentCityId) {
                                            $emptyDistance = $distanceService->getDistance($currentCityId, $orderFromId);
                                            $fromCityName = $pdo->query("SELECT name FROM cities WHERE id = $currentCityId")->fetchColumn();
                                            echo '<tr class="row-type-empty" style="opacity: 0.65; background-color: rgba(231, 76, 60, 0.05);">
                                                <td></td>
                                                <td style="color: #e74c3c; font-weight: bold;">LEERFAHRT</td>
                                                <td>' . htmlspecialchars($fromCityName) . ' ➔ ' . htmlspecialchars($order['from_city_name']) . '</td>
                                                <td>' . $emptyDistance . ' km</td>
                                                <td>-</td>
                                                <td>0,00 €</td>
                                                <td>-</td>
                                            </tr>';
                                            $currentCityId = $orderFromId;
                                        }

                                        // Cargo-Berechnung "on the fly" mit echtem LAGER/BÖRSE Status
                                        $jobDistance = $distanceService->getDistance($orderFromId, $orderToId);
                                        $jobTypeLabel = ((int)$order['is_accepted'] === 1) ? 'LAGER' : 'BÖRSE';
                                        $jobTypeColor = ((int)$order['is_accepted'] === 1) ? '#3498db' : '#e67e22';

                                        echo '<tr class="row-type-cargo" style="background-color: rgba(46, 204, 113, 0.05);">
                                            <td>
                                                <!-- Abgearbeitet-Button ganz links zur Vermeidung von Fehlklicks -->
                                                <form method="post" style="display:inline;">
                                                    <input type="hidden" name="action" value="complete_job">
                                                    <input type="hidden" name="order_id" value="' . $order['id'] . '">
                                                    <input type="hidden" name="truck_id" value="' . $focusTruck['id'] . '">
                                                    <button type="submit" class="btn-primary" style="background-color: #27ae60; padding: 4px 8px; font-size: 0.85em; border-radius:3px; border:none; cursor:pointer;">Erledigt</button>
                                                </form>
                                            </td>
                                            <td style="color: ' . $jobTypeColor . '; font-weight: bold;">' . $jobTypeLabel . '</td>
                                            <td>' . htmlspecialchars($order['from_city_name']) . ' ➔ ' . htmlspecialchars($order['to_city_name']) . '</td>
                                            <td>' . $jobDistance . ' km</td>
                                            <td>' . $order['weight_total'] . ' t</td>
                                            <td>' . number_format((float)$order['revenue'], 2, ',', '.') . ' €</td>
                                            <td>
                                                <!-- Entladen-Button ganz rechts -->
                                                <form method="post" style="display:inline;" onsubmit="return confirm(\'Auftrag wirklich entladen?\')">
                                                    <input type="hidden" name="action" value="unload_job">
                                                    <input type="hidden" name="order_id" value="' . $order['id'] . '">
                                                    <button type="submit" class="btn-primary btn-danger" style="padding: 4px 8px; font-size: 0.85em; border-radius:3px; border:none; cursor:pointer;">Entladen</button>
                                                </form>
                                            </td>
                                        </tr>';
                                        $currentCityId = $orderToId;
                                    }
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- UNTERE HÄLFTE: Vorschlagskette (Tagesplanung) -->
                    <div class="detail-bottom-half" onclick="event.stopPropagation();">
                        <h3 class="accent-text" style="margin-top: 0; margin-bottom: 10px;">
                            Vorschlagskette für dieses Fahrzeug (Tagesplanung)
                        </h3>
                        <?php 
                        $focusSuggestions = $suggestedChains[$focusTruck['id']] ?? [];
                        if (!empty($focusSuggestions)): 
                        ?>
                            <table class="suggestion-table" style="font-size: 0.85em;">
                                <thead>
                                    <tr>
                                        <th>Laden?</th> <!-- GANZ LINKS -->
                                        <th>Auftrags-ID</th>
                                        <th>Route</th>
                                        <th>Typ</th>
                                        <th>Gewicht (Ladung)</th>
                                        <th>Erlös</th>
                                        <th>Leerfahrt</th>
                                        <th>Status</th>
                                        <th>Aktion</th> <!-- ARCHIVIEREN GANZ RECHTS -->
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($focusSuggestions as $suggestion): ?>
                                    <?php $order = $suggestion['order']; ?>
                                    <tr style="<?= $suggestion['is_split'] ? 'border-left: 3px solid #f39c12;' : '' ?>">
                                        <td>
                                            <!-- Laden-Button ganz links -->
                                            <form method="post" action="load_job.php">
                                                <input type="hidden" name="truck_id" value="<?= $focusTruck['id'] ?>">
                                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                                <button type="submit" class="btn-primary" style="padding: 4px 8px; font-size: 0.85em; border-radius:3px; border:none; cursor:pointer;">Laden</button>
                                            </form>
                                        </td>
                                        <td><?= htmlspecialchars($order['ingame_order_id'] ?? 'Marktpool') ?></td>
                                        <td>
                                            <?= htmlspecialchars($order['from_city_name']) ?>
                                            ➔ <?= htmlspecialchars($order['to_city_name']) ?>
                                        </td>
                                        <td><?= htmlspecialchars($order['freight_type']) ?></td>
                                        <td>
                                            <?= $suggestion['loaded_weight'] ?> t 
                                            <?= $suggestion['is_split'] ? ' <small style="color:#f39c12;">(Teilladung)</small>' : '' ?>
                                        </td>
                                        <td><?= number_format((float)$order['revenue'], 2, ',', '.') ?> €</td>
                                        <td>
                                            <?= $suggestion['empty_run_dist'] ?> km
                                            <?= $suggestion['empty_run_dist'] > 0 ? ' <small style="color:#e74c3c;">(Anfahrt)</small>' : ' <small style="color:#2ecc71;">(Direkt)</small>' ?>
                                        </td>
                                        <td class="status-<?= $suggestion['status'] ?>">
                                            <?= $suggestion['status'] == 'warehouse' ? 'LAGER' : 'BÖRSE' ?>
                                        </td>
                                        <td>
                                            <!-- Archivieren-Button ganz rechts -->
                                            <form method="post" onsubmit="return confirm('Möchten Sie diesen Vorschlag dauerhaft ausblenden/archivieren? Nachfolgende Glieder passen dann nicht mehr.');">
                                                <input type="hidden" name="action" value="archive_pool_order">
                                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                                <button type="submit" class="btn-primary" style="background-color: #7f8c8d; padding: 4px 8px; font-size: 0.85em; border-radius:3px; border:none; cursor:pointer;">Archivieren</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-suggestions" style="padding: 20px; text-align: center;">
                                <span style="<?= $isAlert ? 'color:#e74c3c; font-weight:bold;' : '' ?>">
                                    <?= $isAlert ? 'ACHTUNG: Keine kompatiblen Aufträge für dieses Fahrzeug im gesamten Pool vorhanden!' : 'Keine Vorschläge für dieses Fahrzeug. Bitte aktivieren Sie den LKW für die Planung.' ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                <?php else: ?>
                    <!-- Platzhalter bei leerem Fokus-Zustand -->
                    <div style="flex: 1; display: flex; align-items: center; justify-content: center; background-color: #252525; border: 1px solid #444; border-radius: 5px;">
                        <span class="text-muted-italic" style="font-size: 1.1em; color: #888;">Bitte wählen Sie ein Fahrzeug aus der mittleren Liste aus, um die Tourplanung zu aktivieren.</span>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- JavaScript für Filter, globalen Toggle und LKW-Fokus (PH 1.4.5) -->
    <script>
        // Steuert den LKW-Fokus und leitet nativ über den HTML-Anker weiter (Keine zuckenden JS-Berechnungen mehr)
        function selectTruck(truckId) {
            if (window.getSelection().toString() !== '') {
                return;
            }
            window.location.href = 'dispatcher_board.php?focus_truck_id=' + truckId;
        }

        // --- Multisearch Filter-Logik für die linke Sidebar (Strategic Monitor) ---
        document.getElementById('cityFilter').addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('#sidebarTable tbody tr');
            const keywords = filter.split(/\s+/).filter(k => k.trim() !== '');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                let match = true;
                
                // Prüfe, ob jedes einzelne Suchwort in der Zeile enthalten ist (UND-Verknüpfung)
                for (let kw of keywords) {
                    if (!text.includes(kw)) {
                        match = false;
                        break;
                    }
                }
                row.style.display = match ? '' : 'none';
            });
        });

        // Tourenplan ein-/ausklappen (globaler Steuerungs-Button)
        document.getElementById('toggleAllTours').addEventListener('click', function() {
            const containers = document.querySelectorAll('.tour-plan-container');
            const isCollapsed = this.textContent.includes('einklappen');
            containers.forEach(container => {
                container.style.display = isCollapsed ? 'none' : 'block';
            });
            this.textContent = isCollapsed ? 'Alle Touren ausklappen' : 'Alle Touren einklappen';
        });

        // Individueller Einklapp-Button für die LKW-Tourliste
        function toggleTourPlan(btn, id) {
            const container = document.getElementById(id);
            if (container.style.display === 'block') {
                container.style.display = 'none';
                btn.textContent = 'Tour einblenden';
            } else {
                container.style.display = 'block';
                btn.textContent = 'Tour ausblenden';
            }
        }

        // Individueller Einklapp-Button für die Vorschlagstabelle
        function toggleSuggestions(btn, id) {
            const container = document.getElementById(id);
            if (container) {
                if (container.style.display === 'none') {
                    container.style.display = 'block';
                    btn.textContent = 'Vorschläge ausblenden';
                } else {
                    container.style.display = 'none';
                    btn.textContent = 'Vorschläge einblenden';
                }
            }
        }
    </script>
</body>
</html>