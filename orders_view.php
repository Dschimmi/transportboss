<?php
declare(strict_types=1);

/**
 * orders_view.php
 *
 * Sichtensteuerung für die Frachtbörse (unverplante Pool-Aufträge).
 * Erweitert zu einem intelligenten Dispositions-Leitstand:
 * - Filter-Reparatur für "Min. €/km:"
 * - Autopilot-Vorschlags-Brücke: Highlights für exklusiv eingeplante Autopilot-Frachten [DISPO-VORSCHLAG] (Chronologisch sortiert!)
 * - Automatische Highlight-Kennzeichnung für Mangel-Standorte [FEHLT-AUSGLEICH]
 * - Dreistufige, strategische Prioritäts-Sortierung
 *
 * @author TransportBoss Development
 * @version 2.3.0
 */

// Zentrale Abhängigkeiten laden
require_once 'db_connect.php';
require_once 'classes/DistanceService.php';
require_once 'classes/TopologyEngine.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Flash-Feedback aus dem market_pool Importer empfangen (Automatischer Redirect!)
$message = $_SESSION['pb_pool_message'] ?? '';
$messageClass = $_SESSION['pb_pool_message_class'] ?? '';
unset($_SESSION['pb_pool_message'], $_SESSION['pb_pool_message_class']);

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
        // Offene Börsen-Aufträge (is_accepted = 0, is_archived = 0) laden (ID für Dispo-Abgleich hinzugefügt!)
        $stmt = $this->pdo->query("
            SELECT 
                o.id, o.freight_type, o.commodity, o.is_adr, o.weight_total, o.revenue,
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

        // Ertrags-Kilometer-Marge live und on the fly kalkulieren
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

        return $orders;
    }
}

// Controller instanziieren und verarbeiten
$distanceService = new DistanceService($pdo);
$controller = new OrdersViewController($pdo, $distanceService);
$historicalAverages = $controller->getHistoricalAverages();
$rankedOrders = $controller->getProfitabilityRanking($historicalAverages);

// -------------------------------------------------------------
// CHRONOLOGISCHE AUTOPILOT-BRÜCKE & LEITSTAND-DIAGNOSTIK
// -------------------------------------------------------------

// A. Mangel-Städte (FEHLT-Ausgleich) ermitteln über den CityService (PH § 1.3.1)
require_once 'classes/CityService.php';
$cityServiceForPr = new CityService($pdo);
$missingCities = $cityServiceForPr->getEmptyWarehouseCities();

// B. Von aktiven LKW empfohlene Marktpool-Aufträge (DISPO-VORSCHLAG) ermitteln (NUR AUTOPILOT CHRONOLOGISCH!)
$recommendedIds = [];
$recommendedOrderIndices = []; // Speichert die chronologische Reihenfolge für die korrekte Sortierung

$activeTrucks = $pdo->query("
    SELECT id, current_city_id, vehicle_type, capacity_t, assigned_driver_id 
    FROM trucks 
    WHERE is_active_planning = 1 AND assigned_driver_id IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);

if (!empty($activeTrucks)) {
    $topologyEngine = new TopologyEngine($pdo, $distanceService);

    // Berechne die globalen Ketten über die einheitliche Klassen-Methode (Redundanzfrei!)
    $suggestedChains = $topologyEngine->calculateAutopilotChains();

    foreach ($suggestedChains as $truckId => $chain) {
        foreach ($chain as $step) {
            // Nur Marktpool-Aufträge werden für den Leitstand ausgewertet
            if ($step['status'] === 'market') {
                $orderId = (int)$step['order']['id'];
                $recommendedIds[] = $orderId;
                
                // Chronologischen Index für dieses Auftragssegment registrieren
                if (!isset($recommendedOrderIndices[$orderId])) {
                    $recommendedOrderIndices[$orderId] = count($recommendedOrderIndices);
                }
            }
        }
    }
}
$recommendedIds = array_unique($recommendedIds);

// C. Prioritäten und Reihenfolgen-Index vergeben
foreach ($rankedOrders as &$o) {
    $oId = (int)$o['id'];
    $o['is_recommended_priority'] = in_array($oId, $recommendedIds, true) ? 1 : 0;
    // Nicht empfohlene Aufträge erhalten einen unendlich hohen Index, damit sie hinten anstehen
    $o['recommended_index'] = $recommendedOrderIndices[$oId] ?? 999999;
    $o['is_missing_priority'] = in_array($o['from_city'], $missingCities, true) ? 1 : 0;
}
unset($o);

// Drei-Stufen-Priorität mit chronologischem Index-Filter
usort($rankedOrders, function($a, $b) {
    if ($a['is_recommended_priority'] !== $b['is_recommended_priority']) {
        return $b['is_recommended_priority'] <=> $a['is_recommended_priority'];
    }
    // Wenn beide empfohlen sind, sortiere nach der chronologischen Fahrtreihenfolge (ASC!)
    if ($a['is_recommended_priority'] === 1 && $a['recommended_index'] !== $b['recommended_index']) {
        return $a['recommended_index'] <=> $b['recommended_index'];
    }
    if ($a['is_missing_priority'] !== $b['is_missing_priority']) {
        return $b['is_missing_priority'] <=> $a['is_missing_priority'];
    }
    return $b['eur_per_km'] <=> $a['eur_per_km'];
});

// Daten für die Dropdown-Filter extrahieren
$uniqueStarts = array_unique(array_column($rankedOrders, 'from_city'));
$uniqueZiels = array_unique(array_column($rankedOrders, 'to_city'));
sort($uniqueStarts);
sort($uniqueZiels);

$allowedTruckTypes = ['Kurier', 'Stückgut', 'Schüttgut', 'Pritsche', 'Plane', 'Koffer', 'Kühlwagen', 'Silo', 'Tankwagen', 'Schwertransport', 'ISO-Container', 'Super-Liner'];

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
            <h1 class="accent-text">Börsen-Leitstand (Umsatz-Optimierung)</h1>
            <a href="market_pool.php" class="btn-primary">+ Börsen-Import (Angebote einlesen)</a>
        </div>

        <?php if ($message): ?>
            <div class="feedback-msg <?= $messageClass ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
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

            <!-- MEHRFACHAUSWAHL FAHRZEUGTYPEN -->
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
                data-weight="<?php echo $o['weight_total']; ?>"
                data-eur-per-km="<?php echo $o['eur_per_km']; ?>"> <!-- KORREKTUR: Filter-Attribut hinzugefügt! -->
                <td><?php echo htmlspecialchars($o['from_city']); ?></td>
                <td><?php echo htmlspecialchars($o['to_city']); ?></td>
                <td>
                    <!-- Strategische Leitstands-Hervorhebungen (PH-konforme Badges) -->
                    <?php if ($o['is_recommended_priority']): ?>
                        <span class="adr-badge" title="Dieser Auftrag wird aktiv im LKW-Fahrplan des Autopiloten empfohlen!">[DISPO-VORSCHLAG]</span>
                    <?php endif; ?>
                    <?php if ($o['is_missing_priority']): ?>
                        <span class="badge-missing" title="Diese Stadt hat aktuell 0 Lager-Aufträge!">[FEHLT-AUSGLEICH]</span>
                    <?php endif; ?>
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
         */
        function applyFilters() {
            const startVal = document.getElementById('filterStart').value.toLowerCase();
            const zielVal = document.getElementById('filterZiel').value.toLowerCase();
            const minKmVal = parseFloat(document.getElementById('filterMinKm').value) || 0;
            const minWeightVal = parseFloat(document.getElementById('filterMinWeight').value) || 0;
            const maxWeightVal = parseFloat(document.getElementById('filterMaxWeight').value) || 999999;
            const searchVal = document.getElementById('tableFilter').value.toLowerCase();

            const checkedVehicles = Array.from(document.querySelectorAll('.filter-vehicle-checkbox:checked')).map(cb => cb.value.toLowerCase());

            const rows = document.querySelectorAll('#sortableTable tbody tr');
            const searchKeywords = searchVal.split(/\s+/).filter(k => k.trim() !== '');

            rows.forEach(row => {
                const fromCity = row.getAttribute('data-from').toLowerCase();
                const toCity = row.getAttribute('data-to').toLowerCase();
                const freightType = row.getAttribute('data-freight-type').toLowerCase();
                const weight = parseFloat(row.getAttribute('data-weight')) || 0;
                const eurPerKm = parseFloat(row.getAttribute('data-eur-per-km')) || 0;
                const textContent = row.textContent.toLowerCase();

                const matchStart = (startVal === '' || fromCity === startVal);
                const matchZiel = (zielVal === '' || toCity === zielVal);
                const matchMinKm = (eurPerKm >= minKmVal);
                const matchWeight = (weight >= minWeightVal && weight <= maxWeightVal);
                const matchVehicle = (checkedVehicles.length === 0 || checkedVehicles.includes(freightType));

                let matchSearch = true;
                for (let kw of searchKeywords) {
                    if (!textContent.includes(kw)) {
                        matchSearch = false;
                        break;
                    }
                }

                if (matchStart && matchZiel && matchMinKm && matchWeight && matchVehicle && matchSearch) {
                    row.classList.remove('hidden-row');
                } else {
                    row.classList.add('hidden-row');
                }
            });

            saveFilterState();
        }

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