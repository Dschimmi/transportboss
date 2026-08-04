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
     * Führt eine "Heirat" mit einem aktiven oder archivierten Börsenauftrag durch.
     *
     * KORREKTUR:
     * - Behebt die Duplicate-Entry Integrity-Vulnerability (HY093/1062) durch globale Vorabprüfung!
     * - Schützt verplante LKW-Tonnagen vor Überschreibung (weight_remaining wird nicht auf 0 gesetzt)
     * - Verhindert Geister-Archivierungen durch fortlaufende last_seen_at Updates für verplante LKW-Jobs
     *
     * @param array $order Der parste Auftragsdatensatz
     * @return int 1 = frisch verheiratet/reaktiviert, 2 = bereits existent, 3 = autonom neu angelegt, 4 = übersprungen
     */
    public function syncOrder(array $order): int
    {
        // 1. DUBLETTEN-SCHUTZ: Prüfen, ob die IDN bereits global im System existiert (PH 2.5.1.3)
        // KORREKTUR: Fragt zusätzlich 'is_archived' ab, um voreilige Archivierungen aufzuspüren.
        $stmtCheckIDN = $this->pdo->prepare("SELECT id, assigned_truck_id, is_archived FROM orders WHERE ingame_order_id = ? LIMIT 1");
        $stmtCheckIDN->execute([$order['ingame_order_id']]);
        $existingOrder = $stmtCheckIDN->fetch(PDO::FETCH_ASSOC);

        if ($existingOrder !== false) {
            $existingId = (int)$existingOrder['id'];
            $assignedTruckId = $existingOrder['assigned_truck_id'];
            $isArchived = (int)$existingOrder['is_archived'];

            if ($isArchived === 1) {
                // FALL C: Voreilige oder versehentliche Archivierung im ERP!
                $stmtHealAndDecouple = $this->pdo->prepare("
                    UPDATE orders 
                    SET is_archived = 0,
                        is_accepted = 1,
                        assigned_truck_id = NULL,
                        assigned_at = NULL,
                        weight_remaining = ?,
                        last_seen_at = NOW()
                    WHERE id = ?
                ");
                $stmtHealAndDecouple->execute([$order['weight_remaining'], $existingId]);
                return 1;
            }

            if ($assignedTruckId !== null) {
                // Fall A: IDN ist bereits auf einem LKW geladen
                $stmtUpdateAssigned = $this->pdo->prepare("
                    UPDATE orders 
                    SET last_seen_at = NOW(),
                        is_archived = 0
                    WHERE id = ?
                ");
                $stmtUpdateAssigned->execute([$existingId]);
                return 2;
            } else {
                // Fall B: IDN liegt im Lagerpool.
                // RETROAKTIVE HEILUNG: Prüfe, ob unakzeptierte Teilstücke auf LKWs zu dieser IDN gehören!
                $this->propagateMarriageToClones($existingId, $order['ingame_order_id']);

                $stmtClonesWeight = $this->pdo->prepare("
                    SELECT COALESCE(SUM(weight_loaded), 0) 
                    FROM orders 
                    WHERE ingame_order_id LIKE ? 
                      AND assigned_truck_id IS NOT NULL 
                      AND is_archived = 0
                ");
                $stmtClonesWeight->execute([$order['ingame_order_id'] . '-%']);
                $assignedClonesWeight = (int)$stmtClonesWeight->fetchColumn();

                $adjustedRemaining = max(0, (int)$order['weight_remaining'] - $assignedClonesWeight);

                $stmtUpdatePool = $this->pdo->prepare("
                    UPDATE orders 
                    SET weight_remaining = ?, 
                        last_seen_at = NOW(),
                        is_archived = 0
                    WHERE id = ?
                ");
                $stmtUpdatePool->execute([$adjustedRemaining, $existingId]);
                return 2;
            }
        }

        // 2. IDN existiert noch nicht im System.
        // Wenn die verbleibende Menge im Ingame-Lager bereits 0 ist, existiert kein aktiver Pool-Rest.
        // Wir überspringen die Neuanlage komplett, um unbrauchbaren Datenmüll zu vermeiden.
        if ((int)$order['weight_remaining'] <= 0) {
            return 4; // Code 4: Übersprungen (Bereits vollständig auf LKWs verplant)
        }

        // 3. IDN existiert noch nicht. Versuche einen passenden Börsenauftrag zu heiraten.
        $unitRate = (int)$order['weight_total'] > 0 ? ((float)$order['revenue'] / (int)$order['weight_total']) : 0.0;

        $stmtSearch = $this->pdo->prepare("
            SELECT id, fingerprint, is_archived 
            FROM orders 
            WHERE ingame_order_id IS NULL 
              AND is_archived = 0
              AND (
                    (fingerprint IS NOT NULL AND fingerprint = :fingerprint)
                 OR (
                    from_city_id = :from_city 
                AND to_city_id = :to_city 
                AND (
                    ABS((revenue / NULLIF(weight_total, 0)) - :unit_rate_1) < 0.05
                 OR ABS((revenue / NULLIF(COALESCE(weight_loaded, weight_remaining), 0)) - :unit_rate_2) < 0.05
                )
                 )
              )
            ORDER BY assigned_truck_id IS NULL DESC, id ASC
            LIMIT 1
        ");

        $stmtSearch->execute([
            'fingerprint' => $order['fingerprint'] ?? '',
            'from_city'   => $order['from_city_id'],
            'to_city'     => $order['to_city_id'],
            'unit_rate_1' => $unitRate,
            'unit_rate_2' => $unitRate
        ]);

        $matched = $stmtSearch->fetch(PDO::FETCH_ASSOC);

        if ($matched !== false) {
            // Ermittle bereits verplante Klon-Tonnagen auf LKWs, um Doppelzählung beim Mutterauftrag zu verhindern
            $matchedFingerprint = $matched['fingerprint'] ?? ($order['fingerprint'] ?? '');
            $stmtClonesWeight = $this->pdo->prepare("
                SELECT COALESCE(SUM(weight_loaded), 0) 
                FROM orders 
                WHERE fingerprint = :fingerprint 
                  AND assigned_truck_id IS NOT NULL 
                  AND is_archived = 0
                  AND id != :matched_id
            ");
            $stmtClonesWeight->execute([
                'fingerprint' => $matchedFingerprint,
                'matched_id'  => (int)$matched['id']
            ]);
            $assignedClonesWeight = (int)$stmtClonesWeight->fetchColumn();

            $adjustedRemaining = max(0, (int)$order['weight_remaining'] - $assignedClonesWeight);

            // Fall 3: Match gefunden -> Börsenauftrag verheiraten und Restmenge anpassen
            $stmtUpdate = $this->pdo->prepare("
                UPDATE orders 
                SET ingame_order_id = :idn,
                    is_accepted = 1,
                    is_archived = 0,
                    weight_remaining = :weight_remaining,
                    last_seen_at = NOW()
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                'idn' => $order['ingame_order_id'],
                'weight_remaining' => $adjustedRemaining,
                'id' => (int)$matched['id']
            ]);

            // --- PROPAGIERUNG AN BEREITS GELADENE TEILSTÜCKE AUF LKWS ---
            $this->propagateMarriageToClones((int)$matched['id'], $order['ingame_order_id']);

            return 1; // Code 1: Frisch verheiratet / Reaktiviert
        }

        // Fall 4: Weder IDN noch passender Börsenauftrag existieren -> Autonome Neuanlage
        $stmtInsert = $this->pdo->prepare("
            INSERT INTO orders (
                ingame_order_id, freight_type, commodity, is_adr, weight_total, 
                weight_remaining, revenue, from_city_id, to_city_id, is_accepted, is_archived, last_seen_at
            ) VALUES (
                :idn, :freight_type, :commodity, :is_adr, :weight_total, 
                :weight_remaining, :revenue, :from_city, :to_city, 1, 0, NOW()
            )
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

        /**
         * Propagiert die IDN-Verheiratung und den Lager-Status (is_accepted = 1) 
         * an alle bereits verplanten Teilladungs-Klone auf den LKW.
         */
        private function propagateMarriageToClones(int $parentOrderId, string $baseIdn): void
        {
            // 1. Stammdaten des Mutter-Auftrags ermitteln
            $stmtInfo = $this->pdo->prepare("SELECT fingerprint, from_city_id, to_city_id, weight_total, revenue, commodity, freight_type FROM orders WHERE id = ?");
            $stmtInfo->execute([$parentOrderId]);
            $parent = $stmtInfo->fetch(PDO::FETCH_ASSOC);

            if (!$parent) {
                return;
            }

            $fingerprint = $parent['fingerprint'];
            $unitRate = (int)$parent['weight_total'] > 0 ? ((float)$parent['revenue'] / (int)$parent['weight_total']) : 0.0;

            // 2. Alle LKW-Klone ohne IDN-Suffix suchen (per Fingerprint ODER Route + Ware ODER Frachtrate)
            $stmtClones = $this->pdo->prepare("
                SELECT id 
                FROM orders 
                WHERE assigned_truck_id IS NOT NULL 
                  AND is_archived = 0
                  AND (ingame_order_id IS NULL OR ingame_order_id NOT LIKE :base_idn_pattern)
                  AND id != :parent_id
                  AND (
                        (fingerprint IS NOT NULL AND fingerprint != '' AND fingerprint = :fingerprint)
                     OR (
                        from_city_id = :from_city 
                    AND to_city_id = :to_city 
                    AND (
                        (commodity = :commodity AND freight_type = :freight_type)
                     OR ABS((revenue / NULLIF(COALESCE(weight_loaded, weight_total - weight_remaining), 0)) - :unit_rate_1) < 0.05
                     OR ABS((revenue / NULLIF(weight_remaining, 0)) - :unit_rate_2) < 0.05
                     OR ABS((revenue / NULLIF(weight_total, 0)) - :unit_rate_3) < 0.05
                    )
                     )
                  )
                ORDER BY id ASC
            ");
            $stmtClones->execute([
                'base_idn_pattern' => $baseIdn . '-%',
                'parent_id'        => $parentOrderId,
                'fingerprint'      => $fingerprint ?? '',
                'from_city'        => $parent['from_city_id'],
                'to_city'          => $parent['to_city_id'],
                'commodity'        => $parent['commodity'],
                'freight_type'     => $parent['freight_type'],
                'unit_rate_1'      => $unitRate,
                'unit_rate_2'      => $unitRate,
                'unit_rate_3'      => $unitRate
            ]);
            $clones = $stmtClones->fetchAll(PDO::FETCH_COLUMN);

            if (empty($clones)) {
                return;
            }

            // 3. Bereits existierende Suffixe für diese IDN ermitteln
            $stmtSuffixes = $this->pdo->prepare("SELECT ingame_order_id FROM orders WHERE ingame_order_id LIKE ?");
            $stmtSuffixes->execute([$baseIdn . '-%']);
            $existingSuffixes = $stmtSuffixes->fetchAll(PDO::FETCH_COLUMN);

            $maxSuffix = 0;
            foreach ($existingSuffixes as $existingIdn) {
                $parts = explode('-', $existingIdn);
                if (isset($parts[1])) {
                    $suffixNum = (int)$parts[1];
                    if ($suffixNum > $maxSuffix) {
                        $maxSuffix = $suffixNum;
                    }
                }
            }

            // 4. Jeden Klon auf dem LKW aktivieren und mit fortlaufender Suffix-IDN versehen
            $stmtUpdateClone = $this->pdo->prepare("
                UPDATE orders 
                SET ingame_order_id = ?, 
                    is_accepted = 1, 
                    last_seen_at = NOW() 
                WHERE id = ?
            ");

            foreach ($clones as $cloneId) {
                $maxSuffix++;
                $splitIdn = $baseIdn . '-' . $maxSuffix;
                $stmtUpdateClone->execute([$splitIdn, (int)$cloneId]);
            }
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