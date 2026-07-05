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
 * @version 1.1.0
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
            
            // Text über die Parser-Klasse einlesen
            $parsedOrders = $parser->parse($rawData, false);

            if (empty($parsedOrders)) {
                return [
                    'message' => 'Keine gültigen Aufträge im Textblock gefunden. Bitte überprüfen Sie das Format.',
                    'messageClass' => 'status-error',
                    'parsed' => []
                ];
            }

            $this->pdo->beginTransaction();

            $importedCount = 0;
            foreach ($parsedOrders as $order) {
                // 1. Dubletten-Schutz: Prüfen, ob dieser Auftrag bereits aktiv im Pool existiert (PH 3.4.2)
                $stmtCheck = $this->pdo->prepare("
                    SELECT id FROM orders 
                    WHERE fingerprint = :fingerprint 
                      AND is_accepted = 0 
                      AND is_archived = 0 
                    LIMIT 1
                ");
                $stmtCheck->execute(['fingerprint' => $order['fingerprint']]);
                $existingId = $stmtCheck->fetchColumn();

                if ($existingId !== false) {
                    // Fall A: Bereits aktiv vorhanden -> Nur den "Zuletzt gesehen"-Zeitstempel erneuern
                    $stmtUpdate = $this->pdo->prepare("UPDATE orders SET last_seen_at = NOW() WHERE id = ?");
                    $stmtUpdate->execute([(int)$existingId]);
                } else {
                    // Fall B: Neuer Auftrag -> Datensatz persistent anlegen
                    $stmtInsert = $this->pdo->prepare("
                        INSERT INTO orders (fingerprint, freight_type, commodity, is_adr, weight_total, weight_remaining, revenue, from_city_id, to_city_id, is_accepted, is_archived, last_seen_at)
                        VALUES (:fingerprint, :freight_type, :commodity, :is_adr, :weight, :weight, :revenue, :from_city, :to_city, 0, 0, NOW())
                    ");
                    $stmtInsert->execute([
                        'fingerprint' => $order['fingerprint'],
                        'freight_type' => $order['freight_type'],
                        'commodity' => $order['commodity'],
                        'is_adr' => $order['is_adr'],
                        'weight' => $order['weight_total'],
                        'revenue' => $order['revenue'],
                        'from_city' => $order['from_city_id'],
                        'to_city' => $order['to_city_id']
                    ]);
                }
                $importedCount++;
            }

            // 2. Automatisierte Archivierung (PH 3.4.3)
            // Alle Börsenaufträge, die im aktuellen Import-Lauf nicht mehr gesichtet wurden, werden archiviert.
            $stmtArchive = $this->pdo->prepare("
                UPDATE orders 
                SET is_archived = 1, completed_at = NOW() 
                WHERE is_accepted = 0 
                  AND is_archived = 0 
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
            
            <!-- Kontroll-Tabelle der eingelesenen Frachten -->
            <?php if (!empty($viewData['parsed']) && $viewData['messageClass'] === 'status-success'): ?>
                <div style="margin-bottom: 25px; overflow-x: auto;">
                    <h3 class="accent-text" style="font-size: 1em; margin-bottom: 10px;">Kontrollübersicht: Importierte Börsendaten</h3>
                    <table class="data-table" style="font-size: 0.85em; white-space: nowrap;">
                        <thead>
                            <tr>
                                <th>Frachttyp</th>
                                <th>Ware</th>
                                <th>ADR</th>
                                <th>Gewicht</th>
                                <th>Erlös</th>
                                <th>Distanz</th>
                                <th>Route</th>
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
        <?php endif; ?>
        
        <!-- Eingabeformular -->
        <form method="post" action="market_pool.php">
            <label for="import_data">Rohtext aus der Ingame-Frachtbörse (Kopierter Auftragspool) einfügen:</label><br>
            <textarea id="import_data" name="import_data" class="import-textarea" required></textarea><br>
            <button type="submit" class="btn-primary">Börsen-Angebote importieren</button>
        </form>
    </div>
</body>
</html>