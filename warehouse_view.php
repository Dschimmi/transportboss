<?php
declare(strict_types=1);

require_once 'db_connect.php';
require_once 'classes/DistanceService.php';

// Service für die Entfernungs-Matrix initialisieren
$distanceService = new DistanceService($pdo);

// Lade alle akzeptierten Lager-Aufträge (is_accepted = 1) inkl. aufgelöster Städtenamen
$stmt = $pdo->query("
    SELECT 
        o.ingame_order_id, o.freight_type, o.commodity, o.is_adr, 
        o.weight_total, o.weight_remaining, o.revenue,
        c1.name AS from_city, c1.id AS from_id,
        c2.name AS to_city, c2.id AS to_id
    FROM orders o
    JOIN cities c1 ON o.from_city_id = c1.id
    JOIN cities c2 ON o.to_city_id = c2.id
    WHERE o.is_accepted = 1 AND o.is_archived = 0
");
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Berechne die Rentabilität (€/km) für jeden Auftrag
foreach ($orders as &$order) {
    $km = $distanceService->getDistance((int)$order['from_id'], (int)$order['to_id']);
    $order['km'] = $km;
    
    // Schutz vor Division durch Null
    $order['eur_per_km'] = $km > 0 ? $order['revenue'] / $km : 0;
}
unset($order);

// Standard-Sortierung: Lukrativste zuerst
usort($orders, fn($a, $b) => $b['eur_per_km'] <=> $a['eur_per_km']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Lager Übersicht - TransportBoss</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <div class="main-container" style="max-width: 1000px;">
        <h1 class="accent-text">Eigenes Lager (Angenommene Aufträge)</h1>
        
        <!-- Filter-Eingabefeld -->
        <input type="text" id="tableFilter" class="filter-input" placeholder="Tabelle durchsuchen (z.B. Stadt, IDN, Ware)...">
        
        <table class="data-table" id="sortableTable">
            <thead>
                <tr>
                    <th onclick="sortTable(0, 'string')">IDN ↕</th>
                    <th onclick="sortTable(1, 'string')">Von ↕</th>
                    <th onclick="sortTable(2, 'string')">Nach ↕</th>
                    <th onclick="sortTable(3, 'string')">Ware (Typ) ↕</th>
                    <th onclick="sortTable(4, 'number')">Gewicht (Rest/Gesamt) ↕</th>
                    <th onclick="sortTable(5, 'number')">Umsatz ↕</th>
                    <th onclick="sortTable(6, 'number')">Distanz ↕</th>
                    <th class="accent-text" onclick="sortTable(7, 'number')">€ / km ↕</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
            <tr>
                <td><?= htmlspecialchars($o['ingame_order_id'] ?? '-') ?></td>
                <td><?= htmlspecialchars($o['from_city']) ?></td>
                <td><?= htmlspecialchars($o['to_city']) ?></td>
                <td>
                    <?= $o['is_adr'] ? '<span class="adr-badge">[ADR]</span>' : '' ?>
                    <?= htmlspecialchars($o['commodity']) ?> (<?= htmlspecialchars($o['freight_type']) ?>)
                </td>
                <td><?= $o['weight_remaining'] ?> / <?= $o['weight_total'] ?> t</td>
                <td><?= number_format((float)$o['revenue'], 2) ?> €</td>
                <td><?= $o['km'] ?> km</td>
                <td class="accent-text"><strong><?= number_format($o['eur_per_km'], 2) ?> €</strong></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Client-Side Filter & Sort Logik -->
    <script>
        // --- Filter-Logik ---
        document.getElementById('tableFilter').addEventListener('keyup', function() {
            let filter = this.value.toLowerCase();
            let rows = document.querySelectorAll('#sortableTable tbody tr');
            
            rows.forEach(row => {
                let text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });

        // --- Sortier-Logik ---
        let sortDirections = [false, false, false, false, false, false, false, true]; 

        function sortTable(columnIndex, type) {
            let table = document.getElementById("sortableTable");
            let tbody = table.querySelector("tbody");
            let rows = Array.from(tbody.querySelectorAll("tr"));
            
            let dir = !sortDirections[columnIndex];
            sortDirections[columnIndex] = dir;

            rows.sort((a, b) => {
                let valA = a.children[columnIndex].innerText.trim();
                let valB = b.children[columnIndex].innerText.trim();

                if (type === 'number') {
                    // Spezielle Behandlung für das Gewicht "Rest / Gesamt" (Wir sortieren nach dem Restgewicht)
                    if (valA.includes('/')) valA = valA.split('/')[0].trim();
                    if (valB.includes('/')) valB = valB.split('/')[0].trim();

                    // Bereinigung für Floats
                    valA = parseFloat(valA.replace(/[^0-9,-]+/g, '').replace(',', '.'));
                    valB = parseFloat(valB.replace(/[^0-9,-]+/g, '').replace(',', '.'));
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
    </script>
</body>
</html>