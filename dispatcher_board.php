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
 * @version 2.1.0
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

// -------------------------------------------------------------
// DYNAMISCHE PLANUNGSMODUS-WEICHE (PH § 9)
// -------------------------------------------------------------
// Aktiven Modus aus der Config auslesen (Fallback auf 'autopilot')
$planningMode = $pdo->query("SELECT cfg_value FROM config WHERE cfg_key = 'planning_mode'")->fetchColumn();
if ($planningMode === false) {
    $planningMode = 'autopilot';
    $pdo->exec("INSERT INTO config (cfg_key, cfg_value) VALUES ('planning_mode', 'autopilot')");
}

// POST-Aktion zur Umschaltung verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_planning_mode') {
    $newMode = $_POST['mode'] === 'radar' ? 'radar' : 'autopilot';
    $stmtUpdateMode = $pdo->prepare("UPDATE config SET cfg_value = ? WHERE cfg_key = 'planning_mode'");
    $stmtUpdateMode->execute([$newMode]);
    
    // Holt die ID des fokussierten Fahrzeugs direkt aus dem Formular-Post
    $redirectId = isset($_POST['focus_truck_id']) ? (int)$_POST['focus_truck_id'] : 0;
    header("Location: dispatcher_board.php?focus_truck_id=" . $redirectId);
    exit;
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
    // KORREKTUR: Wir lesen zuerst den in der DB gespeicherten, letzten Fokus aus!
    $focussedTruck = $pdo->query("SELECT id FROM trucks WHERE is_focussed = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($focussedTruck) {
        $focusTruckId = (int)$focussedTruck['id'];
    } else {
        // Fallback falls gar kein Fokus existiert (z. B. beim ersten Systemstart)
        $firstTruck = $pdo->query("SELECT id FROM trucks WHERE assigned_driver_id IS NOT NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $focusTruckId = $firstTruck ? (int)$firstTruck['id'] : null;
        if ($focusTruckId) {
            $pdo->exec("UPDATE trucks SET is_focussed = 1 WHERE id = $focusTruckId");
        }
    }
}

// Entladen-Aktion (Kaskaden-Storno eines Tour-Auftrags)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unload_job') {
    $orderId = (int)$_POST['order_id'];
    
    // 1. Hole Fahrzeug-ID und Zuweisungs-Zeitstempel des zu entladenden Auftrags
    $stmtInfo = $pdo->prepare("SELECT assigned_truck_id, assigned_at FROM orders WHERE id = ?");
    $stmtInfo->execute([$orderId]);
    $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);
    
    if ($info && $info['assigned_truck_id'] && $info['assigned_at']) {
        $truckId = (int)$info['assigned_truck_id'];
        $assignedAt = $info['assigned_at'];

        // 2. MINIMALINVASIVE SCHNITTSTELLE:
        // Wir laden alle betroffenen Folge-Aufträge dieser Tour chronologisch, um verplante
        // Split-Klone sauber zu löschen und ihre Tonnagen sicher zurückzuerstatten!
        $stmtAffected = $pdo->prepare("
            SELECT id, ingame_order_id, weight_total 
            FROM orders 
            WHERE assigned_truck_id = ? 
              AND is_archived = 0 
              AND assigned_at >= ?
        ");
        $stmtAffected->execute([$truckId, $assignedAt]);
        $affectedOrders = $stmtAffected->fetchAll(PDO::FETCH_ASSOC);

        $pdo->beginTransaction();
        try {
            foreach ($affectedOrders as $ord) {
                $oId = (int)$ord['id'];
                $idn = $ord['ingame_order_id'];

                // Prüfen, ob es sich um einen Split-Klon handelt (IDN enthält einen Bindestrich, z.B. IDN10700463-6)
                if ($idn !== null && str_contains($idn, '-')) {
                    $baseIdn = explode('-', $idn)[0];
                    $loadedWeight = (int)$ord['weight_total'];

                    // A. Tonnage zurück in den unplanbaren Pool-Hauptauftrag überführen
                    $stmtMergeBack = $pdo->prepare("
                        UPDATE orders 
                        SET weight_remaining = weight_remaining + ? 
                        WHERE ingame_order_id = ? 
                          AND is_archived = 0
                    ");
                    $stmtMergeBack->execute([$loadedWeight, $baseIdn]);

                    // B. Den verwaisten Klon-Eintrag vollständig löschen (Vermeidung von Datenmüll)
                    $stmtDeleteClone = $pdo->prepare("DELETE FROM orders WHERE id = ?");
                    $stmtDeleteClone->execute([$oId]);
                } else {
                    // C. Normaler, vollständiger Auftrag: Einfach vom LKW entkoppeln
                    $stmtUnassign = $pdo->prepare("
                        UPDATE orders 
                        SET assigned_truck_id = NULL, 
                            assigned_at = NULL 
                        WHERE id = ?
                    ");
                    $stmtUnassign->execute([$oId]);
                }
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    
    header("Location: dispatcher_board.php?focus_truck_id=$focusTruckId");
    exit;
}

// Aktiv/Inaktiv-Planungsstatus eines Fahrzeugs umschalten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_planning') {
    $truckId = (int)$_POST['truck_id'];
    $newState = (int)$_POST['state'];
    $stmtToggle = $pdo->prepare("UPDATE trucks SET is_active_planning = ? WHERE id = ?");
    $stmtToggle->execute([$newState, $truckId]);
    header("Location: dispatcher_board.php?focus_truck_id=$focusTruckId");
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
    header("Location: dispatcher_board.php?focus_truck_id=$focusTruckId");
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


// --- TOPOLOGY ENGINE: ROUND-ROBIN DISPATCHER & SPLITTING-ALGORITHMUS (AUTOPILOT) ---
// Extrahiert die aktiven Planungsfahrzeuge für die UI-Steuerung
$activeTrucks = [];
foreach ($allTrucks as $t) {
    if ((int)$t['is_active_planning'] === 1 && !empty($t['assigned_driver_id'])) {
        $activeTrucks[] = $t;
    }
}

// Holt die berechneten Ketten direkt über die einheitliche, gekapselte Klassen-Methode (PH § 1.3.1)
$suggestedChains = $topologyEngine->calculateAutopilotChains();

// Ermittlung absoluter Inkompatibilitäten für das visuelle Alarm-System (PH 4.4.5)
// Unabhängige, performante Echtzeit-Abfrage ohne Alt-Abhängigkeiten!
$activeTruckAlerts = [];
$unassignedOrdersForAlert = $pdo->query("
    SELECT freight_type, is_adr 
    FROM orders 
    WHERE is_archived = 0 
      AND assigned_truck_id IS NULL 
      AND weight_remaining > 0
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($activeTrucks as $at) {
    $hasAnyCompatible = false;
    $driverHasAdr = isset($driverMap[$at['assigned_driver_id']]) ? (bool)$driverMap[$at['assigned_driver_id']]['adr_permit'] : false;
    
    foreach ($unassignedOrdersForAlert as $o) {
        if (isFreightCompatible($at['vehicle_type'], $o['freight_type'])) {
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

// Vorschläge basierend auf dem aktiven Planungsmodus deklarieren
$focusSuggestions = [];
if ($focusTruck) {
    if ($planningMode === 'radar') {
        // Taktisches Radar: Wir ermitteln den virtuellen Startpunkt und rufen getRadarScanForTruck auf
        $lastOrderCity = $pdo->query("
            SELECT to_city_id 
            FROM orders 
            WHERE assigned_truck_id = " . (int)$focusTruck['id'] . " AND is_archived = 0 
            ORDER BY assigned_at DESC LIMIT 1
        ")->fetchColumn();
        
        $virtualStartCityId = $lastOrderCity ? (int)$lastOrderCity : (int)$focusTruck['current_city_id'];
        $focusSuggestions = $topologyEngine->getRadarScanForTruck((int)$focusTruck['id'], $virtualStartCityId);
    } else {
        // Autopilot: Wir greifen auf die im Speicher berechnete globale Kette zurück
        $focusSuggestions = $suggestedChains[$focusTruck['id']] ?? [];
    }
}
?>



<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Dispatcher Board - TransportBoss</title>
    <link rel="stylesheet" href="main.css">
</head>
<body class="board-body">
    <?php require_once 'nav.php'; ?>
    <div class="fluid-container">
        <h1 class="accent-text">Dispatcher Board</h1>
        <div class="board-layout">
            
            <!-- SPALTE 1 (LINKS): Sidebar (Strategic Monitor) -->
            <div class="board-sidebar">
                <h2 class="accent-text sidebar-title">Strategie-Monitor</h2>
                <input type="text" id="cityFilter" class="filter-input city-filter-input" placeholder="Städte filtern (Multisearch)...">
                <table class="data-table sidebar-table" id="sidebarTable">
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
                                        <span class="badge-missing">FEHLT</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- SPALTE 2 (MITTE): Kompakte LKW-Auswahlbuttons -->
            <div class="board-middle">
                <h2 class="accent-text sidebar-title">Fuhrpark</h2>
                
                <?php foreach ($allTrucks as $truck): ?>
                <?php 
                $truckSuggestions = $suggestedChains[$truck['id']] ?? [];
                
                // Bestimme Warnzustand bei vollständiger Inkompatibilität
                $isAlert = false;
                if ((int)$truck['is_active_planning'] === 1 && empty($truckSuggestions)) {
                    $isAlert = true;
                }
                $isFocussed = ($truck['id'] == $focusTruckId);
                $driver = $driverMap[$truck['assigned_driver_id']] ?? null;
                ?>
                <!-- Zweizeiliger kompakter LKW-Button (Dynamische Zustände via Klassen abgebildet) -->
                <div id="truck-<?php echo $truck['id']; ?>"
                     class="truck-btn <?php echo $truck['is_active_planning'] ? 'truck-btn-active' : 'truck-btn-inactive'; ?> <?php echo $isFocussed ? 'truck-btn-focussed' : ''; ?>" 
                     onclick="selectTruck(<?php echo $truck['id']; ?>)">
                    
                    <!-- Reihe 1: Aktiv-Checkbox, Typ & Kapazität, geplante Jobs -->
                    <div class="btn-row-1">
                        <div class="truck-btn-header-left">
                            <form method="post" class="checkbox-planning-form" onclick="event.stopPropagation();">
                                <input type="hidden" name="action" value="toggle_planning">
                                <input type="hidden" name="truck_id" value="<?= $truck['id'] ?>">
                                <input type="hidden" name="state" value="<?= $truck['is_active_planning'] ? 0 : 1 ?>">
                                <input type="checkbox" <?= $truck['is_active_planning'] ? 'checked' : '' ?> onchange="this.form.submit()" class="checkbox-planning">
                            </form>
                            <span><?= htmlspecialchars($truck['vehicle_type']) ?> (<?= $truck['capacity_t'] ?>t)</span>
                        </div>
                        <span class="badge-jobs-count"><?= $truck['job_count'] ?? 0 ?> Jobs</span>
                    </div>

                    <!-- Reihe 2: Fahrername, ADR & virtuelles Tourende -->
                    <div class="btn-row-2">
                        <div>
                            <?php if ($driver): ?>
                                <span><?= htmlspecialchars($driver['last_name'] . ', ' . substr($driver['first_name'], 0, 1) . '.') ?></span>
                                <?= $driver['adr_permit'] ? '<span class="adr-badge">[ADR]</span>' : '' ?>
                            <?php else: ?>
                                <span class="driver-unassigned">Unbesetzt</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php
                            $lastOrder = $orderRepo->getLastOrderForTruck((int)$truck['id']);
                            if ($lastOrder) {
                                $tourEndCity = $pdo->query("SELECT name FROM cities WHERE id = " . (int)$lastOrder['to_city_id'])->fetchColumn();
                                // Wenn isAlert wahr ist, färben wir den Zielort in Warnrot
                                $cityClass = $isAlert ? 'text-tour-end-alert' : 'text-tour-end';
                                echo '<span class="' . $cityClass . '">➔ ' . htmlspecialchars($tourEndCity) . '</span>';
                            } else {
                                $currentCity = $pdo->query("SELECT name FROM cities WHERE id = " . (int)$truck['current_city_id'])->fetchColumn();
                                // Wenn isAlert wahr ist, färben wir den POS-Ort in Warnrot
                                $cityClass = $isAlert ? 'text-pos-alert' : 'text-pos';
                                echo '<span class="' . $cityClass . '">➔ POS: ' . htmlspecialchars($currentCity) . '</span>';
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
                        <h3 class="accent-text workspace-title">
                            Geplante Tour für LKW ID: <?= htmlspecialchars($focusTruck['ingame_vehicle_id']) ?> (<?= htmlspecialchars($focusTruck['vehicle_type']) ?>)
                        </h3>
                        <table class="suggestion-table workspace-table">
                            <thead>
                                <tr>
                                    <th>Erledigt?</th>
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
                                    WHERE o.assigned_truck_id = " . (int)$focusTruck['id'] . "
                                    AND o.is_archived = 0
                                    ORDER BY o.assigned_at ASC
                                ")->fetchAll(PDO::FETCH_ASSOC);

                                if (empty($assignedOrders)) {
                                    echo '<tr><td colspan="7" class="text-center text-muted-italic empty-tour-cell">Keine Tour geplant</td></tr>';
                                } else {
                                    $currentCityId = (int)$focusTruck['current_city_id'];
                                    foreach ($assignedOrders as $index => $order) {
                                        $orderFromId = (int)$order['from_city_id'];
                                        $orderToId = (int)$order['to_city_id'];

                                        // Leerfahrten-Berechnung
                                        if ($orderFromId !== $currentCityId) {
                                            $emptyDistance = $distanceService->getDistance($currentCityId, $orderFromId);
                                            $fromCityName = $pdo->query("SELECT name FROM cities WHERE id = $currentCityId")->fetchColumn();
                                            echo '<tr class="row-type-empty">
                                                <td></td>
                                                <td class="text-warning-bold">LEERFAHRT</td>
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
                                        $jobTypeColor = ((int)$order['is_accepted'] === 1) ? 'text-lager' : 'text-market';

                                        // -------------------------------------------------------------
                                        // BERECHNUNG DER VERFÜGBAREN TONNAGE ZUM LADEZEITPUNKT (PLANNED TOUR)
                                        // -------------------------------------------------------------
                                        $baseIdCond = "";
                                        $params = [];
                                        if (!empty($order['ingame_order_id'])) {
                                            $baseId = explode('-', $order['ingame_order_id'])[0];
                                            $baseIdCond = "ingame_order_id LIKE :base_id";
                                            $params['base_id'] = $baseId . '%';
                                        } else {
                                            $baseIdCond = "fingerprint = :fingerprint";
                                            $params['fingerprint'] = $order['fingerprint'];
                                        }

                                        // Summe aller Segmente, die nach diesem geladen wurden (oder zeitgleich bei höherer technischer ID)
                                        $stmtSum = $pdo->prepare("
                                            SELECT COALESCE(SUM(weight_total), 0) 
                                            FROM orders 
                                            WHERE $baseIdCond 
                                              AND is_archived = 0 
                                              AND assigned_truck_id IS NOT NULL 
                                              AND (assigned_at > :assigned_at OR (assigned_at = :assigned_at AND id > :current_id))
                                        ");
                                        $params['assigned_at'] = $order['assigned_at'];
                                        $params['current_id'] = $order['id'];
                                        $stmtSum->execute($params);
                                        $futureLoadedSum = (int)$stmtSum->fetchColumn();

                                        // Unverplante Restmengen im Lager ermitteln
                                        $stmtUnassigned = $pdo->prepare("
                                            SELECT COALESCE(SUM(weight_remaining), 0) 
                                            FROM orders 
                                            WHERE $baseIdCond 
                                              AND is_archived = 0 
                                              AND assigned_truck_id IS NULL
                                        ");
                                        $unassignedParams = [];
                                        if (!empty($order['ingame_order_id'])) {
                                            $unassignedParams['base_id'] = $baseId . '%';
                                        } else {
                                            $unassignedParams['fingerprint'] = $order['fingerprint'];
                                        }
                                        $stmtUnassigned->execute($unassignedParams);
                                        $unassignedSum = (int)$stmtUnassigned->fetchColumn();

                                        // Tonnage zum Ladezeitpunkt = Eigene Menge + Später geladene Mengen + Unverplante Restmengen
                                        $availableAtLoading = (int)$order['weight_total'] + $futureLoadedSum + $unassignedSum;

                                        echo '<tr class="row-type-cargo">
                                            <td>
                                                <!-- Abgearbeitet-Button ganz links zur Vermeidung von Fehlklicks -->
                                                <form method="post" class="inline-form">
                                                    <input type="hidden" name="action" value="complete_job">
                                                    <input type="hidden" name="order_id" value="' . $order['id'] . '">
                                                    <input type="hidden" name="truck_id" value="' . $focusTruck['id'] . '">
                                                    <button type="submit" class="btn-primary btn-complete">Erledigt</button>
                                                </form>
                                            </td>
                                            <td class="' . $jobTypeColor . '">' . $jobTypeLabel . '</td>
                                            <td>' . htmlspecialchars($order['from_city_name']) . ' ➔ ' . htmlspecialchars($order['to_city_name']) . '</td>
                                            <td>' . $jobDistance . ' km</td>
                                            <td>' . $order['weight_remaining'] . ' t / ' . $availableAtLoading . ' t</td>
                                            <td>' . number_format((float)$order['revenue'], 2, ',', '.') . ' €</td>
                                            <td>
                                                <!-- Entladen-Button ganz rechts -->
                                                <form method="post" class="inline-form" onsubmit="return confirm(\'Auftrag wirklich entladen?\')">
                                                    <input type="hidden" name="action" value="unload_job">
                                                    <input type="hidden" name="order_id" value="' . $order['id'] . '">
                                                    <button type="submit" class="btn-primary btn-danger btn-unload">Entladen</button>
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
                        <div class="workspace-header-row">
                            <h3 class="accent-text workspace-title">Vorschlagskette für dieses Fahrzeug (Tagesplanung)</h3>
                            <form method="post" class="planning-mode-toggle-form">
                                <input type="hidden" name="action" value="toggle_planning_mode">
                                <input type="hidden" name="focus_truck_id" value="<?php echo $focusTruck['id']; ?>">
                                <span class="toggle-label">Modus:</span>
                                <button type="submit" name="mode" value="autopilot" class="btn-toggle <?php echo $planningMode === 'autopilot' ? 'active' : ''; ?>">🤖 Autopilot</button>
                                <button type="submit" name="mode" value="radar" class="btn-toggle <?php echo $planningMode === 'radar' ? 'active' : ''; ?>">📡 Taktisches Radar</button>
                            </form>
                        </div>
                        <?php 
                        if (!empty($focusSuggestions)): 
                        ?>
                            <table class="suggestion-table workspace-table">
                                <thead>
                                    <tr>
                                        <th>Laden?</th> <!-- GANZ LINKS -->
                                        <th>Auftrags-ID</th>
                                        <th>Route</th>
                                        <th>Typ</th>
                                        <th>Gewicht (Ladung)</th>
                                        <th>Erlös</th>
                                        <th>Leerfahrt</th>
                                        <?php if ($planningMode === 'radar'): ?>
                                            <th>Ketten-Radar</th>
                                        <?php endif; ?>
                                        <th>Status</th>
                                        <th>Aktion</th> <!-- ARCHIVIEREN GANZ RECHTS -->
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($focusSuggestions as $suggestion): ?>
                                    <?php $order = $suggestion['order']; ?>
                                    <tr class="<?php echo $suggestion['is_split'] ? 'row-split-load' : ''; ?>">
                                        <td>
                                            <!-- Laden-Button ganz links -->
                                            <form method="post" action="load_job.php">
                                                <input type="hidden" name="truck_id" value="<?php echo $focusTruck['id']; ?>">
                                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                <button type="submit" class="btn-primary btn-load">Laden</button>
                                            </form>
                                        </td>
                                        <td><?php echo htmlspecialchars($order['ingame_order_id'] ?? 'Marktpool'); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($order['from_city_name']); ?>
                                            ➔ <?php echo htmlspecialchars($order['to_city_name']); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($order['freight_type']); ?></td>
                                        <td>
                                            <?php echo $suggestion['loaded_weight']; ?> t / <?php echo $suggestion['available_weight']; ?> t
                                        </td>
                                        <td><?php echo number_format((float)$order['revenue'], 2, ',', '.'); ?> €</td>
                                        <td>
                                            <?php echo $suggestion['empty_run_dist']; ?> km
                                            <?php echo $suggestion['empty_run_dist'] > 0 ? ' <small class="text-anfahrt">(Anfahrt)</small>' : ' <small class="text-direkt">(Direkt)</small>'; ?>
                                        </td>
                                        <?php if ($planningMode === 'radar'): ?>
                                            <td>
                                                <?php 
                                                $radar = $suggestion['radar_indicator'];
                                                $radarClass = 'radar-type-' . $radar['type'];
                                                echo '<span class="' . $radarClass . '">' . htmlspecialchars($radar['label']) . '</span>';
                                                ?>
                                            </td>
                                        <?php endif; ?>
                                        <td class="status-<?php echo $suggestion['status']; ?>">
                                            <?php echo $suggestion['status'] == 'warehouse' ? 'LAGER' : 'BÖRSE'; ?>
                                        </td>
                                        <td>
                                            <!-- Archivieren-Button ganz rechts -->
                                            <form method="post" onsubmit="return confirm('Möchten Sie diesen Vorschlag dauerhaft ausblenden/archivieren? Nachfolgende Glieder passen dann nicht mehr.');">
                                                <input type="hidden" name="action" value="archive_pool_order">
                                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                <button type="submit" class="btn-primary btn-archive-action">Archivieren</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-suggestions-container">
                                <span class="<?= $isAlert ? 'text-alert-active' : '' ?>">
                                    <?= $isAlert ? 'ACHTUNG: Keine kompatiblen Aufträge für dieses Fahrzeug im gesamten Pool vorhanden!' : 'Keine Vorschläge für dieses Fahrzeug. Bitte aktivieren Sie den LKW für die Planung.' ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                <?php else: ?>
                    <!-- Platzhalter bei leerem Fokus-Zustand -->
                    <div class="workspace-placeholder">
                        <span class="text-muted-italic placeholder-text">Bitte wählen Sie ein Fahrzeug aus der mittleren Liste aus, um die Tourplanung zu aktivieren.</span>
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
            window.location.href = "dispatcher_board.php?focus_truck_id=" + truckId;
        }

        // --- Multisearch Filter-Logik (UND-Verknüpfung mehrerer Wörter) ---
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
    </script>
</body>
</html>