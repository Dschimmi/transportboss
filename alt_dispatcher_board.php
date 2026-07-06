<?php
declare(strict_types=1);

/**
 * dispatcher_board.php
 *
 * Das zentrale Dispositions-Tableau (Dispatcher Board) von TransportBoss.
 * Verwaltet das interaktive Fuhrpark-Board, berechnet die dynamische Slot-Zuteilung,
 * die topologische Vorschlagskette pro Fahrzeug (Round-Robin) und stellt das
 * geografische Frühwarnsystem (Strategic Monitor) links bereit.
 *
 * @author TransportBoss Development
 * @version 1.2.0
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
// Berechnet das tagesaktuelle Slot-Limit auf Basis der angestellten Disponenten
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
    $orderRepo->unassignFromTruck($orderId);
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
            // 1. Physischen LKW-Standort auf Zielort setzen
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

    // Ihre vollständige fahrzeugübergreifende Kompatibilitätsmatrix
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
    if ($cityA === $cityB) return 0;
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

for ($round = 0; $round < $maxRounds; $round++) {
    $anyAssignmentInRound = false;

    foreach ($activeTrucks as $t) {
        $truckId = $t['id'];
        $currentEndpoint = $virtualEndpoints[$truckId];
        $driverAdr = $truckDriversAdr[$truckId];

        $neighborhood = get3CityNeighborhood($pdo, $currentEndpoint);

        $candidates = [];
        foreach ($virtualOrderPool as $index => $op) {
            if ($op['weight_remaining'] <= 0) continue;
            if (!isFreightCompatible($t['vehicle_type'], $op['freight_type'])) continue;
            if ($op['is_adr'] === 1 && $driverAdr === 0) continue;
            if (!in_array($op['from_city_id'], $neighborhood, true)) continue;
            if ($op['is_accepted'] === 0 && $marketOrdersCount >= $freeMarketSlots) continue;

            $emptyRunDist = getCitiesDistance($pdo, $currentEndpoint, $op['from_city_id']);

            $candidates[] = [
                'pool_index' => $index,
                'order' => $op,
                'empty_run_dist' => $emptyRunDist,
                'profitability' => $op['revenue'] / $op['weight_total']
            ];
        }

        if (!empty($candidates)) {
            usort($candidates, function($a, $b) {
                if ($a['empty_run_dist'] !== $b['empty_run_dist']) {
                    return $a['empty_run_dist'] <=> $b['empty_run_dist'];
                }
                return $b['profitability'] <=> $a['profitability'];
            });

            $best = $candidates[0];
            $poolIndex = $best['pool_index'];
            $orderToLoad = &$virtualOrderPool[$poolIndex];

            $loadedWeight = min($orderToLoad['weight_remaining'], (int)$t['capacity_t']);
            $isSplit = $orderToLoad['weight_remaining'] > (int)$t['capacity_t'];

            $orderToLoad['weight_remaining'] -= $loadedWeight;

            $suggestedChains[$truckId][] = [
                'order' => $orderToLoad,
                'loaded_weight' => $loadedWeight,
                'is_split' => $isSplit,
                'empty_run_dist' => $best['empty_run_dist'],
                'status' => $orderToLoad['is_accepted'] ? 'warehouse' : 'market'
            ];

            $virtualEndpoints[$truckId] = $orderToLoad['to_city_id'];

            if ($orderToLoad['is_accepted'] === 0) {
                $marketOrdersCount++;
            }

            $anyAssignmentInRound = true;
        }
    }

    if (!$anyAssignmentInRound) {
        break;
    }
}

// Ermittlung absoluter Inkompatibilitäten für das visuelle Alarm-System
$activeTruckAlerts = [];
foreach ($activeTrucks as $at) {
    $hasAnyCompatible = false;
    $driverHasAdr = isset($driverMap[$at['assigned_driver_id']]) ? (bool)$driverMap[$at['assigned_driver_id']]['adr_permit'] : false;
    
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
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Dispatcher Board - TransportBoss</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <?php require_once 'nav.php'; ?>
    <div class="fluid-container">
        <h1 class="accent-text">Dispatcher Board</h1>
        <div class="board-layout">
            <!-- LINKE SPALTE: Sidebar (Strategie-Monitor) -->
            <div class="board-sidebar">
                <h2 class="accent-text">Strategie-Monitor</h2>
                <!-- Multisearch Filter-Feld für Städte -->
                <input type="text" id="cityFilter" class="filter-input" placeholder="Städte filtern (Multisearch möglich)...">
                <table class="data-table" id="sidebarTable">
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

            <!-- RECHTE SPALTE: Fuhrpark-Board -->
            <div class="board-main">
                <h2 class="accent-text">Fuhrpark-Board</h2>

                <!-- Steuerungs-Modul -->
                <div class="board-controls">
                    <button id="toggleAllTours" class="btn-primary">Alle Touren einklappen</button>
                </div>

                <!-- Fahrzeugkarten -->
                <div class="truck-grid">
                    <?php foreach ($allTrucks as $truck): ?>
                    <?php 
                    $truckSuggestions = $suggestedChains[$truck['id']] ?? [];
                    
                    // Bestimme Warnfarbe bei vollständiger Inkompatibilität
                    $isAlert = false;
                    if ((int)$truck['is_active_planning'] === 1 && empty($truckSuggestions)) {
                        $isAlert = true;
                    }
                    $isFocussed = ($truck['id'] == $focusTruckId);
                    ?>
                    <div id="truck-<?= $truck['id'] ?>"
                        class="truck-card <?= $truck['is_active_planning'] ? 'truck-card-active' : 'truck-card-inactive' ?> <?= $isFocussed ? 'truck-card-focussed' : '' ?>" 
                        onclick="selectTruck(<?= $truck['id'] ?>)"
                        style="<?= $alertStyle ?>">
                        
                        <!-- Header -->
                        <div class="card-header">
                            <div>
                                <div class="text-orange">
                                    ID: <?= htmlspecialchars($truck['ingame_vehicle_id']) ?> | <?= htmlspecialchars($truck['vehicle_type']) ?>
                                </div>
                                <?php
                                $driver = $driverMap[$truck['assigned_driver_id']] ?? null;
                                if ($driver):
                                ?>
                                    <div class="text-white">
                                        <?= htmlspecialchars($driver['last_name'] . ', ' . substr($driver['first_name'], 0, 1) . '.') ?>
                                        <?php if ($driver['adr_permit']): ?>
                                            <span class="adr-badge">[ADR]</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-header-right">
                                <div class="text-gray"><?= $truck['capacity_t'] ?> t</div>
                                <div><span class="badge-jobs"><?= $truck['job_count'] ?? 0 ?> Jobs</span></div>
                                <div style="margin-top: 5px;" onclick="event.stopPropagation();">
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="action" value="toggle_planning">
                                        <input type="hidden" name="truck_id" value="<?= $truck['id'] ?>">
                                        <input type="hidden" name="state" value="<?= $truck['is_active_planning'] ? 0 : 1 ?>">
                                        <button type="submit" class="btn-primary" style="padding: 2px 6px; font-size: 0.8em; border-radius: 3px; background-color: <?= $truck['is_active_planning'] ? '#27ae60' : '#7f8c8d' ?>; border: none; cursor: pointer;">
                                            <?= $truck['is_active_planning'] ? 'Aktiv' : 'Inaktiv' ?>
                                        </button>
                                    </form>
                                    <!-- Individueller Einklapp-Button -->
                                    <button class="btn-primary" style="padding: 2px 6px; font-size: 0.8em; background-color: #34495e; border: none; border-radius: 3px; cursor: pointer; margin-left: 5px;" onclick="toggleTourPlan(this, 'tour-<?= $truck['id'] ?>')">
                                        Tour ausblenden
                                    </button>
                                    <?php if ($isFocussed && !empty($truckSuggestions)): ?>
                                        <button class="btn-primary" style="padding: 2px 6px; font-size: 0.8em; background-color: #d35400; border: none; border-radius: 3px; cursor: pointer; margin-left: 5px;" onclick="toggleSuggestions(this, 'sugg-<?= $truck['id'] ?>')">
                                            Vorschläge ausblenden
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Tourende (dynamisch) -->
                        <div class="card-tour-end">
                            <?php
                            $lastOrder = $orderRepo->getLastOrderForTruck((int)$truck['id']);
                            if ($lastOrder) {
                                $tourEndCity = $pdo->query("SELECT name FROM cities WHERE id = " . (int)$lastOrder['to_city_id'])->fetchColumn();
                                echo '<span class="text-orange">➔ ' . htmlspecialchars($tourEndCity) . '</span>';
                            } else {
                                $currentCity = $pdo->query("SELECT name FROM cities WHERE id = " . (int)$truck['current_city_id'])->fetchColumn();
                                echo '<span class="text-blue">➔ POS: ' . htmlspecialchars($currentCity) . '</span>';
                            }
                            ?>
                        </div>

                        <!-- Tourenplan (einklappbar) -->
                        <div class="tour-plan-container" id="tour-<?= $truck['id'] ?>" onclick="event.stopPropagation();" style="display: block;">
                            <table class="suggestion-table">
                                <thead>
                                    <tr>
                                        <th>Typ</th>
                                        <th>Route</th>
                                        <th>Distanz</th>
                                        <th>Tonnage</th>
                                        <th>Erlös</th>
                                        <th>Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $assignedOrders = $pdo->query("
                                        SELECT o.*, c1.name AS from_city_name, c2.name AS to_city_name
                                        FROM orders o
                                        JOIN cities c1 ON o.from_city_id = c1.id
                                        JOIN cities c2 ON o.to_city_id = c2.id
                                        WHERE o.assigned_truck_id = " . (int)$truck['id'] . "
                                        AND o.is_archived = 0
                                        ORDER BY o.assigned_at ASC
                                    ")->fetchAll(PDO::FETCH_ASSOC);

                                    if (empty($assignedOrders)) {
                                        echo '<tr><td colspan="6" class="text-center text-muted-italic">Keine Tour geplant</td></tr>';
                                    } else {
                                        $currentCityId = (int)$truck['current_city_id'];
                                        foreach ($assignedOrders as $index => $order) {
                                            $orderFromId = (int)$order['from_city_id'];
                                            $orderToId = (int)$order['to_city_id'];

                                            // 1. Prüfe, ob eine Leerfahrt zum Startpunkt dieses Auftrags notwendig ist
                                            if ($orderFromId !== $currentCityId) {
                                                $emptyDistance = $distanceService->getDistance($currentCityId, $orderFromId);
                                                $fromCityName = $pdo->query("SELECT name FROM cities WHERE id = $currentCityId")->fetchColumn();
                                                echo '<tr class="row-type-empty">
                                                    <td>LEERFAHRT</td>
                                                    <td>' . htmlspecialchars($fromCityName) . ' ➔ ' . htmlspecialchars($order['from_city_name']) . '</td>
                                                    <td>' . $emptyDistance . ' km</td>
                                                    <td>-</td>
                                                    <td>0,00 €</td>
                                                    <td>-</td>
                                                </tr>';
                                                $currentCityId = $orderFromId; // LKW steht nun am Startpunkt des Auftrags
                                            }

                                            // 2. Berechne und render die eigentliche JOB-Etappe "on the fly"
                                            $jobDistance = $distanceService->getDistance($orderFromId, $orderToId);

                                            $jobTypeLabel = ((int)$order['is_accepted'] === 1) ? 'LAGER' : 'BÖRSE';
                                            $jobTypeColor = ((int)$order['is_accepted'] === 1) ? '#3498db' : '#e67e22';

                                            echo '<tr class="row-type-cargo">
                                                <td style="color: ' . $jobTypeColor . '; font-weight: bold;">' . $jobTypeLabel . '</td>
                                                <td>' . htmlspecialchars($order['from_city_name']) . ' ➔ ' . htmlspecialchars($order['to_city_name']) . '</td>
                                                <td>' . $jobDistance . ' km</td>
                                                <td>' . $order['weight_total'] . ' t</td>
                                                <td>' . number_format((float)$order['revenue'], 2, ',', '.') . ' €</td>
                                                <td>
                                                    <div style="display: flex; gap: 5px;" onclick="event.stopPropagation();">
                                                        <!-- Abgearbeitet-Button -->
                                                        <form method="post" style="display:inline;">
                                                            <input type="hidden" name="action" value="complete_job">
                                                            <input type="hidden" name="order_id" value="' . $order['id'] . '">
                                                            <input type="hidden" name="truck_id" value="' . $truck['id'] . '">
                                                            <button type="submit" class="btn-primary" style="background-color: #27ae60; padding: 4px 8px; font-size: 0.85em; border-radius:3px; border:none; cursor:pointer;">Erledigt</button>
                                                        </form>
                                                        <!-- Entladen-Button -->
                                                        <form method="post" style="display:inline;" onsubmit="return confirm(\'Auftrag wirklich entladen?\')">
                                                            <input type="hidden" name="action" value="unload_job">
                                                            <input type="hidden" name="order_id" value="' . $order['id'] . '">
                                                            <button type="submit" class="btn-primary btn-danger" style="padding: 4px 8px; font-size: 0.85em; border-radius:3px; border:none; cursor:pointer;">Entladen</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>';
                                            
                                            // Nach Beendigung des Auftrags steht der LKW physisch an der Zielstadt
                                            $currentCityId = $orderToId;
                                        }
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Vorschlagsliste (nur für fokussiertes Fahrzeug) -->
                        <?php if ($truck['id'] == $focusTruckId): ?>
                            <?php if (!empty($truckSuggestions)): ?>
                            <div class="suggestions-wrapper" id="sugg-<?= $truck['id'] ?>" onclick="event.stopPropagation();">
                                <h4 class="accent-text">
                                    Vorschlagskette für dieses Fahrzeug (Tagesplanung)
                                </h4>
                                <table class="suggestion-table">
                                    <thead>
                                        <tr>
                                            <th>Auftrags-ID</th>
                                            <th>Route</th>
                                            <th>Typ</th>
                                            <th>Gewicht (Ladung)</th>
                                            <th>Erlös</th>
                                            <th>Leerfahrt</th>
                                            <th>Status</th>
                                            <th>Aktion</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($truckSuggestions as $suggestion): ?>
                                        <?php $order = $suggestion['order']; ?>
                                        <tr style="<?= $suggestion['is_split'] ? 'border-left: 3px solid #f39c12;' : '' ?>">
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
                                                <form method="post" action="load_job.php" onclick="event.stopPropagation();">
                                                    <input type="hidden" name="truck_id" value="<?= $truck['id'] ?>">
                                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                                    <button type="submit" class="btn-primary">Laden</button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="empty-suggestions">
                                <span style="<?= $isAlert ? 'color:#e74c3c; font-weight:bold;' : '' ?>">
                                    <?= $isAlert ? 'ACHTUNG: Keine kompatiblen Aufträge für dieses Fahrzeug im gesamten Pool vorhanden!' : 'Keine Vorschläge für dieses Fahrzeug. Bitte aktivieren Sie den LKW für die Planung.' ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>

                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript für Filter, Tourenplan ein-/ausklappen und Fokus-Erhalt -->
    <script>

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

        // Falls der Benutzer gerade Text markiert hat, die Weiterleitung blockieren
        function selectTruck(truckId) {
            if (window.getSelection().toString() !== '') {
                return;
            }
            window.location.href = '?focus_truck_id=' + truckId;
        }

        // --- Multisearch Filter-Logik für die linke Sidebar ---
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

        // Tourenplan ein-/ausklappen (global)
        document.getElementById('toggleAllTours').addEventListener('click', function() {
            const containers = document.querySelectorAll('.tour-plan-container');
            const isCollapsed = this.textContent.includes('einklappen');
            containers.forEach(container => {
                container.style.display = isCollapsed ? 'none' : 'block';
            });
            this.textContent = isCollapsed ? 'Alle Touren ausklappen' : 'Alle Touren einklappen';
        });

        // Individueller Einklapp-Button
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

        // Steuert den LKW-Fokus und speichert bei Klick die exakte Position der angeklickten Karte
        function selectTruck(truckId) {
            if (window.getSelection().toString() !== '') {
                return;
            }
            window.location.href = 'dispatcher_board.php?focus_truck_id=' + truckId + '#truck-' + truckId;
        }
    </script>
</body>
</html>