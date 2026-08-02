<?php
declare(strict_types=1);

// CORS-Header für 1-Klick-Bookmarklet Importe aus dem Spiel-Tab freischalten
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

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
require_once 'classes/DistanceService.php';

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
        // 0. ISO-8859-1 / Windows-1252 Ingame-Codierungen automatisch in echtes UTF-8 umwandeln
        if (!mb_check_encoding($rawData, 'UTF-8')) {
            $rawData = mb_convert_encoding($rawData, 'UTF-8', 'ISO-8859-1, WINDOWS-1252, CP1252');
        }

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
            // Transaktion VOR dem Parsen starten (schützt vor impliziten INSERT-Transaktionen bei neuen Städten)
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
            }

            $cityService = new CityService($this->pdo);
            $parser = new OrderParser($cityService);

            // Text über die Parser-Klasse einlesen
            $parsedOrders = $parser->parse($rawData, false);

            if (empty($parsedOrders)) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                return [
                    'message' => 'Keine gültigen Aufträge im Textblock gefunden. Bitte überprüfen Sie das Format.',
                    'messageClass' => 'status-error',
                    'parsed' => []
                ];
            }

            // 1. SCHRITT: RMP-SCHUTZ (Pauschales DELETE entfernt zum Schutz anderer Länder)
            $distanceService = new DistanceService($this->pdo);
            $importedCount = 0;

            // Prepared Statements für Existenz-Check, Update und Insert
            $stmtCheckExisting = $this->pdo->prepare("
                SELECT id 
                FROM orders 
                WHERE fingerprint = :fingerprint 
                  AND is_accepted = 0 
                  AND assigned_truck_id IS NULL 
                  AND is_archived = 0 
                LIMIT 1
            ");

            $stmtUpdateSeen = $this->pdo->prepare("
                UPDATE orders 
                SET last_seen_at = NOW(),
                    weight_remaining = :weight_remaining,
                    revenue = :revenue
                WHERE id = :id
            ");

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

            foreach ($parsedOrders as $order) {
                // 2. SCHRITT: Subtraktions-Prüfung für bereits verplante Teile (PH § 10.5)
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

                $originalWeight = (int)$order['weight_total'];
                $originalRevenue = (float)$order['revenue'];

                $remainingWeight = $originalWeight - $assignedWeight;
                $remainingRevenue = max(0.0, $originalRevenue - $assignedRevenue);

                // 3. SCHRITT: AUTOMATISCHE MATRIZEN-FÜTTERUNG (WEG A)
                if (!empty($order['distance_km']) && (int)$order['distance_km'] > 0) {
                    if (!$distanceService->hasDistance((int)$order['from_city_id'], (int)$order['to_city_id'])) {
                        $distanceService->setDistance((int)$order['from_city_id'], (int)$order['to_city_id'], (int)$order['distance_km']);
                    }
                }

                if ($remainingWeight <= 0) {
                    $importedCount++;
                    continue;
                }

                // 4. SCHRITT: EXISTENZ-CHECK (DUBLETTEN-SCHUTZ)
                $stmtCheckExisting->execute(['fingerprint' => $order['fingerprint']]);
                $existingId = $stmtCheckExisting->fetchColumn();

                if ($existingId !== false) {
                    // Auftrag existiert bereits aktiv im Pool -> Zeitstempel & Resttonnage aktualisieren
                    $stmtUpdateSeen->execute([
                        'weight_remaining' => $remainingWeight,
                        'revenue'          => $remainingRevenue,
                        'id'               => (int)$existingId
                    ]);
                } else {
                    // Neuer Auftrag -> Neuanlage im Pool
                    $stmtInsert->execute([
                        'fingerprint'      => $order['fingerprint'],
                        'freight_type'     => $order['freight_type'],
                        'commodity'        => $order['commodity'],
                        'is_adr'           => $order['is_adr'],
                        'weight_total'     => $originalWeight,
                        'weight_remaining' => $remainingWeight,
                        'revenue'          => $remainingRevenue,
                        'from_city'        => $order['from_city_id'],
                        'to_city'          => $order['to_city_id']
                    ]);
                }

                $importedCount++;
            }

            // 2. RMP LÄNDER-RELATIONEN SCOPE-ARCHIVIERUNG (z. B. DE_DE, DE_AUT, HU_UA)
            $importedRelations = [];
            foreach ($parsedOrders as $po) {
                if (!empty($po['from_city_id']) && !empty($po['to_city_id'])) {
                    $fromCC = $cityService->getCountryCode((int)$po['from_city_id']);
                    $toCC   = $cityService->getCountryCode((int)$po['to_city_id']);
                    $importedRelations[] = $fromCC . '_' . $toCC;
                }
            }
            $importedRelations = array_unique($importedRelations);

            $archivedCount = 0;
            if (!empty($importedRelations)) {
                $stmtActivePool = $this->pdo->query("
                    SELECT id, from_city_id, to_city_id, last_seen_at 
                    FROM orders 
                    WHERE is_accepted = 0 
                      AND is_archived = 0 
                      AND assigned_truck_id IS NULL
                ");
                $activePoolOrders = $stmtActivePool->fetchAll(PDO::FETCH_ASSOC);

                $stmtArchiveSingle = $this->pdo->prepare("
                    UPDATE orders 
                    SET is_archived = 1, completed_at = NOW() 
                    WHERE id = ?
                ");

                foreach ($activePoolOrders as $apo) {
                    $fromCC = $cityService->getCountryCode((int)$apo['from_city_id']);
                    $toCC   = $cityService->getCountryCode((int)$apo['to_city_id']);
                    $orderRelation = $fromCC . '_' . $toCC;

                    // NUR archivieren, wenn die LÄNDER-RELATION im Import vorkam, dieser konkrete Auftrag dort aber gefehlt hat!
                    if (in_array($orderRelation, $importedRelations, true) && $apo['last_seen_at'] < $importStartTime) {
                        $stmtArchiveSingle->execute([(int)$apo['id']]);
                        $archivedCount++;
                    }
                }
            }

            $this->pdo->commit();

            $relationCount = count($importedRelations);
            $message = "RMP-Import erfolgreich! {$importedCount} Angebote über {$relationCount} Länder-Relationen verarbeitet. {$archivedCount} verfallene Börsen-Angebote im Ziel-Scope wurden ins Archiv verschoben (fremde Länder-Kombinationen blieben unberührt).";
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

// Session starten falls noch nicht aktiv (für die Weiterleitungsmeldungen)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['import_data'])) {
    $viewData = $controller->import($_POST['import_data']);
    
    // KORREKTUR: Bei erfolgreichem Import leiten wir den Disponenten sofort 
    // auf die Hauptübersicht der Frachtbörse (orders_view.php) weiter!
    if ($viewData['messageClass'] === 'status-success') {
        $_SESSION['pb_pool_message'] = $viewData['message'];
        $_SESSION['pb_pool_message_class'] = $viewData['messageClass'];
        header("Location: orders_view.php");
        exit;
    }
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
            <label for="import_data">Rohtext aus der Ingame-Frachtbörse einfügen (RMP-Multi-Parsing aktiv: Einzelländer oder mehrere Länder untereinander einkopieren):</label><br>
            <textarea id="import_data" name="import_data" class="import-textarea" placeholder="Börsentabellen einzelner Länder oder mehrerer Länder untereinander einkopieren..." required></textarea><br>
            <button type="submit" class="btn-primary">Börsen-Angebote importieren (RMP)</button>
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