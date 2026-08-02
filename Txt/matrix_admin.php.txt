<?php
declare(strict_types=1);

/**
 * matrix_admin.php
 *
 * Verwaltungs-Center für die Entfernungs-Matrix (PH § 3.2.2.3 & 3.2.3.4).
 * Ermöglicht die stadtbezogene Inspektion aller Distanzen, die Identifikation
 * fehlender Verbindungen, die direkte Manuelle Pflege sowie den Schnell-Import
 * von Anfahrts-Distanzen aus der Ingame-Auftragsvergabe.
 *
 * @author TransportBoss Development
 * @version 1.1.0
 */

require_once 'db_connect.php';
require_once 'classes/CityService.php';
require_once 'classes/DistanceService.php';

$distanceService = new DistanceService($pdo);
$cityService = new CityService($pdo);

$message = '';
$messageClass = '';

// POST-Verarbeitung 1: Einzelne Entfernung manuell speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_distance') {
    try {
        $cityA = (int)$_POST['city_a_id'];
        $cityB = (int)$_POST['city_b_id'];
        $km = (int)$_POST['distance_km'];

        if ($cityA > 0 && $cityB > 0 && $cityA !== $cityB && $km >= 0) {
            $distanceService->setDistance($cityA, $cityB, $km);
            $message = "Entfernung erfolgreich in der Matrix gespeichert.";
            $messageClass = "status-success";
        } else {
            throw new Exception("Ungültige Eingabedaten für die Entfernung.");
        }
    } catch (Exception $e) {
        $message = "Fehler beim Speichern: " . $e->getMessage();
        $messageClass = "status-error";
    }
}

// POST-Verarbeitung 2: Anfahrts-Matrix aus Ingame-Auftragsvergabe parsen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_anfahrt_matrix') {
    try {
        $baseCityId = (int)$_POST['base_city_id'];
        $rawText = trim($_POST['anfahrt_data'] ?? '');

        if ($baseCityId > 0 && $rawText !== '') {
            $lines = explode("\n", $rawText);
            $parsedCount = 0;

            foreach ($lines as $line) {
                $row = trim($line);
                if ($row === '') continue;

                // Anfahrts-Kilometer oder VOR ORT ermitteln
                $distKm = null;
                if (str_contains($row, 'VOR ORT')) {
                    $distKm = 0;
                } elseif (preg_match('/Anfahrt:\s*(\d+)\s*km/i', $row, $matches)) {
                    $distKm = (int)$matches[1];
                }

                if ($distKm !== null) {
                    // Startstadt extrahieren (Text vor "Anfahrt:" bzw. "VOR ORT")
                    if (preg_match('/(?:Deutschland|Österreich|Schweiz|Frankreich|Niederlande|Belgien|Luxemburg|Dänemark|Polen|Tschechien|Ungarn|Ukraine)\s+([A-ZÄÖÜa-zäöüß\s\.-]+?)\s+(?:Anfahrt:|VOR ORT)/u', $row, $cityMatches)) {
                        $fromCityName = trim($cityMatches[1]);
                        $fromCityId = $cityService->resolveId($fromCityName, true);

                        if ($fromCityId && $fromCityId !== $baseCityId) {
                            $distanceService->setDistance($baseCityId, $fromCityId, $distKm);
                            $parsedCount++;
                        }
                    }
                }
            }

            $message = "Anfahrts-Matrix erfolgreich verarbeitet! {$parsedCount} Distanzen ab der Basis-Stadt in der Matrix gespeichert.";
            $messageClass = "status-success";
            $_GET['city_id'] = $baseCityId; // Automatisch auf die Basis-Stadt fokussieren
        }
    } catch (Exception $e) {
        $message = "Fehler beim Einlesen der Anfahrts-Matrix: " . $e->getMessage();
        $messageClass = "status-error";
    }
}

// Alle registrierten Städte für das Auswahl-Dropdown laden
$allCities = $pdo->query("SELECT id, name, country_code FROM cities ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$selectedCityId = isset($_GET['city_id']) ? (int)$_GET['city_id'] : 0;
$selectedCity = null;
$matrixRows = [];

if ($selectedCityId > 0) {
    foreach ($allCities as $c) {
        if ((int)$c['id'] === $selectedCityId) {
            $selectedCity = $c;
            break;
        }
    }

    if ($selectedCity) {
        // Alle anderen Städte laden und vorhandene/fehlende Distanzen ermitteln
        foreach ($allCities as $otherCity) {
            $otherId = (int)$otherCity['id'];
            if ($otherId === $selectedCityId) {
                continue;
            }

            $hasDist = $distanceService->hasDistance($selectedCityId, $otherId);
            $dist = $distanceService->getDistance($selectedCityId, $otherId);

            $matrixRows[] = [
                'other_id' => $otherId,
                'other_name' => $otherCity['name'],
                'other_country' => $otherCity['country_code'] ?? 'DE',
                'distance_km' => $dist,
                'has_distance' => $hasDist
            ];
        }

        // Sortierung: Vorhandene Entfernungen aufsteigend nach km, fehlende am Ende
        usort($matrixRows, function ($a, $b) {
            if ($a['has_distance'] !== $b['has_distance']) {
                return $b['has_distance'] <=> $a['has_distance'];
            }
            if ($a['has_distance'] && $b['has_distance']) {
                return $a['distance_km'] <=> $b['distance_km'];
            }
            return strcmp($a['other_name'], $b['other_name']);
        });
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Matrix Verwaltung - TransportBoss</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <?php require_once 'nav.php'; ?>
    <div class="fluid-container">
        <h1 class="accent-text">Entfernungs-Matrix Verwaltung</h1>

        <?php if ($message): ?>
            <div class="feedback-msg <?= $messageClass ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- SCHNELL-IMPORT: ANFAHRTS-MATRIX AUS AUFTRAGSVERGABE -->
        <details class="form-box" style="margin-bottom: 20px;">
            <summary class="accent-text" style="cursor: pointer; font-weight: 600;">📥 Anfahrts-Matrix aus Ingame-Auftragsvergabe einlesen (Schnell-Import)</summary>
            <form method="post" action="matrix_admin.php?city_id=<?= $selectedCityId ?>" style="margin-top: 15px;">
                <input type="hidden" name="action" value="import_anfahrt_matrix">
                <div class="input-group">
                    <label for="base_city_id">Aktueller LKW-Standort aus dem Spiel (Basis-Stadt):</label>
                    <select id="base_city_id" name="base_city_id" class="inline-select w-100" required>
                        <option value="">-- Basis-Stadt wählen --</option>
                        <?php foreach ($allCities as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $c['id'] === $selectedCityId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?> (Land: <?= htmlspecialchars($c['country_code'] ?? 'DE') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <label for="anfahrt_data">Kopierten Text aus der Ingame-Auftragsvergabe hier einfügen:</label>
                    <textarea id="anfahrt_data" name="anfahrt_data" class="import-textarea" placeholder="Auftragsvergabe-Liste einkopieren..." required></textarea>
                </div>
                <button type="submit" class="btn-primary">Anfahrts-Distanzen parsen &amp; in Matrix speichern</button>
            </form>
        </details>

        <!-- STADTAUSWAHL-PANEL -->
        <div class="filter-panel">
            <div class="filter-group filter-search-group">
                <label for="city_id">Stadt zur Matrix-Inspektion auswählen:</label>
                <form method="get" action="matrix_admin.php">
                    <select id="city_id" name="city_id" class="inline-select w-100" onchange="this.form.submit()">
                        <option value="">-- Bitte Stadt auswählen --</option>
                        <?php foreach ($allCities as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $c['id'] === $selectedCityId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?> (Land: <?= htmlspecialchars($c['country_code'] ?? 'DE') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        </div>

        <?php if ($selectedCity): ?>
            <h2 class="accent-text">
                Matrix-Verbindungen für: <?= htmlspecialchars($selectedCity['name']) ?> (<?= htmlspecialchars($selectedCity['country_code'] ?? 'DE') ?>)
            </h2>

            <!-- TABELLE DER VERBINDUNGEN -->
            <table class="data-table" id="matrixTable">
                <thead>
                    <tr>
                        <th onclick="sortTable('matrixTable', 0, 'string')">Zielstadt / Partner ⇕</th>
                        <th onclick="sortTable('matrixTable', 1, 'string')">Land ⇕</th>
                        <th onclick="sortTable('matrixTable', 2, 'number')">Entfernung (km) ⇕</th>
                        <th>Status</th>
                        <th>Entfernung einpflegen / korrigieren</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($matrixRows)): ?>
                        <tr><td colspan="5" class="text-center text-muted-italic">Keine weiteren Städte im System registriert.</td></tr>
                    <?php else: ?>
                        <?php foreach ($matrixRows as $row): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['other_name']) ?></strong></td>
                            <td><span class="text-blue"><?= htmlspecialchars($row['other_country']) ?></span></td>
                            <td>
                                <?php if ($row['has_distance']): ?>
                                    <strong class="text-orange"><?= $row['distance_km'] ?> km</strong>
                                <?php else: ?>
                                    <span class="badge-missing">FEHLT</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['has_distance']): ?>
                                    <span style="color:#2ecc71; font-weight:bold;">🟢 Vorhanden</span>
                                <?php else: ?>
                                    <span class="badge-missing">⚠️ Nicht hinterlegt</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="matrix_admin.php?city_id=<?= $selectedCityId ?>" class="action-form">
                                    <input type="hidden" name="action" value="save_distance">
                                    <input type="hidden" name="city_a_id" value="<?= $selectedCityId ?>">
                                    <input type="hidden" name="city_b_id" value="<?= $row['other_id'] ?>">
                                    <input type="number" name="distance_km" min="0" value="<?= $row['has_distance'] ? $row['distance_km'] : '' ?>" placeholder="KM" class="inline-select" style="width: 110px;" required>
                                    <button type="submit" class="btn-primary btn-small">Speichern</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="workspace-placeholder" style="padding: 40px; text-align: center;">
                <span class="text-muted-italic placeholder-text">Bitte wählen Sie oben eine Stadt aus, um deren Verbindungen zu prüfen und fehlende Entfernungen nachzupflegen.</span>
            </div>
        <?php endif; ?>
    </div>

    <!-- Client-Side Sortier-Logik -->
    <script>
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
                    valA = parseFloat(valA.replace(/[^0-9,-]+/g, '').replace(',', '.'));
                    valB = parseFloat(valB.replace(/[^0-9,-]+/g, '').replace(',', '.'));
                    valA = isNaN(valA) ? 999999 : valA;
                    valB = isNaN(valB) ? 999999 : valB;
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