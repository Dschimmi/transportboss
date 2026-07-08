<?php
declare(strict_types=1);

/**
 * market_pool.php
 *
 * Import-Schnittstelle für die Frachtbörse (Auftragspool).
 * Nimmt den einkopierten Rohtext der Ingame-Börse entgegen, gleicht diesen
 * über inhaltliche Fingerprints ab und archiviert veraltete Angebote automatisch.
 *
 * @author TransportBoss Development
 * @version 1.1.1
 */

// Zentrale Abhängigkeiten laden
require_once 'db_connect.php';
require_once 'classes/CityService.php';
require_once 'classes/OrderParser.php';

use classes\OrderParser;

/**
 * MarketPoolController
 *
 * Kapselt die Steuerungs- und Persistenzlogik für den Import
 * der öffentlichen Frachtbörsendaten.
 */
class MarketPoolController
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
     * Verarbeitet den rohen Frachtbörsen-Text, führt das Fingerprint-Matching
     * durch und stößt den Archivierungs-Prozess für alte Angebote an.
     *
     * @param string $rawData Der einkopierte Rohtext
     * @return array Array mit Statusdaten ('message', 'messageClass', 'parsed')
     */
    public function import(string $rawData): array
    {
        $rawData = trim($rawData);
        if ($rawData === '') {
            return [
                'message' => 'Bitte fügen Sie Daten für den Import ein.',
                'messageClass' => 'status-error',
                'parsed' => []
            ];
        }

        // Startzeitpunkt für die spätere Archivierung veralteter Angebote (PH 3.4.3.2)
        $importStartTime = date('Y-m-d H:i:s');

        try {
            $cityService = new CityService($this->pdo);
            $parser = new OrderParser($cityService);
            
            // Text über die Parser-Klasse einlesen (Korrektur: Namespaced-Instanziierung)
            $parsedOrders = $parser->parse($rawData, false);

            if (empty($parsedOrders)) {
                return [
                    'message' => 'Keine gültigen Aufträge im Textblock gefunden. Bitte überprüfen Sie das Format.',
                    'messageClass' => 'status-error',
                    'parsed' => []
                ];
            }

            $this->pdo->beginTransaction();

            // 1. SCHRITT: Bereinigung aller unverplanten Börsen-Aufträge (PH § 10.3)
            // Verhindert das Verbleiben verwaister Reste und heilt die Fragmentierung sofort.
            $this->pdo->exec("
                DELETE FROM orders 
                WHERE is_accepted = 0 
                  AND assigned_truck_id IS NULL 
                  AND is_archived = 0
            ");

            $importedCount = 0;
            foreach ($parsedOrders as $order) {
                // 2. SCHRITT: Subtraktions-Prüfung für bereits verplante Teile (PH § 10.5)
                // Da wir oben die unzugeordneten gelöscht haben, finden wir hier exakt die bereits verplanten Segmente!
                $stmtSum = $this->pdo->prepare("
                    SELECT 
                        COALESCE(SUM(weight_total), 0) AS assigned_weight,
                        COALESCE(SUM(revenue), 0) AS assigned_revenue
                    FROM orders
                    WHERE fingerprint = :fingerprint
                      AND is_accepted = 0
                      AND assigned_truck_id IS NOT NULL
                      AND is_archived = 0
                ");
                $stmtSum->execute(['fingerprint' => $order['fingerprint']]);
                $assigned = $stmtSum->fetch(PDO::FETCH_ASSOC);

                $assignedWeight = (int)($assigned['assigned_weight'] ?? 0);
                $assignedRevenue = (float)($assigned['assigned_revenue'] ?? 0);

                // Reale verbleibende Pool-Mengen nach Abzug berechnen
                $originalWeight = (int)$order['weight_total'];
                $originalRevenue = (float)$order['revenue'];

                $remainingWeight = $originalWeight - $assignedWeight;
                $remainingRevenue = max(0.0, $originalRevenue - $assignedRevenue);

                // Falls die Tonnage bereits vollständig auf LKW verplant wurde, überspringen wir die Neuanlage des Restpostens
                if ($remainingWeight <= 0) {
                    $importedCount++;
                    continue;
                }

                // 3. SCHRITT: Neuanlage des bereinigten Restpostens im Pool
                $stmtInsert = $this->pdo->prepare("
                    INSERT INTO orders (
                        fingerprint, freight_type, commodity, is_adr, 
                        weight_total, weight_remaining, revenue, 
                        from_city_id, to_city_id, is_accepted, is_archived, last_seen_at
                    ) VALUES (
                        :fingerprint, :freight_type, :commodity, :is_adr, 
                        :weight_total, :weight_remaining, :revenue, 
                        :from_city, :to_city, 0, 0, NOW()
                    )
                ");
                $stmtInsert->execute([
                    'fingerprint' => $order['fingerprint'],
                    'freight_type' => $order['freight_type'],
                    'commodity' => $order['commodity'],
                    'is_adr' => $order['is_adr'],
                    'weight_total' => $originalWeight,      // Behält das ungeteilte Ingame-Gewicht (z. B. 43 t)
                    'weight_remaining' => $remainingWeight,  // Reduziert um verplante Mengen (z. B. 17 t)
                    'revenue' => $remainingRevenue,          // Reduziert um verplante Erlöse (z. B. 1.673,71 €)
                    'from_city' => $order['from_city_id'],
                    'to_city' => $order['to_city_id']
                ]);

                $importedCount++;
            }

            // 2. Automatisierte Archivierung (PH 3.4.3)
            // Alle Börsenaufträge, die im aktuellen Import-Lauf nicht mehr gesichtet wurden, werden archiviert.
            $stmtArchive = $this->pdo->prepare("
                UPDATE orders 
                SET is_archived = 1, completed_at = NOW() 
                WHERE is_accepted = 0 
                AND is_archived = 0 
                AND assigned_truck_id IS NULL
                AND last_seen_at < :start_time
            ");
            $stmtArchive->execute(['start_time' => $importStartTime]);
            $archivedCount = $stmtArchive->rowCount();

            $this->pdo->commit();

            $message = "Import erfolgreich! {$importedCount} Angebote verarbeitet. {$archivedCount} veraltete Börsen-Angebote wurden ins Archiv verschoben.";
            return [
                'message' => $message,
                'messageClass' => 'status-success',
                'parsed' => $parsedOrders
            ];

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return [
                'message' => 'Fehler beim Verarbeiten: ' . htmlspecialchars($e->getMessage()),
                'messageClass' => 'status-error',
                'parsed' => []
            ];
        }
    }
}

// Controller instanziieren und ausführen
$controller = new MarketPoolController($pdo);
$viewData = [
    'message' => '',
    'messageClass' => '',
    'parsed' => []
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['import_data'])) {
    $viewData = $controller->import($_POST['import_data']);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Frachtbörse Import - TransportBoss</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <?php require_once 'nav.php'; ?>
    <div class="fluid-container" style="max-width: 1000px; margin: 0 auto;">
        <h1 class="accent-text">Frachtbörse Import (Auftragspool)</h1>
        
        <?php if ($viewData['message']): ?>
            <div class="feedback-msg <?= $viewData['messageClass'] ?>"><?= $viewData['message'] ?></div>
        <?php endif; ?>
        
        <!-- Eingabeformular (Jetzt ganz oben über der Tabelle platziert) -->
        <form method="post" action="market_pool.php">
            <label for="import_data">Rohtext aus der Ingame-Frachtbörse (Kopierter Auftragspool) einfügen:</label><br>
            <textarea id="import_data" name="import_data" class="import-textarea" required></textarea><br>
            <button type="submit" class="btn-primary">Börsen-Angebote importieren</button>
        </form>

        <?php if (!empty($viewData['parsed']) && $viewData['messageClass'] === 'status-success'): ?>
            <hr class="section-divider">

            <!-- Kontroll-Tabelle der eingelesenen Frachten -->
            <div style="margin-bottom: 25px; overflow-x: auto;">
                <h3 class="accent-text" style="font-size: 1.1em; margin-bottom: 10px;">Kontrollübersicht: Importierte Börsendaten</h3>
                
                <!-- Multisearch Filter-Feld -->
                <input type="text" id="tableFilter" class="filter-input" placeholder="Tabelle durchsuchen (z.B. Stadt, Ware, ADR; mehrere Keywords möglich)...">
                
                <table class="data-table" id="sortableTable" style="font-size: 0.85em; white-space: nowrap;">
                    <thead>
                        <tr>
                            <th onclick="sortTable(0, 'string')">Frachttyp ⇕</th>
                            <th onclick="sortTable(1, 'string')">Ware ⇕</th>
                            <th onclick="sortTable(2, 'string')">ADR ⇕</th>
                            <th onclick="sortTable(3, 'number')">Gewicht ⇕</th>
                            <th onclick="sortTable(4, 'number')">Erlös ⇕</th>
                            <th onclick="sortTable(5, 'number')">Distanz ⇕</th>
                            <th onclick="sortTable(6, 'string')">Route ⇕</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($viewData['parsed'] as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['freight_type']) ?></td>
                                <td><?= htmlspecialchars($item['commodity']) ?></td>
                                <td><?= $item['is_adr'] ? 'Ja' : 'Nein' ?></td>
                                <td><?= $item['weight_total'] ?> t</td>
                                <td><?= number_format((float)$item['revenue'], 2, ',', '.') ?> €</td>
                                <td><?= $item['distance_km'] ?> km</td>
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