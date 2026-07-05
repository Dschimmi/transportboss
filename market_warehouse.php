<?php
declare(strict_types=1);

/**
 * market_warehouse.php
 *
 * Import-Schnittstelle für das eigene Lager (angenommene Aufträge).
 * Nimmt den mehrzeiligen Ingame-Kopiertext entgegen, parst die Daten und
 * verheiratet diese über die Ingame-ID (IDN) mit bestehenden Börseneinträgen
 * oder legt sie bei Bedarf autonom neu an.
 *
 * @author TransportBoss Development
 * @version 1.1.0
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
     * Führt eine "Heirat" mit einem passenden Börsenauftrag durch oder legt diesen neu an.
     *
     * @param array $order Der parste Auftragsdatensatz
     * @return bool True, wenn ein bestehender Börsenauftrag verheiratet wurde, sonst false (Neuanlage)
     */
    public function syncOrder(array $order): bool
    {
        // 1. Suche nach einem exakt passenden, noch unbestätigten Börsenauftrag (is_accepted = 0)
        // zur Durchführung der IDN-Heirat (Erhaltung der Datenhistorie)
        $stmtSearch = $this->pdo->prepare("
            SELECT id FROM orders 
            WHERE is_accepted = 0 
              AND is_archived = 0 
              AND from_city_id = :from_city
              AND to_city_id = :to_city
              AND weight_total = :weight_total
              AND revenue = :revenue
              AND assigned_truck_id IS NULL
            LIMIT 1
        ");

        $stmtSearch->execute([
            'from_city' => $order['from_city_id'],
            'to_city' => $order['to_city_id'],
            'weight_total' => $order['weight_total'],
            'revenue' => $order['revenue']
        ]);

        $matchedId = $stmtSearch->fetchColumn();

        if ($matchedId !== false) {
            // FALL A: Match gefunden -> Börsenauftrag mit Ingame-ID verheiraten und ins Lager überführen
            $stmtUpdate = $this->pdo->prepare("
                UPDATE orders 
                SET ingame_order_id = :idn,
                    is_accepted = 1,
                    weight_remaining = :weight_remaining,
                    last_seen_at = NOW()
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                'idn' => $order['ingame_order_id'],
                'weight_remaining' => $order['weight_remaining'],
                'id' => (int)$matchedId
            ]);
            return true;
        }

        // FALL B: Kein Match gefunden -> Auftrag wurde z. B. vor Installation des Tools angenommen.
        // Autonome Neuanlage direkt im Status akzeptiert (is_accepted = 1).
        $stmtInsert = $this->pdo->prepare("
            INSERT INTO orders (ingame_order_id, freight_type, commodity, is_adr, weight_total, weight_remaining, revenue, from_city_id, to_city_id, is_accepted, is_archived, last_seen_at)
            VALUES (:idn, :freight_type, :commodity, :is_adr, :weight_total, :weight_remaining, :revenue, :from_city, :to_city, 1, 0, NOW())
            ON DUPLICATE KEY UPDATE 
                weight_remaining = :weight_remaining,
                last_seen_at = NOW()
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
        return false;
    }
}

// Variablen für die Benutzerführung
$message = '';
$messageClass = '';
$parsedOrders = [];

// Formularverarbeitung
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['import_data'])) {
    $rawData = $_POST['import_data'];
    
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
            $newCreatedCount = 0;
            
            // Jeden parsten Auftrag synchronisieren
            foreach ($parsedOrders as $order) {
                if ($synchronizer->syncOrder($order)) {
                    $matchedCount++;
                } else {
                    $newCreatedCount++;
                }
            }
            
            $message = "Lager-Import erfolgreich abgeschlossen! {$matchedCount} Aufträge wurden erfolgreich mit der Börse synchronisiert (verheiratet).";
            if ($newCreatedCount > 0) {
                $message .= " {$newCreatedCount} Aufträge existierten nicht in der Börse und wurden direkt als Lager-Stamm angelegt.";
            }
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
            
            <!-- Kontrolltabelle für den Benutzer -->
            <?php if (!empty($parsedOrders) && $messageClass === 'status-success'): ?>
                <div style="margin-bottom: 25px; overflow-x: auto;">
                    <h3 class="accent-text" style="font-size: 1em; margin-bottom: 10px;">Kontrollübersicht: Importierte Daten</h3>
                    <table class="data-table" style="font-size: 0.85em; white-space: nowrap;">
                        <thead>
                            <tr>
                                <th>Ingame-ID (IDN)</th>
                                <th>Frachttyp</th>
                                <th>Ware</th>
                                <th>ADR</th>
                                <th>Gewicht (Rest/Gesamt)</th>
                                <th>Erlös</th>
                                <th>Route</th>
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
        <?php endif; ?>
        
        <!-- Eingabeformular -->
        <form method="post" action="market_warehouse.php">
            <label for="import_data">Rohtext aus dem Ingame-Lager (angenommene Aufträge) einfügen:</label><br>
            <textarea id="import_data" name="import_data" class="import-textarea" required></textarea><br>
            <button type="submit" class="btn-primary">Lager-Daten importieren</button>
        </form>
    </div>
</body>
</html>