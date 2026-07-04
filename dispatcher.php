<?php
declare(strict_types=1);

require_once 'db_connect.php';
require_once 'classes/TopologyEngine.php';
require_once 'classes/DistanceService.php';
require_once 'classes/TruckRepository.php';

// Initialisierung
$distanceService = new DistanceService($pdo);
$topologyEngine = new TopologyEngine($pdo, $distanceService);
$truckRepo = new TruckRepository($pdo);

// Aktive Fahrzeuge laden (is_active_planning = 1)
$activeTrucks = $truckRepo->getActiveForPlanning();

// Standardmäßig erstes Fahrzeug auswählen (falls vorhanden)
$selectedTruckId = $_GET['truck_id'] ?? ($activeTrucks[0]['id'] ?? null);
$suggestions = [];

if ($selectedTruckId) {
    // Vorschläge für das ausgewählte Fahrzeug laden
    $truck = $truckRepo->getById($selectedTruckId);
    if ($truck) {
        $suggestions = $topologyEngine->getSuggestionsForTruck($truck['id'], $truck['current_city_id']);
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Disposition - TransportBoss</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <div class="main-container">
        <h1 class="accent-text">Disposition (Tourenbildung)</h1>

        <!-- Fahrzeugauswahl -->
        <div class="form-box">
            <h3 class="accent-text">Aktive Fahrzeuge für Disposition</h3>
            <form method="get">
                <select name="truck_id" onchange="this.form.submit()" required>
                    <option value="">-- Fahrzeug auswählen --</option>
                    <?php foreach ($activeTrucks as $truck): ?>
                        <option value="<?= $truck['id'] ?>"
                            <?= $truck['id'] == $selectedTruckId ? 'selected' : '' ?>>
                            <?= htmlspecialchars("ID: {$truck['ingame_vehicle_id']} | {$truck['vehicle_type']} ({$truck['capacity_t']}t)") ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if ($selectedTruckId && $suggestions): ?>
            <hr style="border-color: #444; margin: 30px 0;">

            <h3 class="accent-text">Vorschläge für Fahrzeug ID: <?= htmlspecialchars($truck['ingame_vehicle_id']) ?></h3>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>Auftrags-ID</th>
                        <th>Route</th>
                        <th>Typ</th>
                        <th>Gewicht</th>
                        <th>Erlös</th>
                        <th>Distanz zur Abholung</th>
                        <th>Erlös/tkm</th>
                        <th>Status</th>
                        <th>Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($suggestions as $suggestion): ?>
                        <?php $order = $suggestion['order']; ?>
                        <tr>
                            <td><?= htmlspecialchars($order['ingame_order_id'] ?? 'Marktpool') ?></td>
                            <td>
                                <?= htmlspecialchars($order['from_city_name']) ?> →
                                <?= htmlspecialchars($order['to_city_name']) ?>
                            </td>
                            <td><?= htmlspecialchars($order['freight_type']) ?></td>
                            <td><?= $order['weight_total'] ?> t</td>
                            <td><?= number_format($order['revenue'], 2, ',', '.') ?> €</td>
                            <td><?= $suggestion['distance_to_order'] ?> km</td>
                            <td><?= number_format($suggestion['earning_per_tkm'], 2, ',', '.') ?> €/tkm</td>
                            <td class="<?= $order['is_accepted'] ? 'status-employed' : 'status-unemployed' ?>">
                                <?= $order['is_accepted'] ? 'Lager' : 'Marktpool' ?>
                            </td>
                            <td>
                                <form method="post" action="load_job.php" style="display: inline;">
                                    <input type="hidden" name="truck_id" value="<?= $truck['id'] ?>">
                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                    <button type="submit" class="btn-primary">Laden</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif ($selectedTruckId): ?>
            <p>Keine passenden Aufträge für dieses Fahrzeug gefunden.</p>
        <?php endif; ?>
    </div>
</body>
</html>