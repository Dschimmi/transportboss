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
    <div class="main-container">
        <h1 class="accent-text">TransportBoss Dashboard</h1>

        <!-- KPI-Übersicht -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
            <div style="background-color: #252525; padding: 20px; border-radius: 5px; border: 1px solid #444; text-align: center;">
                <h3 style="color: #f39c12; margin-top: 0;">Fahrzeuge</h3>
                <div style="font-size: 2.5em; font-weight: bold; color: #fff; margin: 10px 0;"><?= $totalTrucks ?></div>
                <div style="color: #aaa; font-size: 0.9em;">Gesamt (disponierbar: <?= $activeTrucks ?>)</div>
            </div>

            <div style="background-color: #252525; padding: 20px; border-radius: 5px; border: 1px solid #444; text-align: center;">
                <h3 style="color: #f39c12; margin-top: 0;">Fahrer</h3>
                <div style="font-size: 2.5em; font-weight: bold; color: #fff; margin: 10px 0;"><?= $totalDrivers ?></div>
                <div style="color: #aaa; font-size: 0.9em;">Eingestellt</div>
            </div>

            <div style="background-color: #252525; padding: 20px; border-radius: 5px; border: 1px solid #444; text-align: center;">
                <h3 style="color: #f39c12; margin-top: 0;">Disponenten</h3>
                <div style="font-size: 2.5em; font-weight: bold; color: #fff; margin: 10px 0;"><?= $totalDispatchers ?></div>
                <div style="color: #aaa; font-size: 0.9em;">Eingestellt</div>
            </div>

            <div style="background-color: #252525; padding: 20px; border-radius: 5px; border: 1px solid #444; text-align: center;">
                <h3 style="color: #f39c12; margin-top: 0;">Aufträge</h3>
                <div style="font-size: 2.5em; font-weight: bold; color: #fff; margin: 10px 0;"><?= $openOrders ?></div>
                <div style="color: #aaa; font-size: 0.9em;">Offen</div>
            </div>

            <div style="background-color: #252525; padding: 20px; border-radius: 5px; border: 1px solid #444; text-align: center;">
                <h3 style="color: #f39c12; margin-top: 0;">Umsatz</h3>
                <div style="font-size: 2.5em; font-weight: bold; color: #fff; margin: 10px 0;">
                    <?= number_format($totalRevenue, 2, ',', '.') ?> €
                </div>
                <div style="color: #aaa; font-size: 0.9em;">Gesamterlös</div>
            </div>
        </div>

        <hr style="border-color: #444; margin: 30px 0;">

        <!-- Modul-Übersicht -->
        <h2 class="accent-text">Module</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
            <div style="background-color: #252525; padding: 20px; border-radius: 5px; border: 1px solid #444; text-align: center;">
                <h3 style="color: #f39c12; margin-top: 0;">🚛 Fuhrpark & Personal</h3>
                <p>Verwalte Fahrzeuge, Fahrer und deren Zuordnung.</p>
                <a href="fleet_manager.php" style="display: block; margin-top: 15px; padding: 10px; background-color: #1e1e1e; color: #fff; text-decoration: none; border-radius: 3px;">→ Zum Fuhrpark-Manager</a>
            </div>

            <div style="background-color: #252525; padding: 20px; border-radius: 5px; border: 1px solid #444; text-align: center;">
                <h3 style="color: #f39c12; margin-top: 0;">👔 Disponenten</h3>
                <p>Verwalte Disponenten und deren Bewerbungen.</p>
                <a href="dispatcher_manager.php" style="display: block; margin-top: 15px; padding: 10px; background-color: #1e1e1e; color: #fff; text-decoration: none; border-radius: 3px;">→ Zum Disponenten-Manager</a>
            </div>

            <div style="background-color: #252525; padding: 20px; border-radius: 5px; border: 1px solid #444; text-align: center;">
                <h3 style="color: #f39c12; margin-top: 0;">🗺️ Disposition</h3>
                <p>Bilde optimale Touren für deine Fahrzeuge.</p>
                <a href="dispatcher_board.php" style="display: block; margin-top: 15px; padding: 10px; background-color: #1e1e1e; color: #fff; text-decoration: none; border-radius: 3px;">→ Zur Disposition</a>
            </div>

            <div style="background-color: #252525; padding: 20px; border-radius: 5px; border: 1px solid #444; text-align: center;">
                <h3 style="color: #f39c12; margin-top: 0;">📦 Lageraufträge</h3>
                <p>Verwalte Aufträge aus deinem Lager.</p>
                <a href="warehouse_view.php" style="display: block; margin-top: 15px; padding: 10px; background-color: #1e1e1e; color: #fff; text-decoration: none; border-radius: 3px;">→ Zu den Lageraufträgen</a>
            </div>

            <div style="background-color: #252525; padding: 20px; border-radius: 5px; border: 1px solid #444; text-align: center;">
                <h3 style="color: #f39c12; margin-top: 0;">🌍 Frachtbörse</h3>
                <p>Durchstöbere verfügbare Marktaufträge.</p>
                <a href="market_pool.php" style="display: block; margin-top: 15px; padding: 10px; background-color: #1e1e1e; color: #fff; text-decoration: none; border-radius: 3px;">→ Zur Frachtbörse</a>
            </div>

            <div style="background-color: #252525; padding: 20px; border-radius: 5px; border: 1px solid #444; text-align: center;">
                <h3 style="color: #f39c12; margin-top: 0;">💰 Gebrauchtwagen</h3>
                <p>Analysiere den Gebrauchtwagenmarkt.</p>
                <a href="market_vehicles.php" style="display: block; margin-top: 15px; padding: 10px; background-color: #1e1e1e; color: #fff; text-decoration: none; border-radius: 3px;">→ Zum Gebrauchtwagen-Markt</a>
            </div>

            <div style="background-color: #252525; padding: 20px; border-radius: 5px; border: 1px solid #444; text-align: center;">
                <h3 style="color: #f39c12; margin-top: 0;">📊 Entfernungsmatrix</h3>
                <p>Pflege die Entfernungen zwischen Städten.</p>
                <a href="import_matrix.php" style="display: block; margin-top: 15px; padding: 10px; background-color: #1e1e1e; color: #fff; text-decoration: none; border-radius: 3px;">→ Zur Matrix-Verwaltung</a>
            </div>
        </div>
    </div>
</body>
</html>