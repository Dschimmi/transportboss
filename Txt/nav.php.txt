<?php
declare(strict_types=1);

/**
 * nav.php
 *
 * Die obere globale Navigationsleiste der TransportBoss-Plattform.
 * Bietet den schnellen Zugriff auf alle operativen ERP-Module und stellt
 * konsistente Verknüpfungen zu den jeweiligen Übersichtsseiten her.
 *
 * @author TransportBoss Development
 * @version 1.1.0
 */
?>
<!-- Globale Navigations-Komponente (Zentrales Stylesheet: main.css) -->
<nav class="top-nav">
    <!-- Markenlogo / Link zur Startseite (Dashboard) -->
    <a href="index.php" class="nav-brand">TransportBoss</a>
    
    <!-- Navigationsgliederung -->
    <div class="nav-links">
        <!-- Haupt-Dashboard mit KPIs -->
        <a href="index.php">Dashboard</a>
        
        <!-- Fuhrpark & Personal (LKWs und Fahrerzuweisung) -->
        <a href="fleet_manager.php">Fuhrpark & Personal</a>
        
        <!-- Disponenten-Manager (CRUD, Slots & Bewerbungen) -->
        <a href="dispatcher_manager.php">Disponenten</a>
        
        <!-- Disposition (Das interaktive Board zur Tourenplanung) -->
        <a href="dispatcher_board.php">Disposition</a>
        
        <!-- Eigene angenommene Lageraufträge (warehouse_view.php) -->
        <a href="warehouse_view.php">Lager</a>
        
        <!-- Frachtbörse (Übersicht der lukrativsten Pool-Angebote; korrigiert von market_pool.php) -->
        <a href="orders_view.php">Frachtbörse</a>
        
        <!-- Gebrauchtwagenmarkt (Fahrzeughandel-Import) -->
        <a href="market_vehicles.php">Fahrzeugmarkt</a>
    </div>
</nav>