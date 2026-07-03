<?php
declare(strict_types=1);

require_once 'db_connect.php';
require_once 'classes/FinanceMapper.php';
require_once 'classes/City.php';
require_once 'classes/CityService.php';
require_once 'classes/Order.php';
require_once 'classes/WarehouseParser.php';
require_once 'classes/OrderRepository.php';

$message = '';
$messageClass = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['import_data'])) {
    $rawData = $_POST['import_data'];
    
    try {
        $cityService = new CityService($pdo);
        $parser = new WarehouseParser($cityService);
        $repo = new OrderRepository($pdo);
        
        $parsedOrders = $parser->parse($rawData);
        
        if (empty($parsedOrders)) {
            $message = "Keine gültigen Aufträge gefunden. Bitte den kopierten Text prüfen.";
            $messageClass = "status-error";
        } else {
            $matchedCount = 0;
            $unmatchedCount = 0;
            
            foreach ($parsedOrders as $order) {
                // Versuche den Lager-Auftrag mit dem Pool zu matchen
                if ($repo->syncWarehouseOrder($order)) {
                    $matchedCount++;
                } else {
                    $unmatchedCount++;
                }
            }
            
            $message = "Lager-Import beendet! $matchedCount Aufträge erfolgreich mit der Börse synchronisiert.";
            if ($unmatchedCount > 0) {
                $message .= " ($unmatchedCount Aufträge konnten nicht gematcht werden, da sie evtl. vor der Installation des Tools angenommen wurden).";
            }
            $messageClass = "status-success";
        }
    } catch (Exception $e) {
        $message = "Fehler beim Import: " . htmlspecialchars($e->getMessage());
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
    <div class="main-container">
        <h1 class="accent-text">Eigenes Lager Import</h1>
        
        <?php if ($message): ?>
            <div class="feedback-msg <?= $messageClass ?>"><?= $message ?></div>
        <?php endif; ?>

        <form method="post" action="market_warehouse.php">
            <label for="import_data">Rohtext aus dem Lager (angenommene Aufträge) einfügen:</label><br>
            <textarea id="import_data" name="import_data" class="import-textarea" required></textarea><br>
            <button type="submit" class="btn-primary">Lager aktualisieren</button>
        </form>
    </div>
</body>
</html>