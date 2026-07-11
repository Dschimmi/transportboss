<?php
declare(strict_types=1);

/**
 * market_warehouse.php
 *
 * Import-Schnittstelle für das eigene Lager (angenommene Aufträge).
 * Nimmt den einzeiligen Kopiertext (TSV) entgegen, parst die Daten und
 * verheiratet diese über die Ingame-ID (IDN) mit bestehenden Börseneinträgen
 * oder legt sie bei Bedarf autonom neu an.
 *
 * @author TransportBoss Development
 * @version 1.1.5
 */

// Zentrale Abhängigkeiten laden
require_once 'db_connect.php';
require_once 'classes/CityService.php';
require_once 'classes/WarehouseParser.php';

use classes\WarehouseParser;

/**
 * WarehouseSynchronizer
 *
 * Kapselt die Abgleichs- und Speicheroperationen zur Überführung von importierten
 * Lagerdaten in die SQL-Datenbankstrukturen.
 */
class WarehouseSynchronizer
{
    private PDO $pdo;

    /**
     * @param PDO $pdo Die aktive Datenbankverbindung
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Synchronisiert einen parsten Lagerauftrag mit der Datenbank.
     * Führt eine "Heirat" mit einem passenden Börsenauftrag durch, aktualisiert bestehende oder legt diese neu an.
     *
     * @param array $order Der parste Auftragsdatensatz
     * @return int 1 = frisch verheiratet, 2 = bereits existent (aktualisiert), 3 = autonom neu angelegt
     */
    /**
     * Synchronisiert einen parsten Lagerauftrag mit der Datenbank.
     * Führt eine "Heirat" mit einem aktiven oder archivierten Börsenauftrag durch.
     *
     * @param array $order Der parste Auftragsdatensatz
     * @return int 1 = frisch verheiratet/reaktiviert, 2 = bereits existent, 3 = autonom neu angelegt
     */
    public function syncOrder(array $order): int
    {
        // 1. DUBLETTEN-SCHUTZ: Prüfen, ob die IDN bereits im System existiert (PH 2.5.1.3 & 3.4.1)
        $stmtCheckIDN = $this->pdo->prepare("SELECT id FROM orders WHERE ingame_order_id = ? LIMIT 1");
        $stmtCheckIDN->execute([$order['ingame_order_id']]);
        $existingWarehouseId = $stmtCheckIDN->fetchColumn();

        if ($existingWarehouseId !== false) {
            // Fall 1: IDN existiert bereits -> Nur das verbleibende Restgewicht, Zeitstempel und Aktiv-Status aktualisieren (heilt Altdaten!)
            $stmtUpdateWarehouse = $this->pdo->prepare("
                UPDATE orders 
                SET weight_remaining = ?, 
                    last_seen_at = NOW(),
                    is_archived = 0
                WHERE id = ?
            ");
            $stmtUpdateWarehouse->execute([$order['weight_remaining'], (int)$existingWarehouseId]);
            return 2; // Code 2: Bereits erfasst, restliches Gewicht aktualisiert
        }

        // 2. IDN existiert noch nicht. Versuche einen passenden Börsenauftrag zu heiraten.
        // KORREKTUR: Wir suchen in aktiven (is_archived = 0) UND archivierten (is_archived = 1) Aufträgen.
        // Sortiert aktive nach oben und wählt das jüngste passende Pendant aus.
        // KORREKTUR: Wir erlauben auch den Abgleich bereits zugewiesener (geladener) Börsenaufträge,
        // indem wir die Einschränkung 'assigned_truck_id IS NULL' aufheben.
        // Bereits zugewiesene Aufträge werden bei der Sortierung bevorzugt, damit die LKW-Tour aktualisiert wird.
        // Wir nutzen eine Delta-Prüfung ABS(revenue - :revenue) < 0.01, um Float-Präzisionsfehler bei DECIMAL-Spalten zu verhindern.
        $stmtSearch = $this->pdo->prepare("
            SELECT id, is_archived FROM orders 
            WHERE is_accepted = 0 
              AND from_city_id = :from_city
              AND to_city_id = :to_city
              AND weight_total = :weight_total
              AND ABS(revenue - :revenue) < 0.01
            ORDER BY assigned_truck_id DESC, is_archived ASC, id DESC
            LIMIT 1
        ");

        $stmtSearch->execute([
            'from_city' => $order['from_city_id'],
            'to_city' => $order['to_city_id'],
            'weight_total' => $order['weight_total'],
            'revenue' => $order['revenue']
        ]);

        $matched = $stmtSearch->fetch(PDO::FETCH_ASSOC);

        if ($matched !== false) {
            // Fall 2: Match gefunden -> Börsenauftrag verheiraten und aktivieren (auch falls er archiviert war!)
            $stmtUpdate = $this->pdo->prepare("
                UPDATE orders 
                SET ingame_order_id = :idn,
                    is_accepted = 1,
                    is_archived = 0, -- Setzt den archivierten Status im Bedarfsfall zurück
                    weight_remaining = :weight_remaining,
                    last_seen_at = NOW()
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                'idn' => $order['ingame_order_id'],
                'weight_remaining' => $order['weight_remaining'],
                'id' => (int)$matched['id']
            ]);
            return 1; // Code 1: Frisch verheiratet / Reaktiviert
        }

        // Fall 3: Weder IDN noch passender Börsenauftrag existieren -> Autonome Neuanlage
        $stmtInsert = $this->pdo->prepare("
            INSERT INTO orders (ingame_order_id, freight_type, commodity, is_adr, weight_total, weight_remaining, revenue, from_city_id, to_city_id, is_accepted, is_archived, last_seen_at)
            VALUES (:idn, :freight_type, :commodity, :is_adr, :weight_total, :weight_remaining, :revenue, :from_city, :to_city, 1, 0, NOW())
        ");
        $stmtInsert->execute([
            'idn' => $order['ingame_order_id'],
            'freight_type' => $order['freight_type'],
            'commodity' => $order['commodity'],
            'is_adr' => $order['is_adr'],
            'weight_total' => $order['weight_total'],
            'weight_remaining' => $order['weight_remaining'],
            'revenue' => $order['revenue'],
            'from_city' => $order['from_city_id'],
            'to_city' => $order['to_city_id']
        ]);
        return 3; // Code 3: Autonom neu angelegt
    }
}

// Variablen für die Benutzerführung
$message = '';
$messageClass = '';
$parsedOrders = [];

// Formularverarbeitung
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['import_data'])) {
    $rawData = $_POST['import_data'];
    $importStartTime = date('Y-m-d H:i:s'); // Startzeitpunkt sichern
    
    try {
        $cityService = new CityService($pdo);
        $parser = new WarehouseParser($cityService);
        $synchronizer = new WarehouseSynchronizer($pdo);
        
        // Rohtext über die Parserklasse einlesen
        $parsedOrders = $parser->parse($rawData);
        
        if (empty($parsedOrders)) {
            $message = "Keine gültigen Aufträge im Textblock gefunden. Bitte überprüfen Sie den einkopierten Inhalt.";
            $messageClass = "status-error";
        } else {
            $matchedCount = 0;
            $updatedCount = 0;
            $newCreatedCount = 0;
            
            // Jeden parsten Auftrag über den Synchronizer abgleichen
            foreach ($parsedOrders as $order) {
                $status = $synchronizer->syncOrder($order);
                if ($status === 1) {
                    $matchedCount++;
                } elseif ($status === 2) {
                    $updatedCount++;
                } elseif ($status === 3) {
                    $newCreatedCount++;
                }
            }
            // -------------------------------------------------------------
            // AUTO-ARCHIVIERUNG & TOUREN-FORTSCHREIBUNG (PH § 8)
            // KORREKTUR: Basis-IDN-Abgleich zur split-sicheren Ghost-Tour-Bereinigung!
            // -------------------------------------------------------------
            
            // 1. Alle im aktuellen Import gesichteten Basis-IDNs sammeln (ohne künstliche Split-Suffixe)
            $importedBaseIdns = [];
            foreach ($parsedOrders as $order) {
                if (!empty($order['ingame_order_id'])) {
                    // Extrahiert den Teil vor dem Bindestrich (z.B. IDN10688810)
                    $base = explode('-', $order['ingame_order_id'])[0];
                    $importedBaseIdns[] = strtoupper(trim($base));
                }
            }
            $importedBaseIdns = array_unique($importedBaseIdns);

            // 2. Alle aktuell aktiven, unarchivierten Lageraufträge aus der DB laden
            $activeDbOrders = $pdo->query("
                SELECT id, ingame_order_id, to_city_id, assigned_truck_id 
                FROM orders 
                WHERE is_accepted = 1 
                  AND is_archived = 0 
                  AND ingame_order_id IS NOT NULL
            ")->fetchAll(PDO::FETCH_ASSOC);

            // 3. Ermitteln, welche Aufträge wirklich aus dem Spiel verschwunden sind
            // Ein verplanter LKW-Job (Klon) darf nur archiviert werden, wenn seine Basis-IDN nicht mehr im Import existiert
            $disappearedOrders = [];
            foreach ($activeDbOrders as $dbOrd) {
                $dbBaseIdn = strtoupper(explode('-', $dbOrd['ingame_order_id'])[0]);
                if (!in_array($dbBaseIdn, $importedBaseIdns, true)) {
                    $disappearedOrders[] = $dbOrd;
                }
            }

            $archivedWarehouseCount = 0;
            foreach ($disappearedOrders as $disp) {
                // Wenn der erledigte Auftrag einem LKW zugewiesen war, hat der LKW ihn geliefert.
                // Wir verschieben den LKW-Standort automatisch an das Ziel dieses gelieferten Jobs (PH § 8.3)
                if ($disp['assigned_truck_id']) {
                    $stmtMoveTruck = $pdo->prepare("
                        UPDATE trucks 
                        SET current_city_id = ? 
                        WHERE id = ?
                    ");
                    $stmtMoveTruck->execute([
                        (int)$disp['to_city_id'],
                        (int)$disp['assigned_truck_id']
                    ]);
                }

                // Den beendeten Auftrag archivieren (und sauber entkoppeln)
                $stmtArchive = $pdo->prepare("
                    UPDATE orders 
                    SET is_archived = 1, 
                        completed_at = NOW(), 
                        assigned_truck_id = NULL, 
                        assigned_at = NULL 
                    WHERE id = ?
                ");
                $stmtArchive->execute([$disp['id']]);
                $archivedWarehouseCount++;
            }

            // Präzises, vierstufiges Feedback für den Anwender aufbauen
            $message = "Lager-Import erfolgreich abgeschlossen! ";
            $feedbackParts = [];
            if ($matchedCount > 0) {
                $feedbackParts[] = "{$matchedCount} Aufträge wurden erfolgreich mit der Börse synchronisiert (verheiratet).";
            }
            if ($updatedCount > 0) {
                $feedbackParts[] = "{$updatedCount} bereits erfasste Lageraufträge wurden aktualisiert (Mengenabgleich durchgeführt).";
            }
            if ($newCreatedCount > 0) {
                $feedbackParts[] = "{$newCreatedCount} neue, autonome Lageraufträge wurden erfasst.";
            }
            if ($archivedWarehouseCount > 0) {
                $feedbackParts[] = "{$archivedWarehouseCount} Im Spiel beendete/erledigte Lageraufträge wurden archiviert und LKW-Standorte aktualisiert.";
            }
            $message .= implode(" ", $feedbackParts);
            $messageClass = "status-success";
        }
    } catch (Exception $e) {
        $message = "Fehler beim Importieren: " . htmlspecialchars($e->getMessage());
        $messageClass = "status-error";
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Eigenes Lager Import - TransportBoss</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <?php require_once 'nav.php'; ?>
    <div class="fluid-container" style="max-width: 1000px; margin: 0 auto;">
        <h1 class="accent-text">Eigenes Lager Import</h1>
        
        <?php if ($message): ?>
            <div class="feedback-msg <?= $messageClass ?>"><?= $message ?></div>
        <?php endif; ?>

        <!-- Formular (Ganz oben über der Tabelle platziert) -->
        <form method="post" action="market_warehouse.php">
            <label for="import_data">Rohtext aus dem Ingame-Lager (angenommene Aufträge) einfügen:</label><br>
            <textarea id="import_data" name="import_data" class="import-textarea" required></textarea><br>
            <button type="submit" class="btn-primary">Lager-Daten importieren</button>
        </form>

        <?php if (!empty($parsedOrders) && $messageClass === 'status-success'): ?>
            <hr class="section-divider">

            <!-- Kontroll-Tabelle der eingelesenen Frachten -->
            <div style="margin-bottom: 25px; overflow-x: auto;">
                <h3 class="accent-text" style="font-size: 1.1em; margin-bottom: 10px;">Kontrollübersicht: Importierte Daten</h3>
                
                <!-- Multisearch Filter-Feld -->
                <input type="text" id="tableFilter" class="filter-input" placeholder="Tabelle durchsuchen (z.B. Stadt, IDN, Ware; mehrere Keywords möglich)...">
                
                <table class="data-table" id="sortableTable" style="font-size: 0.85em; white-space: nowrap;">
                    <thead>
                        <tr>
                            <th onclick="sortTable(0, 'string')">Ingame-ID (IDN) ⇕</th>
                            <th onclick="sortTable(1, 'string')">Frachttyp ⇕</th>
                            <th onclick="sortTable(2, 'string')">Ware ⇕</th>
                            <th onclick="sortTable(3, 'string')">ADR ⇕</th>
                            <th onclick="sortTable(4, 'number')">Gewicht (Rest/Gesamt) ⇕</th>
                            <th onclick="sortTable(5, 'number')">Erlös ⇕</th>
                            <th onclick="sortTable(6, 'string')">Route ⇕</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($parsedOrders as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['ingame_order_id'] ?? 'Keine IDN') ?></td>
                                <td><?= htmlspecialchars($item['freight_type']) ?></td>
                                <td><?= htmlspecialchars($item['commodity']) ?></td>
                                <td><?= $item['is_adr'] ? 'Ja' : 'Nein' ?></td>
                                <td><?= $item['weight_remaining'] ?> / <?= $item['weight_total'] ?> t</td>
                                <td><?= number_format((float)$item['revenue'], 2, ',', '.') ?> €</td>
                                <td><?= htmlspecialchars($item['from_city_name']) ?> ➔ <?= htmlspecialchars($item['to_city_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Client-Side Filter (Multisearch) & Sort Logik -->
    <script>
        // --- Multisearch Filter-Logik (UND-Verknüpfung mehrerer Wörter) ---
        document.getElementById('tableFilter').addEventListener('keyup', function() {
            let filter = this.value.toLowerCase();
            let rows = document.querySelectorAll('#sortableTable tbody tr');
            let keywords = filter.split(/\s+/).filter(k => k.trim() !== '');
            
            rows.forEach(row => {
                let text = row.textContent.toLowerCase();
                let match = true;
                
                // Prüfe, ob jedes einzelne Suchwort in der Zeile enthalten ist
                for (let kw of keywords) {
                    if (!text.includes(kw)) {
                        match = false;
                        break;
                    }
                }
                row.style.display = match ? '' : 'none';
            });
        });

        // --- Sortier-Logik ---
        let sortDirections = [false, false, false, false, false, false, false]; 

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
                    if (valA.includes('/')) valA = valA.split('/')[0].trim();
                    if (valB.includes('/')) valB = valB.split('/')[0].trim();
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