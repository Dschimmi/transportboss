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
$activeTrucks = count($truckRepo->getActiveForPlanning());
$totalDrivers = count($driverRepo->getAllEmployed());
$totalDispatchers = count($dispatcherRepo->getAllEmployed());
$openOrders = $orderRepo->getOpenOrdersCount(); // Siehe nächste Aufgabe
$totalRevenue = $orderRepo->getTotalRevenue(); // Siehe nächste Aufgabe
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>TransportBoss Dashboard</title>
    <link rel="stylesheet" href="main.css">
    <style>
        /* Dashboard-spezifische Stile (können später in main.css verschoben werden) */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .kpi-card {
            background-color: #252525;
            padding: 20px;
            border-radius: 5px;
            border: 1px solid #444;
            text-align: center;
        }
        .kpi-card h3 {
            color: #f39c12;
            margin-top: 0;
            font-size: 1.1em;
        }
        .kpi-value {
            font-size: 2.5em;
            font-weight: bold;
            color: #fff;
            margin: 10px 0;
        }
        .kpi-label {
            color: #aaa;
            font-size: 0.9em;
        }
        .module-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        .module-card {
            background-color: #252525;
            padding: 20px;
            border-radius: 5px;
            border: 1px solid #444;
            text-align: center;
        }
        .module-card h3 {
            color: #f39c12;
            margin-top: 0;
        }
        .module-card a {
            display: block;
            margin-top: 15px;
            padding: 10px;
            background-color: #1e1e1e;
            color: #fff;
            text-decoration: none;
            border-radius: 3px;
            transition: background-color 0.2s;
        }
        .module-card a:hover {
            background-color: #f39c12;
            color: #000;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <h1 class="accent-text">TransportBoss Dashboard</h1>

        <!-- KPI-Übersicht -->
        <div class="dashboard-grid">
            <div class="kpi-card">
                <h3>Fuhrpark</h3>
                <div class="kpi-value"><?= $totalTrucks ?></div>
                <div class="kpi-label">Gesamtfahrzeuge</div>
                <div style="margin-top: 10px; color: #2ecc71;">Aktiv: <?= $activeTrucks ?></div>
            </div>

            <div class="kpi-card">
                <h3>Fahrer</h3>
                <div class="kpi-value"><?= $totalDrivers ?></div>
                <div class="kpi-label">Eingestellte Fahrer</div>
            </div>

            <div class="kpi-card">
                <h3>Disponenten</h3>
                <div class="kpi-value"><?= $totalDispatchers ?></div>
                <div class="kpi-label">Eingestellte Disponenten</div>
            </div>

            <div class="kpi-card">
                <h3>Aufträge</h3>
                <div class="kpi-value"><?= $openOrders ?></div>
                <div class="kpi-label">Offene Aufträge</div>
            </div>

            <div class="kpi-card">
                <h3>Umsatz</h3>
                <div class="kpi-value"><?= number_format($totalRevenue, 2, ',', '.') ?> €</div>
                <div class="kpi-label">Gesamterlös</div>
            </div>
        </div>

        <hr style="border-color: #444; margin: 30px 0;">

        <!-- Modul-Übersicht -->
        <h2 class="accent-text">Module</h2>
        <div class="module-grid">
            <div class="module-card">
                <h3>🚛 Fuhrpark & Personal</h3>
                <p>Verwalte Fahrzeuge, Fahrer und deren Zuordnung.</p>
                <a href="fleet_manager.php">→ Zum Fuhrpark-Manager</a>
            </div>

            <div class="module-card">
                <h3>👔 Disponenten</h3>
                <p>Verwalte Disponenten und deren Bewerbungen.</p>
                <a href="dispatcher_manager.php">→ Zum Disponenten-Manager</a>
            </div>

            <div class="module-card">
                <h3>🗺️ Disposition</h3>
                <p>Bilde optimale Touren für deine Fahrzeuge.</p>
                <a href="dispatcher.php">→ Zur Disposition</a>
            </div>

            <div class="module-card">
                <h3>📦 Lageraufträge</h3>
                <p>Verwalte Aufträge aus deinem Lager.</p>
                <a href="warehouse_view.php">→ Zu den Lageraufträgen</a>
            </div>

            <div class="module-card">
                <h3>🌍 Frachtbörse</h3>
                <p>Durchstöbere verfügbare Marktaufträge.</p>
                <a href="market_pool.php">→ Zur Frachtbörse</a>
            </div>

            <div class="module-card">
                <h3>💰 Gebrauchtwagen</h3>
                <p>Analysiere den Gebrauchtwagenmarkt.</p>
                <a href="market_vehicles.php">→ Zum Gebrauchtwagen-Markt</a>
            </div>

            <div class="module-card">
                <h3>📊 Entfernungsmatrix</h3>
                <p>Pflege die Entfernungen zwischen Städten.</p>
                <a href="import_matrix.php">→ Zur Matrix-Verwaltung</a>
            </div>
        </div>
    </div>
</body>
</html>