<?php
declare(strict_types=1);

/**
 * ranking_manager.php
 *
 * Das zentrale Ranglisten- und Konkurrenz-Aktivitäts-Zentrum von TransportBoss.
 * Ermöglicht das Einlesen der Ingame-Rangliste sowie die chronologische
 * Verfolgung aller Online-Aktivitäten Ihrer Konkurrenten inklusive Vortags-Deltas.
 *
 * @author TransportBoss Development
 * @version 1.4.0
 */

require_once 'db_connect.php';
require_once 'classes/FinanceMapper.php';
require_once 'classes/FinanceViewHelper.php';
require_once 'classes/RankingParser.php';

use classes\RankingParser;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$message = $_SESSION['pb_rank_message'] ?? '';
$messageClass = $_SESSION['pb_rank_message_class'] ?? '';
unset($_SESSION['pb_rank_message'], $_SESSION['pb_rank_message_class']);

$view = $_GET['view'] ?? 'list';
if (isset($_GET['history_profile_id'])) {
    $view = 'history';
}

// Die 9 offiziellen Firmenstufen laut Pflichtenheft
$tierLabels = [
    1 => 'Einzelunternehmer',
    2 => 'OHG',
    3 => 'GmbH (25.000 € Kapital)',
    4 => 'GmbH (75.000 € Kapital)',
    5 => 'GmbH & Co. LG (150.000 € Kapital)',
    6 => 'AG',
    7 => 'SE',
    8 => 'Holding',
    9 => 'Konzern'
];

/**
 * Hilfsfunktion zum Rendern der Trend-Deltas nach PH § 1.3.2 (Keine Inline-Styles!)
 *
 * @param float|int $delta Die berechnete Differenz
 * @param string $unit Das Suffix (z.B. " €")
 * @return string Formatiertes HTML-Fragment
 */
function renderDelta(float|int $delta, string $unit = ''): string {
    if ($delta === 0 || $delta === 0.0) {
        return ' <small class="text-gray">±0' . $unit . '</small>';
    }
    
    // Grüne oder rote Formatierung über bestehende main.css Klassen
    $class = $delta > 0 ? 'price-good' : 'price-very-bad';
    $sign = $delta > 0 ? '+' : '';
    
    // Tausendertrenner formatisieren falls Währung
    $formattedVal = is_float($delta) ? number_format($delta, 0, ',', '.') : (string)$delta;
    
    return ' <small class="' . $class . '">(' . $sign . $formattedVal . $unit . ')</small>';
}

// -------------------------------------------------------------
// POST-AKTIONEN VERARBEITEN (TRANSAKTIONSSICHER)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];
        $redirectUrl = 'ranking_manager.php';

        if ($action === 'import_rankings' && !empty($_POST['import_data'])) {
            $htmlData = $_POST['import_data'];
            $parser = new RankingParser();
            $parsedRankings = $parser->parse($htmlData);

            $syncedCount = 0;
            $onlineLoggedCount = 0;

            if (!empty($parsedRankings)) {
                $pdo->beginTransaction();

                // Vorbereitung des Haupt-Upserts
                $stmtUpsert = $pdo->prepare("
                    INSERT INTO rankings (
                        profile_id, player_name, rank_val, truck_count, company_value, company_tier, hq_city_name, is_online, last_seen_online
                    ) VALUES (
                        :profile_id, :name, :rank, :trucks, :value, :tier, :hq, :online, :seen
                    ) ON DUPLICATE KEY UPDATE
                        player_name = VALUES(player_name),
                        rank_val = VALUES(rank_val),
                        truck_count = VALUES(truck_count),
                        company_value = VALUES(company_value),
                        company_tier = VALUES(company_tier),
                        hq_city_name = VALUES(hq_city_name),
                        is_online = VALUES(is_online),
                        last_seen_online = COALESCE(VALUES(last_seen_online), last_seen_online)
                ");

                // Vorbereitung des täglichen Snapshots (ON DUPLICATE KEY UPDATE überschreibt bei Mehrfachimports am selben Tag)
                $stmtSnapshot = $pdo->prepare("
                    INSERT INTO ranking_snapshots (profile_id, rank_val, truck_count, company_value, company_tier, snapshot_date)
                    VALUES (:profile_id, :rank, :trucks, :value, :tier, CURRENT_DATE())
                    ON DUPLICATE KEY UPDATE
                        rank_val = VALUES(rank_val),
                        truck_count = VALUES(truck_count),
                        company_value = VALUES(company_value),
                        company_tier = VALUES(company_tier)
                ");

                // Vorbereitung des Online-Protokolls
                $stmtHistory = $pdo->prepare("
                    INSERT IGNORE INTO player_online_history (profile_id, online_at) 
                    VALUES (?, NOW())
                ");

                foreach ($parsedRankings as $item) {
                    $now = date('Y-m-d H:i:s');
                    $seenTimestamp = $item['is_online'] ? $now : null;

                    // 1. Stammdaten-Upsert
                    $stmtUpsert->execute([
                        'profile_id' => $item['profile_id'],
                        'name' => $item['player_name'],
                        'rank' => $item['rank_val'],
                        'trucks' => $item['truck_count'],
                        'value' => $item['company_value'],
                        'tier' => $item['company_tier'],
                        'hq' => $item['hq_city_name'],
                        'online' => $item['is_online'],
                        'seen' => $seenTimestamp
                    ]);

                    // 2. Täglichen Snapshot schreiben
                    $stmtSnapshot->execute([
                        'profile_id' => $item['profile_id'],
                        'rank' => $item['rank_val'],
                        'trucks' => $item['truck_count'],
                        'value' => $item['company_value'],
                        'tier' => $item['company_tier']
                    ]);

                    // 3. Online-Protokoll schreiben
                    if ($item['is_online'] === 1) {
                        $stmtHistory->execute([$item['profile_id']]);
                        $onlineLoggedCount++;
                    }

                    $syncedCount++;
                }

                $pdo->commit();
            }

            $_SESSION['pb_rank_message'] = "Import beendet! " . $syncedCount . " Speditionen aktualisiert. " . $onlineLoggedCount . " Online-Sitzungen erfolgreich protokolliert.";
            $_SESSION['pb_rank_message_class'] = "status-success";
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['pb_rank_message'] = "Fehler bei der Transaktion: " . $e->getMessage();
        $_SESSION['pb_rank_message_class'] = "status-error";
    }

    header("Location: ranking_manager.php");
    exit;
}

// -------------------------------------------------------------
// BASISDATEN LADEN & DELTAS ERRECHNEN
// -------------------------------------------------------------
// Lade alle registrierten Rankings (sortiert nach Platzierung)
$rankings = $pdo->query("SELECT * FROM rankings ORDER BY rank_val ASC")->fetchAll(PDO::FETCH_ASSOC);

// Ermittle den zeitlich jüngsten Snapshot-Tag, der vor dem heutigen Kalendertag liegt (Vergleichs-Tag)
$lastSnapshotDate = $pdo->query("
    SELECT MAX(snapshot_date) 
    FROM ranking_snapshots 
    WHERE snapshot_date < CURRENT_DATE()
")->fetchColumn();

// Vergleichs-Map im Arbeitsspeicher aufbauen
$prevSnapshots = [];
if ($lastSnapshotDate !== false && $lastSnapshotDate !== null) {
    $stmtPrev = $pdo->prepare("
        SELECT profile_id, rank_val, truck_count, company_value, company_tier
        FROM ranking_snapshots
        WHERE snapshot_date = ?
    ");
    $stmtPrev->execute([$lastSnapshotDate]);
    while ($row = $stmtPrev->fetch(PDO::FETCH_ASSOC)) {
        $prevSnapshots[(int)$row['profile_id']] = $row;
    }
}

// Falls die Aktivitäts-Historie geladen werden soll
$historyLogs = [];
$targetPlayer = null;
if ($view === 'history') {
    $profileId = (int)$_GET['history_profile_id'];
    
    $stmtPlayer = $pdo->prepare("SELECT player_name FROM rankings WHERE profile_id = ?");
    $stmtPlayer->execute([$profileId]);
    $targetPlayer = $stmtPlayer->fetchColumn() ?: 'Unbekannter Spieler';

    $stmtLog = $pdo->prepare("
        SELECT online_at 
        FROM player_online_history 
        WHERE profile_id = ? 
        ORDER BY online_at DESC
    ");
    $stmtLog->execute([$profileId]);
    $historyLogs = $stmtLog->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Rangliste &amp; Aktivität - TransportBoss</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <?php require_once 'nav.php'; ?>
    <div class="fluid-container">

        <!-- ==========================================================================
             ANSICHT 1: DIE AKTIVE RANGLISTE (list)
             ========================================================================== -->
        <?php if ($view === 'list'): ?>
            <div class="workspace-header-row">
                <h1 class="accent-text">Globale Spieler-Rangliste</h1>
                <div class="action-form">
                    <a href="?view=import" class="btn-primary">📥 Rangliste einlesen (Import)</a>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="feedback-msg <?= $messageClass ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <!-- Interaktive Multifilter-Leiste -->
            <div class="filter-panel">
                <div class="filter-group">
                    <label>Firmenstufe:</label>
                    <select id="filterTier" class="inline-select" onchange="applyRankingFilters()">
                        <option value="">-- Alle Stufen --</option>
                        <?php foreach ($tierLabels as $num => $label): ?>
                            <option value="<?= htmlspecialchars($label) ?>">Stufe <?= $num ?> (<?= htmlspecialchars($label) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Aktivität:</label>
                    <select id="filterActivity" class="inline-select" onchange="applyRankingFilters()">
                        <option value="">-- Alle --</option>
                        <option value="online">Online</option>
                        <option value="offline">Offline</option>
                        <option value="anytime">Jemals online gesichtet</option> <!-- Die neue Chef-Erweiterung -->
                    </select>
                </div>
                <div class="filter-group filter-search-group">
                    <label>Rangliste durchsuchen:</label>
                    <input type="text" id="rankingSearch" class="filter-input" placeholder="Nach Platz, Spieler, Zentrale etc. suchen..." onkeyup="applyRankingFilters()">
                </div>
            </div>

            <!-- Haupt-Ranglistentabelle -->
            <table class="data-table" id="rankingsTable">
                <thead>
                    <tr>
                        <th onclick="sortTable('rankingsTable', 0, 'number')">Platz ⇕</th>
                        <th onclick="sortTable('rankingsTable', 1, 'string')">Spieler / Spedition ⇕</th>
                        <th onclick="sortTable('rankingsTable', 2, 'number')">LKW ⇕</th>
                        <th onclick="sortTable('rankingsTable', 3, 'number')">Firmenwert ⇕</th>
                        <th onclick="sortTable('rankingsTable', 4, 'string')">Rechtsform (Stufe) ⇕</th>
                        <th onclick="sortTable('rankingsTable', 5, 'string')">Firmensitz ⇕</th>
                        <th onclick="sortTable('rankingsTable', 6, 'string')">Status ⇕</th>
                        <th>Aktivität</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rankings)): ?>
                        <tr><td colspan="8" class="text-center text-muted-italic">Noch keine Ranglistendaten im ERP vorhanden. Importieren Sie den Quelltext über die Schaltfläche oben rechts!</td></tr>
                    <?php else: ?>
                        <?php foreach ($rankings as $row): ?>
                        <?php 
                        $tierName = $tierLabels[$row['company_tier']] ?? 'Unbekannt'; 
                        $onlineStatus = $row['is_online'] ? 'online' : 'offline';
                        
                        // --- MATHEMATISCHE DELTA-BERECHNUNGEN ---
                        $profId = (int)$row['profile_id'];
                        $hasPrev = isset($prevSnapshots[$profId]);
                        
                        $rankDeltaHtml = '';
                        $truckDeltaHtml = '';
                        $valueDeltaHtml = '';
                        $tierDeltaHtml = '';
                        
                        if ($hasPrev) {
                            $prev = $prevSnapshots[$profId];
                            
                            // Platzierungs-Delta: Niedrigerer Rang ist besser (z.B. von 160 auf 159 ist +1 Aufstieg)
                            $rankDelta = (int)$prev['rank_val'] - (int)$row['rank_val'];
                            $rankDeltaHtml = renderDelta($rankDelta);
                            
                            // LKW-Delta
                            $truckDelta = (int)$row['truck_count'] - (int)$prev['truck_count'];
                            $truckDeltaHtml = renderDelta($truckDelta);
                            
                            // Firmenwert-Delta
                            $valueDelta = (float)$row['company_value'] - (float)$prev['company_value'];
                            $valueDeltaHtml = renderDelta($valueDelta, ' €');
                            
                            // Firmenstufen-Delta
                            $tierDelta = (int)$row['company_tier'] - (int)$prev['company_tier'];
                            $tierDeltaHtml = renderDelta($tierDelta);
                        } else {
                            // Kein historischer Snapshot vorhanden -> alle Tendenzen auf ±0 setzen
                            $rankDeltaHtml = renderDelta(0);
                            $truckDeltaHtml = renderDelta(0);
                            $valueDeltaHtml = renderDelta(0.0, ' €');
                            $tierDeltaHtml = renderDelta(0);
                        }
                        ?>
                        <tr class="filterable-ranking-row" 
                            data-tier="<?= htmlspecialchars($tierName) ?>" 
                            data-activity="<?= $onlineStatus ?>"
                            data-seen-online="<?= $row['last_seen_online'] ? 'anytime' : 'never' ?>">
                            <td><strong><?= $row['rank_val'] ?>.</strong><?= $rankDeltaHtml ?></td>
                            <td>
                                <strong><?= htmlspecialchars($row['player_name']) ?></strong><br>
                                <small class="text-gray">Ingame ID: <?= $row['profile_id'] ?></small>
                            </td>
                            <td><?= $row['truck_count'] ?> LKW<?= $truckDeltaHtml ?></td>
                            <!-- Formatierte Ausgabe des Firmenwerts über die Geld-Logik (PH 3.1.3) -->
                            <td><strong><?= FinanceViewHelper::renderAmount((float)$row['company_value']) ?></strong><?= $valueDeltaHtml ?></td>
                            <td><span class="text-orange">Stufe <?= $row['company_tier'] ?> (<?= htmlspecialchars($tierName) ?>)</span><?= $tierDeltaHtml ?></td>
                            <td><span class="text-blue">➔ <?= htmlspecialchars($row['hq_city_name']) ?></span></td>
                            <td>
                                <?php if ($row['is_online']): ?>
                                    <span style="color:#2ecc71; font-weight:bold;">🟢 Online</span>
                                <?php else: ?>
                                    <span class="text-gray">⚪ Offline</span><br>
                                    <?php if ($row['last_seen_online']): ?>
                                        <small class="text-gray">Gesehen: <?= date('d.m. H:i', strtotime($row['last_seen_online'])) ?></small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="?history_profile_id=<?= $row['profile_id'] ?>" class="btn-primary btn-small">Aktivitäts-Log</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

        <!-- ==========================================================================
             ANSICHT 2: QUELLTEXT EINLESEN (import)
             ========================================================================== -->
        <?php elseif ($view === 'import'): ?>
            <div class="workspace-header-row">
                <h1 class="accent-text">Ranglisten-Quelltext einlesen</h1>
                <a href="ranking_manager.php" class="btn-primary">⬅️ Zurück zur Rangliste</a>
            </div>

            <div class="form-box">
                <form method="post" action="ranking_manager.php">
                    <input type="hidden" name="action" value="import_rankings">
                    <label for="import_data">Fügen Sie hier den kompletten HTML-Quellcode der Ingame-Rangliste ein:</label>
                    <textarea id="import_data" name="import_data" class="import-textarea" placeholder="HTML hier hineinkopieren..." required></textarea>
                    <button type="submit" class="btn-primary">Einlesen und Aktivitäts-Protokoll schreiben</button>
                </form>
            </div>

        <!-- ==========================================================================
             ANSICHT 3: AKTIVITÄTS-LOG HISTORIE (history)
             ========================================================================== -->
        <?php elseif ($view === 'history'): ?>
            <div class="workspace-header-row">
                <h1 class="accent-text">Aktivitäts-Log: <?= htmlspecialchars($targetPlayer) ?></h1>
                <a href="ranking_manager.php" class="btn-primary">⬅️ Zurück zur Rangliste</a>
            </div>

            <!-- KPI-Box für Aktivitätsfrequenz -->
            <div class="dashboard-grid">
                <div class="dashboard-card">
                    <h3 class="accent-text">Aktivitäts-Index</h3>
                    <div class="kpi-value"><?= count($historyLogs) ?></div>
                    <div class="kpi-desc">Registrierte Online-Sitzungen im ERP</div>
                </div>
            </div>

            <h3 class="accent-text text-blue">Chronologischer Verlauf (Zuletzt online zuerst)</h3>
            <table class="data-table" id="historyTable">
                <thead>
                    <tr>
                        <th onclick="sortTable('historyTable', 0, 'string')">Registrierter Online-Zeitstempel (Datum / Uhrzeit) ⇕</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($historyLogs)): ?>
                        <tr><td class="text-center text-muted-italic">Für diesen Spieler wurden bisher noch keine Online-Zeiten aufgezeichnet. Importieren Sie neue Ranglisten oben rechts!</td></tr>
                    <?php else: ?>
                        <?php foreach ($historyLogs as $log): ?>
                        <tr>
                            <td>
                                <strong>🟢 <?= date('d.m.Y \u\m H:i:s \U\h\r', strtotime($log['online_at'])) ?></strong>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </div>

    <!-- Client-Side Multi-Filter und Tabellensortierung (Zentral und rein CSS-basiert) -->
    <script>
        /**
         * Speichert den aktuellen Zustand der Ranglisten-Filter im localStorage des Browsers.
         */
        function saveRankingFilterState() {
            const searchInput = document.getElementById('rankingSearch');
            const tierSelect = document.getElementById('filterTier');
            const activitySelect = document.getElementById('filterActivity');

            if (searchInput && tierSelect && activitySelect) {
                const state = {
                    search: searchInput.value,
                    tier: tierSelect.value,
                    activity: activitySelect.value
                };
                localStorage.setItem('tb_ranking_filters', JSON.stringify(state));
            }
        }

        /**
         * Stellt den Filterzustand aus dem localStorage wieder her.
         */
        function restoreRankingFilterState() {
            const raw = localStorage.getItem('tb_ranking_filters');
            if (!raw) return;
            try {
                const state = JSON.parse(raw);
                const searchInput = document.getElementById('rankingSearch');
                const tierSelect = document.getElementById('filterTier');
                const activitySelect = document.getElementById('filterActivity');

                if (searchInput) searchInput.value = state.search || '';
                if (tierSelect) tierSelect.value = state.tier || '';
                if (activitySelect) activitySelect.value = state.activity || '';
            } catch (e) {
                // Fehler geräuschlos abfangen
            }
        }

        // -------------------------------------------------------------
        // CLIENT-SIDE MULTI-FILTER (Echtzeit)
        // -------------------------------------------------------------
        function applyRankingFilters() {
            const query = document.getElementById('rankingSearch').value.toLowerCase();
            const keywords = query.split(/\s+/).filter(k => k.trim() !== '');

            const tierVal = document.getElementById('filterTier').value.toLowerCase();
            const activityVal = document.getElementById('filterActivity').value.toLowerCase();

            const rows = document.querySelectorAll('#rankingsTable tbody tr');

            rows.forEach(row => {
                if (row.cells.length < 2) return; // Tabellenplatzhalter überspringen

                const tierAttr = row.getAttribute('data-tier').toLowerCase();
                const activityAttr = row.getAttribute('data-activity').toLowerCase();
                const seenAttr = row.getAttribute('data-seen-online').toLowerCase();
                const fullText = row.textContent.toLowerCase();

                // Evaluierung der Dropdown-Filter (inklusive der neuen "Jemals Online" Option)
                const matchTier = (tierVal === '' || tierAttr === tierVal);
                
                let matchActivity = false;
                if (activityVal === '') {
                    matchActivity = true;
                } else if (activityVal === 'online' || activityVal === 'offline') {
                    matchActivity = (activityAttr === activityVal);
                } else if (activityVal === 'anytime') {
                    matchActivity = (seenAttr === 'anytime');
                }

                // Evaluierung der Multisearch-Suchbegriffe (UND-Verknüpfung)
                let matchSearch = true;
                for (let kw of keywords) {
                    if (!fullText.includes(kw)) {
                        matchSearch = false;
                        break;
                    }
                }

                if (matchTier && matchActivity && matchSearch) {
                    row.classList.remove('hidden-row');
                } else {
                    row.classList.add('hidden-row');
                }
            });

            // Filterzustand persistent im localStorage sichern
            saveRankingFilterState();
        }

        // Automatische Wiederherstellung beim Laden der Seite (Echtzeit-Injektion)
        window.addEventListener('DOMContentLoaded', () => {
            const table = document.getElementById('rankingsTable');
            if (table) {
                restoreRankingFilterState();
                applyRankingFilters();
            }
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