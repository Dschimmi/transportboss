<?php
declare(strict_types=1);

require_once 'db_connect.php';
require_once 'classes/TruckRepository.php';
require_once 'classes/DriverRepository.php';
require_once 'classes/OrderRepository.php';
require_once 'classes/TopologyEngine.php';
require_once 'classes/DistanceService.php';

// Initialisierung
$truckRepo = new TruckRepository($pdo);
$driverRepo = new DriverRepository($pdo);
$orderRepo = new OrderRepository($pdo);
$distanceService = new DistanceService($pdo);
$topologyEngine = new TopologyEngine($pdo, $distanceService);

// Fokus-System
$focusTruckId = null;
if (isset($_GET['focus_truck_id'])) {
    $focusTruckId = (int)$_GET['focus_truck_id'];
    $pdo->exec("UPDATE trucks SET is_focussed = 0");
    $pdo->exec("UPDATE trucks SET is_focussed = 1 WHERE id = $focusTruckId");
} else {
    $firstTruck = $pdo->query("SELECT id FROM trucks WHERE assigned_driver_id IS NOT NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $focusTruckId = $firstTruck ? (int)$firstTruck['id'] : null;
}

// Entladen-Aktion (Kaskaden-Storno)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unload_job') {
    $orderId = (int)$_POST['order_id'];
    $orderRepo->unassignFromTruck($orderId);
    // Aktuelle Seite neu laden, um Änderungen anzuzeigen
    header("Location: dispatcher_board.php?focus_truck_id=$focusTruckId");
    exit;
}

// Alle disponiblen Fahrzeuge laden
$allTrucks = $truckRepo->getAllOwned();

// Städte für Sidebar laden
$cities = $pdo->query("
    SELECT
        c.id,
        c.name,
        COUNT(CASE WHEN o.is_accepted = 0 THEN 1 END) AS market_jobs,
        SUM(CASE WHEN o.is_accepted = 1 THEN o.weight_remaining ELSE 0 END) AS warehouse_weight
    FROM cities c
    LEFT JOIN orders o ON c.id = o.from_city_id AND o.is_archived = 0
    GROUP BY c.id, c.name
    ORDER BY market_jobs ASC, c.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Vorschläge für das fokussierte Fahrzeug laden
$suggestions = [];
$focusTruck = null;
if ($focusTruckId) {
    $focusTruck = $truckRepo->getById($focusTruckId);
    if ($focusTruck) {
        $suggestions = $topologyEngine->getSuggestionsForTruck($focusTruck['id'], $focusTruck['current_city_id']);
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
<body>
    <div class="main-container">
        <h1 class="accent-text">Dispatcher Board</h1>

        <div style="display: flex; height: calc(100vh - 100px);">
            <!-- LINKE SPALTE: Sidebar (Strategie-Monitor) -->
            <div style="width: 350px; background-color: #1e1e1e; border-right: 1px solid #444; overflow-y: auto; padding: 15px;">
                <h2 class="accent-text">Strategie-Monitor</h2>
                <input type="text" id="cityFilter" class="filter-input" placeholder="Städte filtern...">
                <table class="data-table" id="sidebarTable">
                    <thead>
                        <tr>
                            <th>Stadt</th>
                            <th>Jobs (Markt)</th>
                            <th>Bestand (t)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cities as $city): ?>
                        <tr>
                            <td><?= htmlspecialchars($city['name']) ?></td>
                            <td><?= $city['market_jobs'] > 0 ? $city['market_jobs'] : '-' ?></td>
                            <td class="<?= $city['warehouse_weight'] > 0 ? '' : 'status-missing' ?>">
                                <?= $city['warehouse_weight'] > 0 ? $city['warehouse_weight'] . ' t' : 'FEHLT' ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- RECHTE SPALTE: Fuhrpark-Board -->
            <div style="flex: 1; overflow-y: auto; padding: 15px;">
                <h2 class="accent-text">Fuhrpark-Board</h2>

                <!-- Steuerungs-Modul -->
                <div style="margin-bottom: 15px; display: flex; gap: 10px; align-items: center;">
                    <button id="toggleAllTours" class="btn-primary">Alle Touren einklappen</button>
                </div>

                <!-- Fahrzeugkarten -->
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 15px;">
                    <?php foreach ($allTrucks as $truck): ?>
                    <div class="truck-card <?= $truck['is_active_planning'] ? 'truck-card-active' : 'truck-card-inactive' ?>
                        <?= $truck['id'] == $focusTruckId ? 'truck-card-focussed' : '' ?>"
                         onclick="window.location.href='?focus_truck_id=<?= $truck['id'] ?>'">

                        <!-- Header -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                            <div>
                                <div style="font-weight: bold; color: #f39c12;">
                                    ID: <?= htmlspecialchars($truck['ingame_vehicle_id']) ?> |
                                    <?= htmlspecialchars($truck['vehicle_type']) ?>
                                </div>
                                <?php
                                $driver = $driverRepo->getById($truck['assigned_driver_id']);
                                if ($driver):
                                ?>
                                    <div style="color: #fff;">
                                        <?= htmlspecialchars($driver['last_name'] . ', ' . substr($driver['first_name'], 0, 1) . '.') ?>
                                        <?php if ($driver['adr_permit']): ?>
                                            <span class="adr-badge">[ADR]</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div style="text-align: right;">
                                <div style="color: #aaa;"><?= $truck['capacity_t'] ?> t</div>
                                <div><span class="badge-jobs"><?= $truck['job_count'] ?? 0 ?> Jobs</span></div>
                            </div>
                        </div>

                        <!-- Tourende (dynamisch) -->
                        <div style="margin-top: 10px; font-size: 0.9em;">
                            <?php
                            $lastOrder = $orderRepo->getLastOrderForTruck($truck['id']);
                            if ($lastOrder) {
                                $tourEndCity = $pdo->query("SELECT name FROM cities WHERE id = " . (int)$lastOrder['to_city_id'])->fetchColumn();
                                echo '<span style="color: #f39c12;">➔ ' . htmlspecialchars($tourEndCity) . '</span>';
                            } else {
                                $currentCity = $pdo->query("SELECT name FROM cities WHERE id = " . (int)$truck['current_city_id'])->fetchColumn();
                                echo '<span style="color: #3498db;">📍 POS: ' . htmlspecialchars($currentCity) . '</span>';
                            }
                            ?>
                        </div>

                        <!-- Tourenplan (einklappbar) -->
                        <div class="tour-plan-container" style="margin-top: 15px; display: none;">
                            <table class="suggestion-table">
                                <thead>
                                    <tr>
                                        <th>Typ</th>
                                        <th>Route</th>
                                        <th>Distanz</th>
                                        <th>Tonnage</th>
                                        <th>Erlös</th>
                                        <th>Aktion</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Eingeplante Aufträge für dieses Fahrzeug laden
                                    $assignedOrders = $pdo->query("
                                        SELECT o.*, c1.name AS from_city_name, c2.name AS to_city_name
                                        FROM orders o
                                        JOIN cities c1 ON o.from_city_id = c1.id
                                        JOIN cities c2 ON o.to_city_id = c2.id
                                        WHERE o.assigned_truck_id = {$truck['id']}
                                        AND o.is_archived = 0
                                        ORDER BY o.assigned_at ASC
                                    ")->fetchAll(PDO::FETCH_ASSOC);

                                    if (empty($assignedOrders)) {
                                        echo '<tr><td colspan="6" style="text-align: center; color: #888;">Keine Tour geplant</td></tr>';
                                    } else {
                                        $currentCityId = $truck['current_city_id'];
                                        foreach ($assignedOrders as $index => $order) {
                                            // Prüfen, ob eine Leerfahrt nötig ist (Standort-Diskrepanz)
                                            if ($index === 0 && $order['from_city_id'] != $currentCityId) {
                                                $distance = $distanceService->getDistance($currentCityId, $order['from_city_id']);
                                                echo '<tr class="row-type-empty">
                                                    <td>LEERFAHRT</td>
                                                    <td>' . htmlspecialchars($pdo->query("SELECT name FROM cities WHERE id = $currentCityId")->fetchColumn()) . ' → ' . htmlspecialchars($order['from_city_name']) . '</td>
                                                    <td>' . $distance . ' km</td>
                                                    <td>-</td>
                                                    <td>0,00 €</td>
                                                    <td>-</td>
                                                </tr>';
                                                $currentCityId = $order['from_city_id'];
                                            }

                                            // JOB-Etappe
                                            echo '<tr class="row-type-cargo">
                                                <td>JOB</td>
                                                <td>' . htmlspecialchars($order['from_city_name']) . ' → ' . htmlspecialchars($order['to_city_name']) . '</td>
                                                <td>' . $order['distance_km'] . ' km</td>
                                                <td>' . $order['weight_total'] . ' t</td>
                                                <td>' . number_format($order['revenue'], 2, ',', '.') . ' €</td>
                                                <td>
                                                    <form method="post" onsubmit="return confirm(\'Auftrag wirklich entladen?\')">
                                                        <input type="hidden" name="action" value="unload_job">
                                                        <input type="hidden" name="order_id" value="' . $order['id'] . '">
                                                        <button type="submit" class="btn-primary" style="background-color: #e74c3c;">Entladen</button>
                                                    </form>
                                                </td>
                                            </tr>';
                                            $currentCityId = $order['to_city_id'];
                                        }
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Vorschlagsliste (nur für fokussiertes Fahrzeug) -->
                        <?php if ($truck['id'] == $focusTruckId): ?>
                            <?php if (!empty($suggestions)): ?>
                            <div style="margin-top: 15px; border-top: 1px solid #444; padding-top: 10px;">
                                <h4 class="accent-text">Vorschläge für dieses Fahrzeug</h4>
                                <table class="suggestion-table">
                                    <thead>
                                        <tr>
                                            <th>Auftrags-ID</th>
                                            <th>Route</th>
                                            <th>Typ</th>
                                            <th>Gewicht</th>
                                            <th>Erlös</th>
                                            <th>Distanz</th>
                                            <th>Status</th>
                                            <th>Aktion</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($suggestions as $suggestion): ?>
                                        <?php $order = $suggestion['order']; ?>
                                        <tr class="<?= $suggestion['is_fallback'] ? 'status-fallback' : '' ?>">
                                            <td><?= htmlspecialchars($order['ingame_order_id'] ?? 'Marktpool') ?></td>
                                            <td>
                                                <?= htmlspecialchars($order['from_city_name']) ?> →
                                                <?= htmlspecialchars($order['to_city_name']) ?>
                                            </td>
                                            <td><?= htmlspecialchars($order['freight_type']) ?></td>
                                            <td><?= $order['weight_total'] ?> t</td>
                                            <td><?= number_format($order['revenue'], 2, ',', '.') ?> €</td>
                                            <td><?= $suggestion['distance_to_order'] ?> km</td>
                                            <td class="status-<?= $suggestion['status'] ?>">
                                                <?= $suggestion['status'] == 'warehouse' ? 'LAGER' : 'BÖRSE' ?>
                                            </td>
                                            <td>
                                                <form method="post" action="load_job.php">
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
                            <div style="margin-top: 15px; color: #888; font-style: italic;">
                                Keine passenden Aufträge für dieses Fahrzeug gefunden.
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript für Filter, Tourenplan ein-/ausklappen -->
    <script>
        // Sidebar-Filter
        document.getElementById('cityFilter').addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('#sidebarTable tbody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
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

        // Tourenplan ein-/ausklappen (individuell pro Fahrzeug)
        document.querySelectorAll('.truck-card').forEach(card => {
            card.addEventListener('click', function(e) {
                // Verhindere, dass Klicks auf Buttons/Links den Fokus-Wechsel auslösen
                if (e.target.tagName === 'BUTTON' || e.target.tagName === 'A' || e.target.closest('form')) {
                    return;
                }
                // Fokus-Wechsel (bereits durch href im div gehandhabt)
            });
        });
    </script>
</body>
</html>