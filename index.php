<?php
declare(strict_types=1);

require_once 'db_connect.php';
require_once 'classes/TruckRepository.php';
require_once 'classes/DriverRepository.php';
require_once 'classes/DispatcherRepository.php';
require_once 'classes/OrderRepository.php';

// Initialisierung
$truckRepo = new TruckRepository($pdo);
$driverRepo = new DriverRepository($pdo);
$dispatcherRepo = new DispatcherRepository($pdo);
$orderRepo = new OrderRepository($pdo);

// KPIs laden
$totalTrucks = count($truckRepo->getAllOwned());
$activeTrucks = count($pdo->query("SELECT * FROM trucks WHERE is_active_planning = 1 AND assigned_driver_id IS NOT NULL")->fetchAll());
$totalDrivers = count($driverRepo->getAllEmployed());
$totalDispatchers = count($dispatcherRepo->getAllEmployed());
$openOrders = $orderRepo->getOpenOrdersCount();
$totalRevenue = $orderRepo->getTotalRevenue();

// Alle disponiblen Fahrzeuge (für Quick-Actions)
$dispoTrucks = $truckRepo->getActiveForPlanning();
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>TransportBoss Dashboard</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <?php require_once 'nav.php'; ?>
    <div class="fluid-container">
        <h1 class="accent-text">TransportBoss Dashboard</h1>

        <!-- KPI-Übersicht -->
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3 class="accent-text">Fahrzeuge</h3>
                <div class="kpi-value"><?= $totalTrucks ?></div>
                <div class="kpi-desc">Gesamt (disponierbar: <?= $activeTrucks ?>)</div>
            </div>
            <div class="dashboard-card">
                <h3 class="accent-text">Fahrer</h3>
                <div class="kpi-value"><?= $totalDrivers ?></div>
                <div class="kpi-desc">Eingestellt</div>
            </div>
            <div class="dashboard-card">
                <h3 class="accent-text">Disponenten</h3>
                <div class="kpi-value"><?= $totalDispatchers ?></div>
                <div class="kpi-desc">Eingestellt</div>
            </div>
            <div class="dashboard-card">
                <h3 class="accent-text">Aufträge</h3>
                <div class="kpi-value"><?= $openOrders ?></div>
                <div class="kpi-desc">Offen</div>
            </div>
            <div class="dashboard-card">
                <h3 class="accent-text">Umsatz</h3>
                <div class="kpi-value"><?= number_format($totalRevenue, 2, ',', '.') ?> €</div>
                <div class="kpi-desc">Gesamterlös</div>
            </div>
        </div>

        <hr class="section-divider">

        <!-- Modul-Übersicht -->
        <h2 class="accent-text">Module</h2>
        <div class="module-grid">
            <div class="dashboard-card">
                <h3 class="accent-text">🚚 Fuhrpark & Personal</h3>
                <p>Verwalte Fahrzeuge, Fahrer und deren Zuordnung.</p>
                <a href="fleet_manager.php" class="module-link">➔ Zum Fuhrpark-Manager</a>
            </div>
            <div class="dashboard-card">
                <h3 class="accent-text">🧑‍💼 Disponenten</h3>
                <p>Verwalte Disponenten und deren Bewerbungen.</p>
                <a href="dispatcher_manager.php" class="module-link">➔ Zum Disponenten-Manager</a>
            </div>
            <div class="dashboard-card">
                <h3 class="accent-text">🗺️ Disposition</h3>
                <p>Bilde optimale Touren für deine Fahrzeuge.</p>
                <a href="dispatcher_board.php" class="module-link">➔ Zur Disposition</a>
            </div>
            <div class="dashboard-card">
                <h3 class="accent-text">📦 Lageraufträge</h3>
                <p>Verwalte Aufträge aus deinem Lager.</p>
                <a href="warehouse_view.php" class="module-link">➔ Zu den Lageraufträgen</a>
            </div>
            <div class="dashboard-card">
                <h3 class="accent-text">💶 Frachtbörse</h3>
                <p>Durchstöbere verfügbare Marktaufträge.</p>
                <a href="market_pool.php" class="module-link">➔ Zur Frachtbörse</a>
            </div>
            <div class="dashboard-card">
                <h3 class="accent-text">🚛 Gebrauchtwagen</h3>
                <p>Analysiere den Gebrauchtwagenmarkt.</p>
                <a href="market_vehicles.php" class="module-link">➔ Zum Gebrauchtwagen-Markt</a>
            </div>
        </div>
    </div>
</body>
</html>