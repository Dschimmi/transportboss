<?php
declare(strict_types=1);

/**
 * orders_view.php
 *
 * Sichtensteuerung für die Frachtbörse (unverplante Pool-Aufträge).
 * Lädt offene Angebote, berechnet live die Rentabilität (€/km) über den DistanceService,
 * vergleicht diese mit den historischen Durchschnittserlösen der jeweiligen Warenkategorie,
 * deklariert eine farbliche Marge-Klasse und filtert über Multi-Vehicle-Select sowie Gewichtsspannen.
 *
 * @author TransportBoss Development
 * @version 1.4.0
 */

// Zentrale Abhängigkeiten laden
require_once 'db_connect.php';
require_once 'classes/DistanceService.php';

/**
 * OrdersViewController
 *
 * Kapselt alle Leseoperationen, die Rentabilitätsberechnung,
 * das historische Benchmarking und die Ranking-Logik für die Frachtbörsen-Übersicht.
 */
class OrdersViewController
{
    private PDO $pdo;
    private DistanceService $distanceService;

    /**
     * @param PDO $pdo Die aktive Datenbankverbindung
     * @param DistanceService $distanceService Der Dienst zur Entfernungsberechnung
     */
    public function __construct(PDO $pdo, DistanceService $distanceService)
    {
        $this->pdo = $pdo;
        $this->distanceService = $distanceService;
    }

    /**
     * Holt die historischen Durchschnitts-Erlöse pro Kilometer für jede Warenkategorie (PH § 2.6.4).
     *
     * @return array Assoziatives Array [commodity_name => avg_eur_per_km]
     */
    public function getHistoricalAverages(): array
    {
        $averages = [];
        $stmt = $this->pdo->query("
            SELECT 
                o.commodity,
                AVG(o.revenue / d.distance_km) AS avg_per_km
            FROM orders o
            JOIN distances d ON (
                (o.from_city_id = d.city_a_id AND o.to_city_id = d.city_b_id) OR
                (o.from_city_id = d.city_b_id AND o.to_city_id = d.city_a_id)
            )
            WHERE o.is_archived = 1 
              AND d.distance_km > 0
            GROUP BY o.commodity
        ");

        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $averages[$row['commodity']] = (float)$row['avg_per_km'];
            }
        }
        return $averages;
    }

    /**
     * Holt alle offenen Börsenangebote, berechnet die Marge pro Kilometer,
     * vergleicht diese mit den historischen Durchschnittswerten und sortiert nach Profitabilität.
     *
     * @param array $historicalAverages Die zuvor geladenen historischen Durchschnittswerte
     * @return array Liste der sortierten Angebote mit Marge und Farbstufen-Klassifizierung
     */
    public function getProfitabilityRanking(array $historicalAverages): array
    {
        // 1. Offene Börsen-Aufträge (is_accepted = 0, is_archived = 0) laden
        $stmt = $this->pdo->query("
            SELECT 
                o.freight_type, o.commodity, o.is_adr, o.weight_total, o.revenue,
                c1.name AS from_city, c1.id AS from_id,
                c2.name AS to_city, c2.id AS to_id
            FROM orders o
            JOIN cities c1 ON o.from_city_id = c1.id
            JOIN cities c2 ON o.to_city_id = c2.id
            WHERE o.is_accepted = 0 AND o.is_archived = 0
        ");
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Globaler Fallback-Wert, falls für eine Ware noch kein historischer Wert vorliegt (2.50 €/km)
        $globalAvg = !empty($historicalAverages) ? array_sum($historicalAverages) / count($historicalAverages) : 2.50;

        // 2. Ertrags-Kilometer-Marge live und on the fly kalkulieren
        foreach ($orders as &$order) {
            $km = $this->distanceService->getDistance((int)$order['from_id'], (int)$order['to_id']);
            $order['km'] = $km;
            
            // Division durch Null bei identischen Städten verhindern
            $currentKmRate = $km > 0 ? (float)$order['revenue'] / $km : 0.0;
            $order['eur_per_km'] = $currentKmRate;

            // Marge-Ratio im Vergleich zum historischen Durchschnitt bestimmen
            $commodity = $order['commodity'];
            $avgKmRate = isset($historicalAverages[$commodity]) ? $historicalAverages[$commodity] : $globalAvg;

            $ratio = $avgKmRate > 0 ? $currentKmRate / $avgKmRate : 1.0;

            // Zuweisung der 5 Marge-Klassen (Kein Inline-CSS!)
            if ($ratio >= 1.25) {
                $order['price_class'] = 'price-excellent'; // Tiefes Erfolgsgrün
            } elseif ($ratio >= 1.05) {
                $order['price_class'] = 'price-good';      // Helles Grün
            } elseif ($ratio >= 0.95) {
                $order['price_class'] = 'price-average';   // Neutrales Gelb
            } elseif ($ratio >= 0.75) {
                $order['price_class'] = 'price-bad';       // Warnendes Orange
            } else {
                $order['price_class'] = 'price-very-bad';  // Kritisches Signalrot
            }
        }
        unset($order); // Referenz aufheben

        // 3. Absteigend sortieren (lukrativste zuerst)
        usort($orders, fn($a, $b) => $b['eur_per_km'] <=> $a['eur_per_km']);

        return $orders;
    }
}

// Controller instanziieren und verarbeiten
$distanceService = new DistanceService($pdo);
$controller = new OrdersViewController($pdo, $distanceService);
$historicalAverages = $controller->getHistoricalAverages();
$rankedOrders = $controller->getProfitabilityRanking($historicalAverages);

// Daten für die Dropdown-Filter extrahieren (Einzigartige Werte sammeln)
$uniqueStarts = array_unique(array_column($rankedOrders, 'from_city'));
$uniqueZiels = array_unique(array_column($rankedOrders, 'to_city'));

sort($uniqueStarts);
sort($uniqueZiels);

// Die 12 offiziellen Fahrzeugklassen laut Pflichtenheft (PH § 2.4.1.4)
$allowedTruckTypes = ['Kurier', 'Stückgut', 'Schüttgut', 'Pritsche', 'Plane', 'Koffer', 'Kühlwagen', 'Silo', 'Tankwagen', 'Schwertransport', 'ISO-Container', 'Super-Liner'];

// Hilfsfunktion zur Normalisierung von Ingame-Frachtbezeichnungen auf unsere 12 Klassen
$normalizeFreight = function(string $type): string {
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
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Frachtbörse Übersicht - TransportBoss</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <?php require_once 'nav.php'; ?>
    <div class="fluid-container">
        
        <!-- Header mit Link zum Börsen-Import -->
        <div class="workspace-header-row">
            <h1 class="accent-text">Lukrativste Aufträge (Pool)</h1>
            <a href="market_pool.php" class="btn-primary">+ Börsen-Import (Angebote einlesen)</a>
        </div>
        
        <!-- REAKTIVE FILTER-BAR (Sauber nebeneinander über Flexbox) -->
        <div class="filter-panel">
            <div class="filter-group">
                <label for="filterStart">Startort:</label>
                <select id="filterStart" class="inline-select" onchange="applyFilters()">
                    <option value="">-- Alle --</option>
                    <?php foreach ($uniqueStarts as $start): ?>
                        <option value="<?php echo htmlspecialchars($start); ?>"><?php echo htmlspecialchars($start); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="filterZiel">Zielort:</label>
                <select id="filterZiel" class="inline-select" onchange="applyFilters()">
                    <option value="">-- Alle --</option>
                    <?php foreach ($uniqueZiels as $ziel): ?>
                        <option value="<?php echo htmlspecialchars($ziel); ?>"><?php echo htmlspecialchars($ziel); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="filterMinKm">Min. €/km:</label>
                <input type="number" id="filterMinKm" step="0.1" min="0" placeholder="z.B. 2.5" class="inline-select filter-min-km" onkeyup="applyFilters()" onchange="applyFilters()">
            </div>

            <div class="filter-group">
                <label for="filterMinWeight">Min. Gewicht (t):</label>
                <input type="number" id="filterMinWeight" min="0" placeholder="Min" class="inline-select filter-weight" onkeyup="applyFilters()" onchange="applyFilters()">
            </div>

            <div class="filter-group">
                <label for="filterMaxWeight">Max. Gewicht (t):</label>
                <input type="number" id="filterMaxWeight" min="0" placeholder="Max" class="inline-select filter-weight" onkeyup="applyFilters()" onchange="applyFilters()">
            </div>

            <div class="filter-group filter-search-group">
                <label for="tableFilter">Multisearch:</label>
                <input type="text" id="tableFilter" class="filter-input" placeholder="Tabelle durchsuchen..." onkeyup="applyFilters()">
            </div>

            <!-- MEHRFACHAUSWAHL FAHRZEUGTYPEN (Checkbox-Leiste) -->
            <div class="filter-group filter-vehicle-group">
                <label>Benötigter Fahrzeugtyp (Mehrfachauswahl möglich):</label>
                <div class="checkbox-filter-container">
                    <?php foreach ($allowedTruckTypes as $type): ?>
                        <label class="checkbox-filter-label">
                            <input type="checkbox" value="<?php echo htmlspecialchars($type); ?>" class="filter-vehicle-checkbox" onchange="applyFilters()">
                            <?php echo htmlspecialchars($type); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- DATEN-TABELLE -->
        <table class="data-table" id="sortableTable">
            <thead>
                <tr>
                    <th onclick="sortTable(0, 'string')">Von ⇕</th>
                    <th onclick="sortTable(1, 'string')">Nach ⇕</th>
                    <th onclick="sortTable(2, 'string')">Ware (Typ) ⇕</th>
                    <th onclick="sortTable(3, 'number')">Gewicht ⇕</th>
                    <th onclick="sortTable(4, 'number')">Umsatz ⇕</th>
                    <th onclick="sortTable(5, 'number')">Distanz ⇕</th>
                    <th onclick="sortTable(6, 'number')">€ / km ⇕</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rankedOrders as $o): ?>
            <tr class="filterable-row" 
                data-from="<?php echo htmlspecialchars($o['from_city']); ?>" 
                data-to="<?php echo htmlspecialchars($o['to_city']); ?>" 
                data-freight-type="<?php echo htmlspecialchars($normalizeFreight($o['freight_type'])); ?>"
                data-weight="<?php echo $o['weight_total']; ?>">
                <td><?php echo htmlspecialchars($o['from_city']); ?></td>
                <td><?php echo htmlspecialchars($o['to_city']); ?></td>
                <td>
                    <?php echo $o['is_adr'] ? '<span class="adr-badge">[ADR]</span>' : ''; ?>
                    <?php echo htmlspecialchars($o['commodity']); ?> (<?php echo htmlspecialchars($o['freight_type']); ?>)
                </td>
                <td><?php echo $o['weight_total']; ?> t</td>
                <td><?php echo number_format((float)$o['revenue'], 2, ',', '.'); ?> €</td>
                <td><?php echo $o['km']; ?> km</td>
                <!-- Farblich deklariertes Kilometer-Erlös Feld -->
                <td class="<?php echo $o['price_class']; ?>">
                    <strong><?php echo number_format($o['eur_per_km'], 2, ',', '.'); ?> €</strong>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Client-Side Filter & Sort Logik -->
    <script>
        /**
         * Speichert den aktuellen Zustand aller Filter im lokalen Speicher des Browsers.
         */
        function saveFilterState() {
            const state = {
                start: document.getElementById('filterStart').value,
                ziel: document.getElementById('filterZiel').value,
                minKm: document.getElementById('filterMinKm').value,
                minWeight: document.getElementById('filterMinWeight').value,
                maxWeight: document.getElementById('filterMaxWeight').value,
                search: document.getElementById('tableFilter').value,
                vehicles: Array.from(document.querySelectorAll('.filter-vehicle-checkbox:checked')).map(cb => cb.value)
            };
            localStorage.setItem('tb_market_filters', JSON.stringify(state));
        }

        /**
         * Liest die Filter aus dem lokalen Speicher und stellt sie im HTML wieder her.
         */
        function restoreFilterState() {
            const raw = localStorage.getItem('tb_market_filters');
            if (!raw) return;
            try {
                const state = JSON.parse(raw);
                document.getElementById('filterStart').value = state.start || '';
                document.getElementById('filterZiel').value = state.ziel || '';
                document.getElementById('filterMinKm').value = state.minKm || '';
                document.getElementById('filterMinWeight').value = state.minWeight || '';
                document.getElementById('filterMaxWeight').value = state.maxWeight || '';
                document.getElementById('tableFilter').value = state.search || '';
                
                const checkedVehicles = state.vehicles || [];
                document.querySelectorAll('.filter-vehicle-checkbox').forEach(cb => {
                    cb.checked = checkedVehicles.includes(cb.value);
                });
            } catch (e) {
                // Fehler im Speicher geräuschlos abfangen
            }
        }

        /**
         * Reaktive Multi-Filtersteuerung.
         * Filtert gleichzeitig nach Start, Ziel, Mindesterlös, Gewichtsspanne, Text und MEHREREN Fahrzeugtypen (OR-Verknüpfung).
         */
        function applyFilters() {
            const startVal = document.getElementById('filterStart').value.toLowerCase();
            const zielVal = document.getElementById('filterZiel').value.toLowerCase();
            const minKmVal = parseFloat(document.getElementById('filterMinKm').value) || 0;
            const minWeightVal = parseFloat(document.getElementById('filterMinWeight').value) || 0;
            const maxWeightVal = parseFloat(document.getElementById('filterMaxWeight').value) || 999999;
            const searchVal = document.getElementById('tableFilter').value.toLowerCase();

            // Sammelt alle aktivierten Fahrzeugtyp-Checkboxen
            const checkedVehicles = Array.from(document.querySelectorAll('.filter-vehicle-checkbox:checked')).map(cb => cb.value.toLowerCase());

            const rows = document.querySelectorAll('#sortableTable tbody tr');
            
            // Zerlegt den Freitext bei Leerzeichen für echte Multisearch-Verknüpfung
            const searchKeywords = searchVal.split(/\s+/).filter(k => k.trim() !== '');

            rows.forEach(row => {
                const fromCity = row.getAttribute('data-from').toLowerCase();
                const toCity = row.getAttribute('data-to').toLowerCase();
                const freightType = row.getAttribute('data-freight-type').toLowerCase();
                const weight = parseFloat(row.getAttribute('data-weight')) || 0;
                const eurPerKm = parseFloat(row.getAttribute('data-eur-per-km')) || 0;
                const textContent = row.textContent.toLowerCase();

                // Evaluierung der Dropdown- und numerischen Filter
                const matchStart = (startVal === '' || fromCity === startVal);
                const matchZiel = (zielVal === '' || toCity === zielVal);
                const matchMinKm = (eurPerKm >= minKmVal);
                const matchWeight = (weight >= minWeightVal && weight <= maxWeightVal);

                // Mehrfachauswahl-Filter
                const matchVehicle = (checkedVehicles.length === 0 || checkedVehicles.includes(freightType));

                // Evaluierung der Freitext-Multisearch (UND-Verknüpfung aller getippten Wörter)
                let matchSearch = true;
                for (let kw of searchKeywords) {
                    if (!textContent.includes(kw)) {
                        matchSearch = false;
                        break;
                    }
                }

                // Zeile ein- oder ausblenden
                if (matchStart && matchZiel && matchMinKm && matchWeight && matchVehicle && matchSearch) {
                    row.classList.remove('hidden-row');
                } else {
                    row.classList.add('hidden-row');
                }
            });

            // Filterzustand nach jeder Änderung persistent speichern
            saveFilterState();
        }

        /**
         * Automatisches Laden und Anwenden der Filter beim Betreten der Seite.
         */
        window.addEventListener('DOMContentLoaded', () => {
            restoreFilterState();
            applyFilters();
        });

        // --- Sortier-Logik ---
        let sortDirections = [false, false, false, false, false, false, true]; 

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