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
 * @version 1.2.0
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
        
        <!-- Fuhrpark (LKWs, Neukauf, Gebrauchtwagen-Übernahme, Updates) -->
        <a href="fleet_manager.php">Fuhrpark</a>
        
        <!-- Personal (Fahrer und Disponenten, Bewerbungen, Angestellte) -->
        <a href="personnel_manager.php">Personal</a>
        
        <!-- Disposition (Das interaktive Board zur Tourenplanung) -->
        <a href="dispatcher_board.php">Disposition</a>
        
        <!-- Eigene angenommene Lageraufträge (warehouse_view.php) -->
        <a href="warehouse_view.php">Lager</a>
        
        <!-- Frachtbörse (Übersicht der lukrativsten Pool-Angebote) -->
        <a href="orders_view.php">Frachtbörse</a>
        
        <!-- Gebrauchtwagenmarkt (Fahrzeughandel-Import) -->
        <a href="market_vehicles.php">Fahrzeugmarkt</a>

        <!-- Entfernungs-Matrix Verwaltung -->
        <a href="matrix_admin.php">Matrix</a>

        <!-- Die globale Spieler-Rangliste und Konkurrenzüberwachung -->
        <a href="ranking_manager.php">Rangliste</a>
    </div>
</nav>

<!-- Globale Ein-Klick-Kopierfunktion mit dauerhafter Hervorhebung (PH § 1.4.5) -->
<script>
(function() {
    let lastCopiedElement = null;

    document.addEventListener('click', function(e) {
        const target = e.target ? e.target.closest('.copy-city') : null;

        if (target) {
            let textToCopy = target.textContent.trim();

            // 1. Suffix (-1, -2 etc.) bei IDN-Nummern vor dem Kopieren abschneiden
            if (textToCopy.startsWith('IDN') && textToCopy.includes('-')) {
                textToCopy = textToCopy.split('-')[0];
            }

            // 2. Deutsches Währungsformat (z.B. "3.407,94 €") in US-Such-Format ("3,407.94") konvertieren
            if (/[0-9]/.test(textToCopy) && textToCopy.includes(',')) {
                textToCopy = textToCopy.replace(/[^\d.,-]/g, '');
                textToCopy = textToCopy.split('.').join('TEMP').replace(',', '.').split('TEMP').join(',');
            }

            // Native Zwischenablage-API nutzen
            navigator.clipboard.writeText(textToCopy).then(() => {
                // Vorheriges kopiertes Element zurücksetzen
                if (lastCopiedElement && lastCopiedElement !== target) {
                    lastCopiedElement.classList.remove('copied-active');
                }
                // Neues Element dauerhaft hervorheben
                target.classList.add('copied-active');
                lastCopiedElement = target;
            }).catch(err => {
                // Geräuschloser Fallback
            });
        } else {
            // Klick außerhalb eines Kopier-Elements: Letzte Hervorhebung zurücksetzen
            if (lastCopiedElement && !e.target.closest('.copy-city')) {
                lastCopiedElement.classList.remove('copied-active');
                lastCopiedElement = null;
            }
        }
    }, true); // Parameter true aktiviert Event Capturing vor stopPropagation()
})();
</script>