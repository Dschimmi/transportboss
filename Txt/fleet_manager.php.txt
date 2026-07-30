<?php
declare(strict_types=1);

/**
 * fleet_manager.php
 *
 * Das zentrale Fuhrpark-Management von TransportBoss.
 * Ermöglicht den Kauf von Neuwagen, die Übernahme von Gebrauchtwagen,
 * die Zuweisung von Fahrern (inkl. automatischer ADR-Sackgassen-Prüfung)
 * sowie das Einlesen von Ingame-Fahrzeugdaten via OwnFleetParser.
 *
 * @author TransportBoss Development
 * @version 2.2.0
 */

require_once 'db_connect.php';
require_once 'classes/OwnFleetParser.php';

use classes\OwnFleetParser;

// Session-basiertes Feedback zur Vermeidung von F5-Doppelposts
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$message = $_SESSION['pb_fleet_message'] ?? '';
$messageClass = $_SESSION['pb_fleet_message_class'] ?? '';
unset($_SESSION['pb_fleet_message'], $_SESSION['pb_fleet_message_class']);

// Bestimme die aktive Ansicht (active, new, market, parser)
$view = $_GET['view'] ?? 'active';

// Predefinierter Katalog für Neuwagen (PH § Neuwagendetails)
$newCarCatalog = [
    'Kurier' => ['capacity' => 2, 'built' => 2018, 'consumption' => 9.0, 'tank' => 60, 'speed' => 140, 'price' => 22000.0, 'freight_label' => 'Kurier', 'enum_val' => 'Kurier'],
    'Stückgut' => ['capacity' => 6, 'built' => 2018, 'consumption' => 10.0, 'tank' => 75, 'speed' => 120, 'price' => 35000.0, 'freight_label' => 'Stückgut', 'enum_val' => 'Stückgut'],
    'Pritsche' => ['capacity' => 18, 'built' => 2018, 'consumption' => 24.0, 'tank' => 300, 'speed' => 90, 'price' => 95000.0, 'freight_label' => 'Pritsche', 'enum_val' => 'Pritsche'],
    'Plane(Wetterschutz)' => ['capacity' => 26, 'built' => 2018, 'consumption' => 28.0, 'tank' => 400, 'speed' => 90, 'price' => 165000.0, 'freight_label' => 'Plane(Wetterschutz)', 'enum_val' => 'Plane'],
    'Kofferwagen' => ['capacity' => 24, 'built' => 2018, 'consumption' => 30.0, 'tank' => 400, 'speed' => 90, 'price' => 195000.0, 'freight_label' => 'Kofferwagen', 'enum_val' => 'Koffer'],
    'Kühlwaren' => ['capacity' => 22, 'built' => 2018, 'consumption' => 34.0, 'tank' => 340, 'speed' => 90, 'price' => 220000.0, 'freight_label' => 'Kühlwaren', 'enum_val' => 'Kühlwagen'],
    'Schüttgut' => ['capacity' => 26, 'built' => 2018, 'consumption' => 35.0, 'tank' => 300, 'speed' => 80, 'price' => 120000.0, 'freight_label' => 'Schüttgut', 'enum_val' => 'Schüttgut'],
    'Silo' => ['capacity' => 25, 'built' => 2018, 'consumption' => 30.0, 'tank' => 380, 'speed' => 80, 'price' => 200000.0, 'freight_label' => 'Silo', 'enum_val' => 'Silo'],
    'Flüssigkeiten' => ['capacity' => 20, 'built' => 2018, 'consumption' => 35.0, 'tank' => 380, 'speed' => 80, 'price' => 220000.0, 'freight_label' => 'Flüssigkeiten', 'enum_val' => 'Tankwagen'],
    'ISO-Container' => ['capacity' => 27, 'built' => 2018, 'consumption' => 30.0, 'tank' => 400, 'speed' => 90, 'price' => 195000.0, 'freight_label' => 'ISO-Container', 'enum_val' => 'ISO-Container'],
    'Schwertransport' => ['capacity' => 70, 'built' => 2018, 'consumption' => 50.0, 'tank' => 700, 'speed' => 60, 'price' => 285000.0, 'freight_label' => 'Schwertransport', 'enum_val' => 'Schwertransport'],
    'Superliner' => ['capacity' => 44, 'built' => 2018, 'consumption' => 40.0, 'tank' => 600, 'speed' => 80, 'price' => 365000.0, 'freight_label' => 'Superliner', 'enum_val' => 'Super-Liner']
];

// -------------------------------------------------------------
// POST-AKTIONEN VERARBEITEN (TRANSAKTIONSSICHER)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];
        $redirectUrl = 'fleet_manager.php';

        // 1. Neuwagen kaufen
        if ($action === 'buy_new') {
            $classKey = $_POST['vehicle_class'];
            $ingameId = trim($_POST['ingame_vehicle_id']);
            $cityId = (int)$_POST['current_city_id'];

            if (!isset($newCarCatalog[$classKey])) {
                throw new Exception("Ungültige Fahrzeugklasse ausgewählt.");
            }
            if ($ingameId === '') {
                throw new Exception("Die Ingame-LKW-ID ist zwingend erforderlich.");
            }

            $specs = $newCarCatalog[$classKey];

            $stmt = $pdo->prepare("
                INSERT INTO trucks (ingame_vehicle_id, user_label, vehicle_type, capacity_t, year_built, km_stand, condition_pct, current_city_id)
                VALUES (?, '', ?, ?, ?, 0, 100.00, ?)
            ");
            $stmt->execute([
                $ingameId,
                $specs['enum_val'],
                $specs['capacity'],
                $specs['built'],
                $cityId
            ]);

            $_SESSION['pb_fleet_message'] = "Neufahrzeug erfolgreich erworben und am gewählten Standort bereitgestellt.";
            $_SESSION['pb_fleet_message_class'] = "status-success";
        }

        // 2. Gebrauchtwagen-Übernahme (Direktkauf aus market_history)
        elseif ($action === 'buy_used') {
            $marketId = (int)$_POST['market_vehicle_id'];

            // Hole Fahrzeugdaten aus dem Preisarchiv
            $stmtHist = $pdo->prepare("SELECT * FROM market_history WHERE id = ?");
            $stmtHist->execute([$marketId]);
            $usedCar = $stmtHist->fetch(PDO::FETCH_ASSOC);

            if (!$usedCar) {
                throw new Exception("Das gewählte Angebot existiert nicht mehr im Preisarchiv.");
            }

            // Auflösung des Verkaufsorts in eine lizensierte Stadt-ID
            $stmtCity = $pdo->prepare("SELECT id FROM cities WHERE name = ?");
            $stmtCity->execute([$usedCar['location_label']]);
            $cityId = $stmtCity->fetchColumn();

            if ($cityId === false) {
                // Falls der Standort nicht lizensiert ist, weisen wir die erste lizensierte Stadt als Standard zu
                $cityId = $pdo->query("SELECT id FROM cities ORDER BY id ASC LIMIT 1")->fetchColumn();
            }

            $pdo->beginTransaction();

            // Erstelle das Fahrzeug im aktiven Fuhrpark mit übernommenem Zustand
            $stmtInsert = $pdo->prepare("
                INSERT INTO trucks (ingame_vehicle_id, user_label, vehicle_type, capacity_t, year_built, km_stand, condition_pct, current_city_id)
                VALUES (?, '', ?, ?, ?, ?, ?, ?)
            ");
            $stmtInsert->execute([
                $usedCar['ingame_vehicle_id'],
                $usedCar['vehicle_type'],
                $usedCar['capacity_t'],
                $usedCar['year_built'],
                $usedCar['km_stand'],
                $usedCar['condition_pct'],
                (int)$cityId
            ]);

            $pdo->commit();

            $_SESSION['pb_fleet_message'] = "Gebrauchtwagen erfolgreich übernommen und in Ihren aktiven Fuhrpark überführt.";
            $_SESSION['pb_fleet_message_class'] = "status-success";
        }

        // 3. Fahrer zuweisen
        elseif ($action === 'assign_driver') {
            $truckId = (int)$_POST['truck_id'];
            $driverIngameId = trim($_POST['driver_ingame_id']);

            $pdo->beginTransaction();

            // Hebe vorherige LKW-Zuweisung dieses Fahrers auf
            $stmtClearDriver = $pdo->prepare("UPDATE drivers SET assigned_truck_id = NULL WHERE ingame_driver_id = ?");
            $stmtClearDriver->execute([$driverIngameId]);

            // Hebe vorherigen Fahrer dieses LKWs auf
            $stmtClearTruck = $pdo->prepare("UPDATE trucks SET assigned_driver_id = NULL WHERE id = ?");
            $stmtClearTruck->execute([$truckId]);

            // Verknüpfe beide Datensätze
            $stmtAssignTruck = $pdo->prepare("UPDATE trucks SET assigned_driver_id = ? WHERE id = ?");
            $stmtAssignTruck->execute([$driverIngameId, $truckId]);

            $stmtAssignDriver = $pdo->prepare("UPDATE drivers SET assigned_truck_id = ? WHERE ingame_driver_id = ?");
            $stmtAssignDriver->execute([$truckId, $driverIngameId]);

            // PH § 5.4.2.3: Tour-Bruch validieren, falls der neue Fahrer kein Gefahrgut (ADR) darf
            $stmtD = $pdo->prepare("SELECT adr_permit FROM drivers WHERE ingame_driver_id = ?");
            $stmtD->execute([$driverIngameId]);
            $hasAdr = (int)$stmtD->fetchColumn();

            if ($hasAdr === 0) {
                $stmtOrders = $pdo->prepare("SELECT id, is_adr, assigned_at FROM orders WHERE assigned_truck_id = ? AND is_archived = 0 ORDER BY assigned_at ASC");
                $stmtOrders->execute([$truckId]);
                $assignedOrders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

                $breakActive = false;
                foreach ($assignedOrders as $ord) {
                    if ($breakActive || (int)$ord['is_adr'] === 1) {
                        $breakActive = true;
                        $stmtUnassign = $pdo->prepare("UPDATE orders SET assigned_truck_id = NULL, assigned_at = NULL WHERE id = ?");
                        $stmtUnassign->execute([$ord['id']]);
                    }
                }
            }

            $pdo->commit();
            $_SESSION['pb_fleet_message'] = "Fahrer-LKW-Zuordnung erfolgreich aktualisiert. ADR-Kettenprüfung wurde durchgeführt.";
            $_SESSION['pb_fleet_message_class'] = "status-success";
        }

        // 4. Fahrer entkoppeln
        elseif ($action === 'unassign_driver') {
            $truckId = (int)$_POST['truck_id'];

            $pdo->beginTransaction();

            $stmtCurrent = $pdo->prepare("SELECT assigned_driver_id FROM trucks WHERE id = ?");
            $stmtCurrent->execute([$truckId]);
            $driverIngameId = $stmtCurrent->fetchColumn();

            if ($driverIngameId) {
                $stmtD = $pdo->prepare("UPDATE drivers SET assigned_truck_id = NULL WHERE ingame_driver_id = ?");
                $stmtD->execute([$driverIngameId]);
            }

            $stmtT = $pdo->prepare("UPDATE trucks SET assigned_driver_id = NULL WHERE id = ?");
            $stmtT->execute([$truckId]);

            // Da fahrerlos, bricht die geplante Tour ab der ersten Gefahrgut-Schnittstelle ab
            $stmtOrders = $pdo->prepare("SELECT id, is_adr, assigned_at FROM orders WHERE assigned_truck_id = ? AND is_archived = 0 ORDER BY assigned_at ASC");
            $stmtOrders->execute([$truckId]);
            $assignedOrders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

            $breakActive = false;
            foreach ($assignedOrders as $ord) {
                if ($breakActive || (int)$ord['is_adr'] === 1) {
                    $breakActive = true;
                    $stmtUnassign = $pdo->prepare("UPDATE orders SET assigned_truck_id = NULL, assigned_at = NULL WHERE id = ?");
                    $stmtUnassign->execute([$ord['id']]);
                }
            }

            $pdo->commit();
            $_SESSION['pb_fleet_message'] = "Fahrer erfolgreich vom LKW abgekoppelt. Die fahrbare Tour wurde neu berechnet.";
            $_SESSION['pb_fleet_message_class'] = "status-success";
        }

        // 5. Fahrzeug verkaufen (Lösch-Logik PH § 5.4.3.2)
        elseif ($action === 'sell_truck') {
            $truckId = (int)$_POST['truck_id'];

            $pdo->beginTransaction();

            $stmtDrivers = $pdo->prepare("UPDATE drivers SET assigned_truck_id = NULL WHERE assigned_truck_id = ?");
            $stmtDrivers->execute([$truckId]);

            $stmtOrders = $pdo->prepare("UPDATE orders SET is_archived = 1, completed_at = NOW(), assigned_truck_id = NULL WHERE assigned_truck_id = ? AND is_archived = 0");
            $stmtOrders->execute([$truckId]);

            $stmtDelete = $pdo->prepare("DELETE FROM trucks WHERE id = ?");
            $stmtDelete->execute([$truckId]);

            $pdo->commit();
            $_SESSION['pb_fleet_message'] = "Fahrzeug erfolgreich aus dem Fuhrpark entfernt. Fahrer entkoppelt, verbliebene Tour-Etappen archiviert.";
            $_SESSION['pb_fleet_message_class'] = "status-success";
        }

        // 6. Fuhrpark-Import-Parser (Über spezialisierte OwnFleetParser-Klasse)
        elseif ($action === 'import_fleet' && !empty($_POST['import_data'])) {
            $htmlData = $_POST['import_data'];

            // Lade lizensierte Städte für den Tokenizer-Abgleich des Parsers
            $citiesList = $pdo->query("SELECT id, name FROM cities")->fetchAll(PDO::FETCH_ASSOC);
            $citiesMap = [];
            foreach ($citiesList as $c) {
                $citiesMap[mb_strtolower($c['name'])] = (int)$c['id'];
            }

            // Instanziierung des spezialisierten Fuhrpark-Parsers
            $parser = new OwnFleetParser();
            $parsedTrucks = $parser->parse($htmlData, $citiesMap);

            $updatedRecords = 0;

            if (!empty($parsedTrucks)) {
                $pdo->beginTransaction();

                foreach ($parsedTrucks as $pt) {
                    $vehicleId = $pt['ingame_vehicle_id'];
                    $km = $pt['km_stand'];
                    $condition = $pt['condition_pct'];
                    $cityId = $pt['current_city_id'];

                    $stmtCheck = $pdo->prepare("SELECT id FROM trucks WHERE ingame_vehicle_id = ?");
                    $stmtCheck->execute([$vehicleId]);
                    $dbTruckId = $stmtCheck->fetchColumn();

                    if ($dbTruckId) {
                        $updates = [];
                        $params = [];

                        if ($km !== null) {
                            $updates[] = "km_stand = :km";
                            $params['km'] = $km;
                        }
                        if ($cityId !== null) {
                            $updates[] = "current_city_id = :city_id";
                            $params['city_id'] = $cityId;
                        }
                        if ($condition !== null) {
                            $updates[] = "condition_pct = :condition";
                            $params['condition'] = $condition;
                        }

                        if (!empty($updates)) {
                            $sql = "UPDATE trucks SET " . implode(', ', $updates) . " WHERE id = :id";
                            $params['id'] = (int)$dbTruckId;
                            $stmtUp = $pdo->prepare($sql);
                            $stmtUp->execute($params);
                            $updatedRecords++;
                        }
                    }
                }

                $pdo->commit();
            }

            $_SESSION['pb_fleet_message'] = "Import beendet! Der OwnFleetParser hat die Laufleistungen, Standorte und den Zustand von " . $updatedRecords . " eigenen LKW erfolgreich über XPath-DOM-Strukturen aktualisiert.";
            $_SESSION['pb_fleet_message_class'] = "status-success";
        }

        header("Location: " . $redirectUrl);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['pb_fleet_message'] = "Fehler bei der Transaktion: " . $e->getMessage();
        $_SESSION['pb_fleet_message_class'] = "status-error";
        header("Location: fleet_manager.php");
        exit;
    }
}

// -------------------------------------------------------------
// BASISDATEN LADEN
// -------------------------------------------------------------
$cities = $pdo->query("SELECT id, name FROM cities ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Liste der eigenen LKWs (inklusive neuer condition_pct Spalte)
$activeFleet = $pdo->query("
    SELECT t.*, c.name AS city_name, d.first_name, d.last_name, d.adr_permit, d.ingame_driver_id
    FROM trucks t
    LEFT JOIN cities c ON t.current_city_id = c.id
    LEFT JOIN drivers d ON t.assigned_driver_id = d.ingame_driver_id
    ORDER BY t.km_stand DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Liste der ungebundenen, eingestellten Fahrer für Dropdowns (PH-konform & Synchronisationssicher)
$freeDrivers = $pdo->query("
    SELECT id, ingame_driver_id, first_name, last_name, skill_val, adr_permit 
    FROM drivers 
    WHERE is_employed = 1 
      AND (assigned_truck_id IS NULL OR assigned_truck_id = 0)
      AND ingame_driver_id NOT IN (
          SELECT assigned_driver_id 
          FROM trucks 
          WHERE assigned_driver_id IS NOT NULL AND assigned_driver_id != ''
      )
    ORDER BY last_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Liste aller noch nicht übernommenen Gebrauchtwagen aus market_history (PH § 2.6.2)
$marketVehicles = $pdo->query("
    SELECT mh.* 
    FROM market_history mh
    LEFT JOIN trucks t ON mh.ingame_vehicle_id = t.ingame_vehicle_id
    WHERE t.id IS NULL
    ORDER BY mh.roi_score ASC
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Fuhrpark-Zentrum - TransportBoss</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <?php require_once 'nav.php'; ?>
    <div class="fluid-container">
        
        <!-- KPI-Übersichts-Zahnräder -->
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3 class="accent-text">Fuhrparkgröße</h3>
                <div class="kpi-value"><?= count($activeFleet) ?></div>
                <div class="kpi-desc">Eigene LKW im Bestand</div>
            </div>
            <div class="dashboard-card">
                <h3 class="accent-text">Fahrzeuge im Markt</h3>
                <div class="kpi-value"><?= count($marketVehicles) ?></div>
                <div class="kpi-desc">Nicht gekaufte Gebrauchtwagen</div>
            </div>
            <div class="dashboard-card">
                <h3 class="accent-text">Gesamtlaufleistung</h3>
                <div class="kpi-value">
                    <?php 
                    $totalKm = array_sum(array_column($activeFleet, 'km_stand'));
                    echo number_format($totalKm, 0, ',', '.');
                    ?>
                </div>
                <div class="kpi-desc">Kumulierte Kilometer</div>
            </div>
        </div>

        <hr class="section-divider">

        <?php if ($message): ?>
            <div class="feedback-msg <?= $messageClass ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- ==========================================================================
             ANSICHT 1: AKTIVER FUHRPARK (active)
             ========================================================================== -->
        <?php if ($view === 'active'): ?>
            <div class="workspace-header-row">
                <h1 class="accent-text">Aktiver Fuhrpark</h1>
                <div class="action-form">
                    <a href="?view=parser" class="btn-primary">📥 Fuhrpark-Import (Daten updaten)</a>
                    <a href="?view=market" class="btn-primary">🛒 Gebrauchtwagen übernehmen</a>
                    <a href="?view=new" class="btn-primary">✨ Neuwagen kaufen</a>
                </div>
            </div>

            <!-- Interaktive Filterleiste (Multifilter) -->
            <div class="filter-panel">
                <div class="filter-group">
                    <label>Fahrzeugtyp:</label>
                    <select id="filterType" class="inline-select" onchange="applyActiveFleetFilters()">
                        <option value="">-- Alle Typen --</option>
                        <?php 
                        $types = array_unique(array_column($activeFleet, 'vehicle_type'));
                        sort($types);
                        foreach ($types as $type): 
                        ?>
                            <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Standort:</label>
                    <select id="filterLocation" class="inline-select" onchange="applyActiveFleetFilters()">
                        <option value="">-- Alle Standorte --</option>
                        <?php 
                        $locs = array_unique(array_column($activeFleet, 'city_name'));
                        sort($locs);
                        foreach ($locs as $loc): 
                        ?>
                            <option value="<?= htmlspecialchars((string)$loc) ?>"><?= htmlspecialchars((string)$loc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Fahrerbelegung:</label>
                    <select id="filterDriver" class="inline-select" onchange="applyActiveFleetFilters()">
                        <option value="">-- Alle --</option>
                        <option value="besetzt">Besetzt</option>
                        <option value="unbesetzt">Unbesetzt</option>
                    </select>
                </div>
                <div class="filter-group filter-search-group">
                    <label>Multisearch-Suche:</label>
                    <input type="text" id="fleetSearch" class="filter-input" placeholder="Nach ID, Typ, Fahrer, Standort etc. suchen..." onkeyup="applyActiveFleetFilters()">
                </div>
            </div>

            <!-- Haupt-Fahrzeugliste -->
            <table class="data-table" id="activeFleetTable">
                <thead>
                    <tr>
                        <th onclick="sortTable('activeFleetTable', 0, 'string')">LKW-ID ⇕</th>
                        <th onclick="sortTable('activeFleetTable', 1, 'string')">Fahrzeugtyp ⇕</th>
                        <th onclick="sortTable('activeFleetTable', 2, 'number')">Kapazität (Sperren) ⇕</th>
                        <th onclick="sortTable('activeFleetTable', 3, 'number')">Baujahr ⇕</th>
                        <th onclick="sortTable('activeFleetTable', 4, 'number')">Laufleistung ⇕</th>
                        <th onclick="sortTable('activeFleetTable', 5, 'number')">Zustand ⇕</th>
                        <th onclick="sortTable('activeFleetTable', 6, 'string')">Standort ⇕</th>
                        <th onclick="sortTable('activeFleetTable', 7, 'string')">Fahrerzuweisung ⇕</th>
                        <th></th> <!-- Neue saubere Spalte für den Abkoppel-Button -->
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($activeFleet)): ?>
                        <tr><td colspan="10" class="text-center text-muted-italic">Keine Fahrzeuge im Fuhrpark registriert. Nutzen Sie die Kauf-Schaltflächen oben!</td></tr>
                    <?php else: ?>
                        <?php foreach ($activeFleet as $truck): ?>
                        <tr class="filterable-fleet-row" data-type="<?= htmlspecialchars($truck['vehicle_type']) ?>" data-location="<?= htmlspecialchars((string)$truck['city_name']) ?>" data-driver-status="<?= $truck['assigned_driver_id'] ? 'besetzt' : 'unbesetzt' ?>">
                            <td>
                                <strong>ID: <?= htmlspecialchars($truck['ingame_vehicle_id']) ?></strong>
                            </td>
                            <td><?= htmlspecialchars($truck['vehicle_type']) ?></td>
                            <td>
                                <?= $truck['capacity_t'] ?> t
                                <?php if ((int)$truck['min_weight_t'] > 0 || (int)$truck['max_weight_t'] > 0): ?>
                                    <br>
                                    <small class="text-orange" title="Aktive Tonnage-Sperren">
                                        Min: <?= (int)$truck['min_weight_t'] ?>t / 
                                        Max: <?= (int)$truck['max_weight_t'] > 0 ? (int)$truck['max_weight_t'] . 't' : 'unbegr.' ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td><?= $truck['year_built'] ?></td>
                            <td><?= number_format((float)$truck['km_stand'], 0, ',', '.') ?> km</td>
                            <td><strong><?= number_format((float)$truck['condition_pct'], 1, ',', '.') ?> %</strong></td>
                            <td><span class="text-blue">➔ <?= htmlspecialchars((string)$truck['city_name']) ?></span></td>
                            <td>
                                <?php if ($truck['assigned_driver_id']): ?>
                                    <span class="text-white"><strong><?= htmlspecialchars($truck['last_name'] . ', ' . substr($truck['first_name'], 0, 1) . '.') ?></strong></span>
                                    <?= $truck['adr_permit'] ? '<span class="adr-badge">[ADR]</span>' : '' ?>
                                <?php else: ?>
                                    <form method="post" class="action-form">
                                        <input type="hidden" name="action" value="assign_driver">
                                        <input type="hidden" name="truck_id" value="<?= $truck['id'] ?>">
                                        <select name="driver_ingame_id" class="inline-select" required>
                                            <option value="">-- Fahrer zuweisen --</option>
                                            <?php foreach ($freeDrivers as $fd): ?>
                                                <option value="<?= htmlspecialchars($fd['ingame_driver_id']) ?>">
                                                    <?= htmlspecialchars($fd['last_name'] . ', ' . $fd['first_name']) ?> (Fahrkönnen: <?= $fd['skill_val'] ?>, ADR: <?= $fd['adr_permit'] ? 'Ja' : 'Nein' ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn-primary btn-small">Verknüpfen</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($truck['assigned_driver_id']): ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="unassign_driver">
                                        <input type="hidden" name="truck_id" value="<?= $truck['id'] ?>">
                                        <button type="submit" class="btn-primary btn-danger btn-small">Abkoppeln</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-form">
                                    <a href="edit_entity.php?type=truck&id=<?= $truck['id'] ?>" class="btn-primary btn-small">Manuelle Korrektur</a>
                                    <form method="post" onsubmit="return confirm('Fahrzeug wirklich verkaufen? Sämtliche Daten werden permanent entfernt.');">
                                        <input type="hidden" name="action" value="sell_truck">
                                        <input type="hidden" name="truck_id" value="<?= $truck['id'] ?>">
                                        <button type="submit" class="btn-primary btn-danger btn-small">Verkaufen</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

        <!-- ==========================================================================
             ANSICHT 2: NEUWAGEN KAUFEN (new)
             ========================================================================== -->
        <?php elseif ($view === 'new'): ?>
            <div class="workspace-header-row">
                <h1 class="accent-text">Neufahrzeug erwerben</h1>
                <a href="fleet_manager.php" class="btn-primary">⬅️ Zurück zum Fuhrpark</a>
            </div>

            <!-- Interaktive Filterleiste für Neuwagen-Katalog -->
            <div class="filter-panel">
                <div class="filter-group filter-search-group">
                    <label>Katalog durchsuchen:</label>
                    <input type="text" id="newCarSearch" class="filter-input" placeholder="Nach Typ oder Frachtklasse filtern..." onkeyup="applyNewCarFilters()">
                </div>
            </div>

            <table class="data-table" id="newCarTable">
                <thead>
                    <tr>
                        <th onclick="sortTable('newCarTable', 0, 'string')">Frachttyp (LKW-Klasse) ⇕</th>
                        <th onclick="sortTable('newCarTable', 1, 'number')">Nutzlast ⇕</th>
                        <th onclick="sortTable('newCarTable', 2, 'number')">Verbrauch ⇕</th>
                        <th onclick="sortTable('newCarTable', 3, 'number')">Tankinhalt ⇕</th>
                        <th onclick="sortTable('newCarTable', 4, 'number')">V-Max ⇕</th>
                        <th onclick="sortTable('newCarTable', 5, 'number')">Neupreis ⇕</th>
                        <th>Kaufabwicklung (Erhalt am Wunschort)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($newCarCatalog as $classKey => $specs): ?>
                    <tr class="filterable-new-row">
                        <td><strong><?= htmlspecialchars($classKey) ?></strong></td>
                        <td><?= $specs['capacity'] ?> t</td>
                        <td><?= number_format($specs['consumption'], 1, ',', '.') ?> l/100km</td>
                        <td><?= $specs['tank'] ?> Liter</td>
                        <td><?= $specs['speed'] ?> km/h</td>
                        <td><span class="text-white"><strong><?= number_format($specs['price'], 2, ',', '.') ?> €</strong></span></td>
                        <td>
                            <form method="post" class="action-form">
                                <input type="hidden" name="action" value="buy_new">
                                <input type="hidden" name="vehicle_class" value="<?= htmlspecialchars($classKey) ?>">
                                
                                <input type="text" name="ingame_vehicle_id" class="inline-select" placeholder="Ingame ID (z.B. 10612411)" required>
                                
                                <select name="current_city_id" class="inline-select" required>
                                    <option value="">-- Wunsch-Standort wählen --</option>
                                    <?php foreach ($cities as $city): ?>
                                        <option value="<?= $city['id'] ?>"><?= htmlspecialchars($city['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn-primary btn-small">Kaufen</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <!-- ==========================================================================
             ANSICHT 3: GEBRAUCHTWAGENMARKT ÜBERNAHME (market)
             ========================================================================== -->
        <?php elseif ($view === 'market'): ?>
            <div class="workspace-header-row">
                <h1 class="accent-text">Gebrauchtwagen-Übernahme</h1>
                <a href="fleet_manager.php" class="btn-primary">⬅️ Zurück zum Fuhrpark</a>
            </div>

            <!-- Interaktive Filterleiste für Gebrauchtwagen -->
            <div class="filter-panel">
                <div class="filter-group">
                    <label>Fahrzeugtyp:</label>
                    <select id="marketTypeFilter" class="inline-select" onchange="applyMarketFilters()">
                        <option value="">-- Alle Typen --</option>
                        <?php 
                        $marketTypes = array_unique(array_column($marketVehicles, 'vehicle_type'));
                        sort($marketTypes);
                        foreach ($marketTypes as $mType): 
                        ?>
                            <option value="<?= htmlspecialchars($mType) ?>"><?= htmlspecialchars($mType) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Standort:</label>
                    <select id="marketLocationFilter" class="inline-select" onchange="applyMarketFilters()">
                        <option value="">-- Alle Standorte --</option>
                        <?php 
                        $marketLocs = array_unique(array_column($marketVehicles, 'location_label'));
                        sort($marketLocs);
                        foreach ($marketLocs as $mLoc): 
                        ?>
                            <option value="<?= htmlspecialchars($mLoc) ?>"><?= htmlspecialchars($mLoc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group filter-search-group">
                    <label>Gebrauchtwagen durchsuchen:</label>
                    <input type="text" id="marketSearch" class="filter-input" placeholder="Nach ID, Typ, Standort, etc. filtern..." onkeyup="applyMarketFilters()">
                </div>
            </div>

            <table class="data-table" id="marketVehiclesTable">
                <thead>
                    <tr>
                        <th onclick="sortTable('marketVehiclesTable', 0, 'string')">Fahrzeug-ID ⇕</th>
                        <th onclick="sortTable('marketVehiclesTable', 1, 'string')">Fahrzeugtyp ⇕</th>
                        <th onclick="sortTable('marketVehiclesTable', 2, 'number')">Kapazität ⇕</th>
                        <th onclick="sortTable('marketVehiclesTable', 3, 'number')">Baujahr ⇕</th>
                        <th onclick="sortTable('marketVehiclesTable', 4, 'number')">Laufleistung ⇕</th>
                        <th onclick="sortTable('marketVehiclesTable', 5, 'number')">Zustand ⇕</th>
                        <th onclick="sortTable('marketVehiclesTable', 6, 'string')">Inserat-Ort ⇕</th>
                        <th onclick="sortTable('marketVehiclesTable', 7, 'number')">Kaufpreis ⇕</th>
                        <th onclick="sortTable('marketVehiclesTable', 8, 'number')">ROI-Score ⇕</th>
                        <th>Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($marketVehicles)): ?>
                        <tr><td colspan="10" class="text-center text-muted-italic">Keine unberührten Gebrauchtwagen im Preisarchiv. Importieren Sie zuerst Daten im Fahrzeugmarkt!</td></tr>
                    <?php else: ?>
                        <?php foreach ($marketVehicles as $v): ?>
                        <tr class="filterable-market-row" data-type="<?= htmlspecialchars($v['vehicle_type']) ?>" data-location="<?= htmlspecialchars($v['location_label']) ?>">
                            <td><strong>ID: <?= htmlspecialchars($v['ingame_vehicle_id']) ?></strong></td>
                            <td><?= htmlspecialchars($v['vehicle_type']) ?></td>
                            <td><?= $v['capacity_t'] ?> t</td>
                            <td><?= $v['year_built'] ?></td>
                            <td><?= number_format((float)$v['km_stand'], 0, ',', '.') ?> km</td>
                            <td><strong><?= $v['condition_pct'] ?> %</strong></td>
                            <td><span class="text-blue">➔ <?= htmlspecialchars($v['location_label']) ?></span></td>
                            <td><span class="text-white"><strong><?= number_format((float)$v['price'], 2, ',', '.') ?> €</strong></span></td>
                            <td class="text-orange"><strong><?= $v['roi_score'] ?></strong></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Möchten Sie dieses Fahrzeug wirklich käuflich übernehmen?');">
                                    <input type="hidden" name="action" value="buy_used">
                                    <input type="hidden" name="market_vehicle_id" value="<?= $v['id'] ?>">
                                    <button type="submit" class="btn-primary btn-small">Kaufen & Übernehmen</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

        <!-- ==========================================================================
             ANSICHT 4: FUHRPARK-PARSER (parser)
             ========================================================================== -->
        <?php elseif ($view === 'parser'): ?>
            <div class="workspace-header-row">
                <h1 class="accent-text">Fuhrpark-Daten aktualisieren (Parser)</h1>
                <a href="fleet_manager.php" class="btn-primary">⬅️ Zurück zum Fuhrpark</a>
            </div>

            <div class="form-box">
                <h3 class="accent-text text-blue">📥 Quelltext der Ingame-Fahrzeugübersicht einlesen</h3>
                <form method="post" action="fleet_manager.php">
                    <input type="hidden" name="action" value="import_fleet">
                    <label for="import_data">Kopieren Sie das HTML Ihrer Ingame-Fuhrparkliste und fügen Sie es hier ein. Das System gleicht die Fahrzeug-IDs automatisch ab und aktualisiert km-Stände sowie physische Standorte der LKW:</label>
                    <textarea id="import_data" name="import_data" class="import-textarea" placeholder="HTML hier hineinkopieren..." required></textarea>
                    <button type="submit" class="btn-primary">Einlesen und LKW-Bestand aktualisieren</button>
                </form>
            </div>
        <?php endif; ?>

    </div>

    <!-- Client-Side Sortier- & Filter-Architektur -->
    <script>
        // -------------------------------------------------------------
        // SORTIER-ALGORITHMUS (ZENTRAL)
        // -------------------------------------------------------------
        function sortTable(tableId, columnIndex, type) {
            const table = document.getElementById(tableId);
            if (!table) return;
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            const headers = table.querySelectorAll('th');
            const th = headers[columnIndex];
            const currentDir = th.getAttribute('data-sort-dir') === 'asc';
            const dir = !currentDir;
            
            headers.forEach(h => h.removeAttribute('data-sort-dir'));
            th.setAttribute('data-sort-dir', dir ? 'asc' : 'desc');

            rows.sort((a, b) => {
                let valA = a.cells[columnIndex]?.innerText.trim() || '';
                let valB = b.cells[columnIndex]?.innerText.trim() || '';

                if (type === 'number') {
                    // Filtert Formatierungen wie Punkte, Kommas, km-Angaben oder Euro-Symbole heraus
                    valA = parseFloat(valA.replace(/[^0-9,-]+/g, '').replace(',', '.'));
                    valB = parseFloat(valB.replace(/[^0-9,-]+/g, '').replace(',', '.'));
                    valA = isNaN(valA) ? 0 : valA;
                    valB = isNaN(valB) ? 0 : valB;
                } else {
                    valA = valA.toLowerCase();
                    valB = valB.toLowerCase();
                }

                if (valA < valB) return dir ? -1 : 1;
                if (valA > valB) return dir ? 1 : -1;
                return 0;
            });

            rows.forEach(row => tbody.appendChild(row));
        }

        // -------------------------------------------------------------
        // FILTER-STEUERUNGEN (REAKTIV & CONCURRENT)
        // -------------------------------------------------------------
        
        // Eigener Fuhrpark (Tabelle activeFleetTable)
        function applyActiveFleetFilters() {
            const query = document.getElementById('fleetSearch').value.toLowerCase();
            const keywords = query.split(/\s+/).filter(k => k.trim() !== '');

            const typeVal = document.getElementById('filterType').value.toLowerCase();
            const locVal = document.getElementById('filterLocation').value.toLowerCase();
            const driverVal = document.getElementById('filterDriver').value.toLowerCase();

            const rows = document.querySelectorAll('#activeFleetTable tbody tr');

            rows.forEach(row => {
                if (row.cells.length < 2) return; 
                
                const typeAttr = row.getAttribute('data-type').toLowerCase();
                const locAttr = row.getAttribute('data-location').toLowerCase();
                const driverAttr = row.getAttribute('data-driver-status').toLowerCase();
                const fullText = row.textContent.toLowerCase();

                const matchType = (typeVal === '' || typeAttr === typeVal);
                const matchLoc = (locVal === '' || locAttr === locVal);
                const matchDriver = (driverVal === '' || driverAttr === driverVal);

                let matchSearch = true;
                for (let kw of keywords) {
                    if (!fullText.includes(kw)) {
                        matchSearch = false;
                        break;
                    }
                }

                if (matchType && matchLoc && matchDriver && matchSearch) {
                    row.classList.remove('hidden-row');
                } else {
                    row.classList.add('hidden-row');
                }
            });
        }

        // Neuwagen-Katalog
        function applyNewCarFilters() {
            const query = document.getElementById('newCarSearch').value.toLowerCase();
            const keywords = query.split(/\s+/).filter(k => k.trim() !== '');

            const rows = document.querySelectorAll('#newCarTable tbody tr');

            rows.forEach(row => {
                const fullText = row.textContent.toLowerCase();
                let matchSearch = true;
                for (let kw of keywords) {
                    if (!fullText.includes(kw)) {
                        matchSearch = false;
                        break;
                    }
                }
                if (matchSearch) {
                    row.classList.remove('hidden-row');
                } else {
                    row.classList.add('hidden-row');
                }
            });
        }

        // Gebrauchtwagenmarkt
        function applyMarketFilters() {
            const query = document.getElementById('marketSearch').value.toLowerCase();
            const keywords = query.split(/\s+/).filter(k => k.trim() !== '');

            const typeVal = document.getElementById('marketTypeFilter').value.toLowerCase();
            const locVal = document.getElementById('marketLocationFilter').value.toLowerCase();

            const rows = document.querySelectorAll('#marketVehiclesTable tbody tr');

            rows.forEach(row => {
                if (row.cells.length < 2) return;

                const typeAttr = row.getAttribute('data-type').toLowerCase();
                const locAttr = row.getAttribute('data-location').toLowerCase();
                const fullText = row.textContent.toLowerCase();

                const matchType = (typeVal === '' || typeAttr === typeVal);
                const matchLoc = (locVal === '' || locAttr === locVal);

                let matchSearch = true;
                for (let kw of keywords) {
                    if (!fullText.includes(kw)) {
                        matchSearch = false;
                        break;
                    }
                }

                if (matchType && matchLoc && matchSearch) {
                    row.classList.remove('hidden-row');
                } else {
                    row.classList.add('hidden-row');
                }
            });
        }
    </script>
</body>
</html>