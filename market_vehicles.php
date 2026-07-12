<?php
declare(strict_types=1);

/**
 * market_vehicles.php
 *
 * Das Gebrauchtwagen-Cockpit von TransportBoss.
 * Ermöglicht den Import von Fahrzeughandels-Daten, vergleicht Angebote mit dem
 * historischen Typ-Durchschnitt und warnt vor überalterten Fahrzeugen (PH § 5.2).
 *
 * @author TransportBoss Development
 * @version 2.7.0
 */

require_once 'db_connect.php';
require_once 'classes/FinanceMapper.php';
require_once 'classes/FinanceViewHelper.php';
require_once 'classes/VehicleMarketParser.php';

/**
 * VehicleMarketController
 *
 * Kapselt alle logischen Geschäfts- und Datenoperationen des Gebrauchtwagenmarktes (PH § 1.3.1).
 */
class VehicleMarketController
{
    private PDO $pdo;
    private int $gameYear;

    /**
     * @param PDO $pdo Die aktive Datenbankverbindung
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureDatabaseSchema();
        
        // Konfiguriertes Spieljahr laden (PH § 5.2.1.1)
        $this->gameYear = (int)($this->pdo->query("SELECT cfg_value FROM config WHERE cfg_key = 'game_year'")->fetchColumn() ?: date('Y'));
    }

    /**
     * Führt bei Bedarf die Schema-Migration auf Tabellenebene durch.
     */
    private function ensureDatabaseSchema(): void
    {
        $cols = $this->pdo->query("DESCRIBE market_history")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('is_active', $cols, true)) {
            $this->pdo->exec("ALTER TABLE market_history ADD COLUMN is_active TINYINT(1) DEFAULT 1");
        }
        if (!in_array('last_seen_at', $cols, true)) {
            $this->pdo->exec("ALTER TABLE market_history ADD COLUMN last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }
    }

    /**
     * Verarbeitet den POST-Request für den Quelltext-Import.
     *
     * @param string $htmlData Rohdaten aus dem Textbereich
     * @return array Statusdaten für das UI-Feedback
     */
    public function handleImport(string $htmlData): array
    {
        $htmlData = trim($htmlData);
        if ($htmlData === '') {
            return [
                'message' => 'Bitte fügen Sie Daten für den Import ein.',
                'messageClass' => 'status-error'
            ];
        }

        $importStartTime = date('Y-m-d H:i:s');

        try {
            $parser = new VehicleMarketParser();
            $parsedVehicles = $parser->parse($htmlData);

            if (empty($parsedVehicles)) {
                return [
                    'message' => 'Keine Fahrzeuge im HTML-Quelltext gefunden. Bitte überprüfen Sie den einkopierten Inhalt.',
                    'messageClass' => 'status-error'
                ];
            }

            $this->pdo->beginTransaction();

            $inserted = 0;
            $updated = 0;

            // Prepared Statement für den transaktionssicheren Upsert
            $stmtUpsert = $this->pdo->prepare("
                INSERT INTO market_history (
                    ingame_vehicle_id, location_label, vehicle_type, capacity_t, 
                    year_built, km_stand, condition_pct, price, tuning_value_total, roi_score, is_active, last_seen_at
                ) VALUES (
                    :id, :loc, :type, :cap, :year, 
                    :km, :cond, :price, :tuning, :roi, 1, NOW()
                ) ON DUPLICATE KEY UPDATE 
                    location_label = VALUES(location_label),
                    km_stand = VALUES(km_stand),
                    condition_pct = VALUES(condition_pct),
                    price = VALUES(price),
                    tuning_value_total = VALUES(tuning_value_total),
                    roi_score = VALUES(roi_score),
                    is_active = 1,
                    last_seen_at = NOW()
            ");

            foreach ($parsedVehicles as $v) {
                // ROI-Score Berechnung auf Basis des Standard-Modells (PH § 2.6.3)
                $effectivePrice = $v['price'] - $v['tuning_value_total'];
                if ($effectivePrice < 0) {
                    $effectivePrice = 0;
                }
                
                $conditionFactor = $v['condition_pct'] / 100.0;
                $age = $this->gameYear - $v['year_built'];
                
                $ageMali = 1.0;
                if ($age >= 15) {
                    $ageMali = 0.3;
                } elseif ($age >= 8) {
                    $ageMali = 0.6;
                }
                
                $kmMali = 1.0;
                if ($v['km_stand'] >= 2000000) {
                    $kmMali = 0.5;
                } elseif ($v['km_stand'] >= 1000000) {
                    $kmMali = 0.8;
                }
                
                $finalCondition = $conditionFactor * $ageMali * $kmMali;
                $roiScore = ($finalCondition > 0 && $v['capacity_t'] > 0) ? (int)round($effectivePrice / ($v['capacity_t'] * $finalCondition)) : 999999;

                // Prüfen ob bereits vorhanden für Statistik
                $stmtCheck = $this->pdo->prepare("SELECT id FROM market_history WHERE ingame_vehicle_id = ?");
                $stmtCheck->execute([$v['ingame_vehicle_id']]);
                if ($stmtCheck->fetch()) {
                    $updated++;
                } else {
                    $inserted++;
                }

                $stmtUpsert->execute([
                    'id' => $v['ingame_vehicle_id'],
                    'loc' => $v['location_label'],
                    'type' => $v['vehicle_type'],
                    'cap' => $v['capacity_t'],
                    'year' => $v['year_built'],
                    'km' => $v['km_stand'],
                    'cond' => $v['condition_pct'],
                    'price' => $v['price'],
                    'tuning' => $v['tuning_value_total'],
                    'roi' => $roiScore
                ]);
            }

            // AUTO-ARCHIVIERUNG: Alle aktiven Angebote, die in diesem Import-Lauf NICHT gesichtet wurden, archivieren (Soll-Ist-Abgleich)
            $stmtArchive = $this->pdo->prepare("
                UPDATE market_history 
                SET is_active = 0 
                WHERE is_active = 1 
                  AND last_seen_at < :import_time
            ");
            $stmtArchive->execute(['import_time' => $importStartTime]);
            $archivedCount = $stmtArchive->rowCount();

            $this->pdo->commit();

            return [
                'message' => "Import erfolgreich! {$inserted} neu inserierte Fahrzeuge erfasst, {$updated} bestehende Angebote aktualisiert/reaktiviert. {$archivedCount} nicht mehr vorhandene Angebote archiviert.",
                'messageClass' => 'status-success'
            ];

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return [
                'message' => "Fehler beim Importieren: " . $e->getMessage(),
                'messageClass' => 'status-error'
            ];
        }
    }

    /**
     * Holt den historischen Durchschnitts-ROI pro Typ und Kapazität für das Schnäppchen-Rating.
     *
     * @return array Aggregierte Durchschnittswerte
     */
    public function getHistoricalAverages(): array
    {
        $stmt = $this->pdo->query("
            SELECT vehicle_type, capacity_t, AVG(roi_score) AS avg_roi 
            FROM market_history 
            GROUP BY vehicle_type, capacity_t
        ");
        $averages = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $averages[$row['vehicle_type']][$row['capacity_t']] = (float)$row['avg_roi'];
        }
        return $averages;
    }

    /**
     * Lädt alle registrierten Fahrzeuge aus dem ERP-Archiv.
     *
     * @return array Liste aller LKW-Einträge
     */
    public function getAllVehicles(): array
    {
        return $this->pdo->query("SELECT * FROM market_history ORDER BY is_active DESC, roi_score ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getGameYear(): int
    {
        return $this->gameYear;
    }
}

// Controller instanziieren und ausführen (MVC-Struktur)
$controller = new VehicleMarketController($pdo);

$message = '';
$messageClass = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['import_data'])) {
    $result = $controller->handleImport($_POST['import_data']);
    $message = $result['message'];
    $messageClass = $result['messageClass'];
}

$vehicles = $controller->getAllVehicles();
$historicalAverages = $controller->getHistoricalAverages();
$gameYear = $controller->getGameYear();

$activeOffersCount = 0;
$archivedOffersCount = 0;
foreach ($vehicles as $v) {
    if ((int)$v['is_active'] === 1) {
        $activeOffersCount++;
    } else {
        $archivedOffersCount++;
    }
}

// Unique Typen für den reaktiven JavaScript-Filter extrahieren
$uniqueTypes = array_unique(array_column($vehicles, 'vehicle_type'));
sort($uniqueTypes);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Gebrauchtwagenmarkt - TransportBoss</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <?php require_once 'nav.php'; ?>
    <div class="fluid-container">
        
        <!-- KPI Dashboard-Header -->
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3 class="accent-text">Aktive Angebote</h3>
                <div class="kpi-value" style="color: #2ecc71;"><?= $activeOffersCount ?></div>
                <div class="kpi-desc">Gebrauchtwagen aktuell im Spiel</div>
            </div>
            <div class="dashboard-card">
                <h3 class="accent-text">Archivierte Fahrzeuge</h3>
                <div class="kpi-value" style="color: #7f8c8d;"><?= $archivedOffersCount ?></div>
                <div class="kpi-desc">Als Referenzwert im Preisarchiv</div>
            </div>
            <div class="dashboard-card">
                <h3 class="accent-text">Beste ROI-Klasse</h3>
                <div class="kpi-value" style="color: #f39c12;">
                    <?php
                    $bestRoi = $pdo->query("SELECT MIN(roi_score) FROM market_history WHERE is_active = 1")->fetchColumn();
                    echo $bestRoi ? $bestRoi : '-';
                    ?>
                </div>
                <div class="kpi-desc">Niedrigster aktiver Score (Günstigster Wert)</div>
            </div>
        </div>

        <hr class="section-divider">

        <?php if ($message): ?>
            <div class="feedback-msg <?= $messageClass ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- NATIVES EINKLAPPBARES IMPORT-PANEL (Platzsparend & PH-konform) -->
        <details class="form-box">
            <summary class="accent-text" style="cursor: pointer; font-weight: 600;">📥 Gebrauchtwagen-Quelltext einlesen (HTML Import)</summary>
            <form method="post" action="market_vehicles.php" style="margin-top: 15px;">
                <label for="import_data">Fügen Sie hier den kompletten HTML-Quelltext des Ingame-Fahrzeughandels ein:</label>
                <textarea id="import_data" name="import_data" class="import-textarea" placeholder="HTML-Quellcode kopieren und hier einfügen..." required></textarea>
                <button type="submit" class="btn-primary">Gebrauchtwagen einlesen und ROI-Statistik berechnen</button>
            </form>
        </details>

        <!-- COCKPIT REAKTIVE FILTERLEISTE (FLEXBOX-PANEL) -->
        <div class="filter-panel">
            <div class="filter-group">
                <label for="filterType">Fahrzeugtyp:</label>
                <select id="filterType" class="inline-select" onchange="applyMarketFilters()">
                    <option value="">-- Alle Typen --</option>
                    <?php foreach ($uniqueTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="filterStatus">Markt-Status:</label>
                <select id="filterStatus" class="inline-select" onchange="applyMarketFilters()">
                    <option value="active">Nur aktive Angebote (im Spiel)</option>
                    <option value="archived">Nur Preisarchiv (bereits verkauft)</option>
                    <option value="all">Gesamte Historie (Alle anzeigen)</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="filterCondition">Zustand mindestens:</label>
                <div class="range-container" style="display: flex; align-items: center; gap: 10px;">
                    <input type="range" id="filterCondition" min="0" max="100" value="0" class="inline-select" oninput="updateConditionValue(this.value)" onchange="applyMarketFilters()">
                    <span class="range-value text-orange" id="cond-val" style="font-weight: bold;">0 %</span>
                </div>
            </div>

            <div class="filter-group filter-search-group">
                <label for="marketSearch">Multisearch (Suche):</label>
                <input type="text" id="marketSearch" class="filter-input" placeholder="Nach ID, Typ, Ort, Tuning etc. filtern..." onkeyup="applyMarketFilters()">
            </div>
        </div>

        <!-- SCHNÄPPCHEN-TABELLE -->
        <table class="data-table" id="marketTable">
            <thead>
                <tr>
                    <th onclick="sortTable('marketTable', 0, 'number')">Fahrzeug-ID ⇕</th>
                    <th onclick="sortTable('marketTable', 1, 'string')">Typ ⇕</th>
                    <th onclick="sortTable('marketTable', 2, 'number')">Nutzlast ⇕</th>
                    <th onclick="sortTable('marketTable', 3, 'number')">Baujahr ⇕</th>
                    <th onclick="sortTable('marketTable', 4, 'number')">Zustand ⇕</th>
                    <th onclick="sortTable('marketTable', 5, 'number')">Laufleistung ⇕</th>
                    <th onclick="sortTable('marketTable', 6, 'number')">Inserat-Preis ⇕</th>
                    <th onclick="sortTable('marketTable', 7, 'number')">ROI-Score ⇕</th>
                    <th onclick="sortTable('marketTable', 8, 'string')">Schnäppchen-Rating ⇕</th>
                    <th onclick="sortTable('marketTable', 9, 'string')">Standort ⇕</th>
                    <th onclick="sortTable('marketTable', 10, 'string')">Status ⇕</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vehicles)): ?>
                    <tr><td colspan="11" class="text-center text-muted-italic">Keine Gebrauchtwagen-Daten im System vorhanden. Bitte nutzen Sie das Import-Panel oben!</td></tr>
                <?php else: ?>
                    <?php foreach ($vehicles as $v): ?>
                    <?php 
                    $avgRoi = $historicalAverages[$v['vehicle_type']][$v['capacity_t']] ?? null;
                    
                    // Rating bestimmen auf Basis des historischen Schnitts (PH-konforme Klassen)
                    $ratingHtml = '<span class="text-muted-italic">Kein Benchmark</span>';
                    if ($avgRoi !== null && $v['roi_score'] > 0) {
                        $diffRatio = $v['roi_score'] / $avgRoi;
                        
                        if ($diffRatio <= 0.80) {
                            $savings = round((1 - $diffRatio) * 100);
                            $ratingHtml = '<span class="adr-badge" title="Dieses Angebot liegt ' . $savings . '% unter dem historischen Typen-Schnitt.">★ TOP DEAL (-' . $savings . '%)</span>';
                        } elseif ($diffRatio <= 0.95) {
                            $savings = round((1 - $diffRatio) * 100);
                            $ratingHtml = '<span class="text-orange" title="Dieses Angebot liegt ' . $savings . '% unter dem historischen Typen-Schnitt.">Gutes Angebot (-' . $savings . '%)</span>';
                        } else {
                            $ratingHtml = '<span class="text-muted-italic" title="Preis liegt im oder über dem historischen Durchschnitt.">Standard</span>';
                        }
                    }

                    // Alters- und Laufleistungswarnungen (Schnittstelle Wartungs-Monitor PH § 5.2.1)
                    $age = $gameYear - (int)$v['year_built'];
                    $isOveraged = ($age >= 8);
                    $isHighMileage = ((int)$v['km_stand'] >= 1000000);
                    ?>
                    <tr class="filterable-market-row" 
                        data-type="<?= htmlspecialchars($v['vehicle_type']) ?>" 
                        data-condition="<?= (int)$v['condition_pct'] ?>"
                        data-active="<?= (int)$v['is_active'] ?>">
                        <td><strong>ID: <?= htmlspecialchars($v['ingame_vehicle_id']) ?></strong></td>
                        <td><?= htmlspecialchars($v['vehicle_type']) ?></td>
                        <td><?= $v['capacity_t'] ?> t</td>
                        <td>
                            <strong><?= $v['year_built'] ?></strong>
                            <?php if ($isOveraged): ?>
                                <br><span class="badge-missing" title="Fahrzeugalter beträgt <?= $age ?> Jahre (Erhöhtes Pannenrisiko, PH 5.2)">⚠️ ÜBERALTERT</span>
                            <?php endif; ?>
                        </td>
                        <td class="<?= $v['condition_pct'] < 50 ? 'text-warning-bold' : '' ?>">
                            <strong><?= number_format((float)$v['condition_pct'], 1, ',', '.') ?> %</strong>
                        </td>
                        <td>
                            <?= number_format((float)$v['km_stand'], 0, ',', '.') ?> km
                            <?php if ($isHighMileage): ?>
                                <br><span class="badge-missing" title="Laufleistung überschreitet Grenzwert (Hoher Verschleiß, PH 5.2)">⚠️ HOHE LAUFLEISTUNG</span>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= FinanceViewHelper::renderAmount((float)$v['price']) ?></strong></td>
                        <td class="text-orange"><strong><?= $v['roi_score'] ?></strong></td>
                        <td><?= $ratingHtml ?></td>
                        <td><span class="text-blue">➔ <?= htmlspecialchars($v['location_label']) ?></span></td>
                        <td>
                            <?php if ($v['is_active']): ?>
                                <span style="color:#2ecc71; font-weight:bold;">🟢 Aktiv im Spiel</span>
                            <?php else: ?>
                                <span class="text-gray">⚪ Archiviert (Verkauft)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Client-Side Filter, Suche, Sortierung -->
    <script>
        // Live-Update des Zustand-Slider Labels
        function updateConditionValue(val) {
            document.getElementById('cond-val').innerText = val + " %";
        }

        // -------------------------------------------------------------
        // CLIENT-SIDE MULTI-FILTER (Echtzeit)
        // -------------------------------------------------------------
        function applyMarketFilters() {
            const typeFilter = document.getElementById('filterType').value.toLowerCase();
            const statusFilter = document.getElementById('filterStatus').value;
            const condFilter = parseInt(document.getElementById('filterCondition').value) || 0;
            const searchVal = document.getElementById('marketSearch').value.toLowerCase();
            const keywords = searchVal.split(/\s+/).filter(k => k.trim() !== '');

            const rows = document.querySelectorAll('#marketTable tbody tr');

            rows.forEach(row => {
                if (row.cells.length < 2) return; // Tabellenplatzhalter überspringen

                const typeAttr = row.getAttribute('data-type').toLowerCase();
                const condAttr = parseInt(row.getAttribute('data-condition')) || 0;
                const activeAttr = parseInt(row.getAttribute('data-active'));
                const textContent = row.textContent.toLowerCase();

                // Evaluierung der Dropdown- und Slider-Filter
                const matchType = (typeFilter === '' || typeAttr === typeFilter);
                const matchCondition = (condAttr >= condFilter);
                
                let matchStatus = false;
                if (statusFilter === 'all') {
                    matchStatus = true;
                } else if (statusFilter === 'active') {
                    matchStatus = (activeAttr === 1);
                } else if (statusFilter === 'archived') {
                    matchStatus = (activeAttr === 0);
                }

                // Evaluierung der Suchbegriffe (UND-Verknüpfung)
                let matchSearch = true;
                for (let kw of keywords) {
                    if (!textContent.includes(kw)) {
                        matchSearch = false;
                        break;
                    }
                }

                if (matchType && matchCondition && matchStatus && matchSearch) {
                    row.classList.remove('hidden-row');
                } else {
                    row.classList.add('hidden-row');
                }
            });
        }

        // Initiales Ausführen beim Laden der Seite
        window.addEventListener('DOMContentLoaded', () => {
            applyMarketFilters();
        });

        // -------------------------------------------------------------
        // SORTIER-ALGORITHMUS (ZENTRAL)
        // -------------------------------------------------------------
        function sortTable(tableId, columnIndex, type) {
            const table = document.getElementById(tableId);
            if (!table) return;
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            const headers = table.querySelectorAll('th');
            const th = headers[columnIndex];
            const currentDir = th.getAttribute('data-sort-dir') === 'asc';
            const dir = !currentDir;
            
            headers.forEach(h => h.removeAttribute('data-sort-dir'));
            th.setAttribute('data-sort-dir', dir ? 'asc' : 'desc');

            rows.sort((a, b) => {
                let valA = a.cells[columnIndex]?.innerText.trim() || '';
                let valB = b.cells[columnIndex]?.innerText.trim() || '';

                if (type === 'number') {
                    // Entfernt Punkte, Kommas, Leerzeichen und Währungen zur reinen Zahlensortierung
                    valA = parseFloat(valA.replace(/[^0-9,-]+/g, '').replace(',', '.'));
                    valB = parseFloat(valB.replace(/[^0-9,-]+/g, '').replace(',', '.'));
                    valA = isNaN(valA) ? 0 : valA;
                    valB = isNaN(valB) ? 0 : valB;
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