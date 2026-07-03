<?php
declare(strict_types=1); // Typsicherheit (PH 1.1.3.1)[cite: 3]

// Einbinden der benötigten Ressourcen
require_once 'db_connect.php';
require_once 'classes/FinanceMapper.php';
require_once 'classes/City.php';
require_once 'classes/CityService.php';
require_once 'classes/Order.php';
require_once 'classes/OrderParser.php';
require_once 'classes/OrderRepository.php';

$message = '';
$messageClass = '';

// POST-Request verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['import_data'])) {
    $rawData = $_POST['import_data'];
    
    // Startzeitpunkt des Imports für die Archivierungs-Logik (PH 3.4.3.2)[cite: 3]
    $importStartTime = date('Y-m-d H:i:s');
    
    try {
        // Services instanziieren
        $cityService = new CityService($pdo);
        $parser = new OrderParser($cityService);
        $repo = new OrderRepository($pdo);
        
        // Parsen: isAccepted = false (da es sich um den Marktpool handelt, nicht das eigene Lager)[cite: 3]
        $parsedOrders = $parser->parse($rawData, false);
        
        if (empty($parsedOrders)) {
            $message = "Keine gültigen Aufträge gefunden. Bitte den kopierten Text prüfen.";
            $messageClass = "status-error";
        } else {
            $importedCount = 0;
            
            // 1. Aufträge speichern oder aktualisieren (PH 3.4.2)[cite: 3]
            foreach ($parsedOrders as $order) {
                $repo->save($order);
                $importedCount++;
            }
            
            // 2. Automatisierter Archivierungsprozess (PH 3.4.3)[cite: 3]
            // Alle Börsen-Aufträge (is_accepted = 0), die nicht im aktuellen Import waren (last_seen_at < Startzeit), werden archiviert.
            $stmtArchive = $pdo->prepare("
                UPDATE orders 
                SET is_archived = 1, completed_at = CURRENT_TIMESTAMP 
                WHERE is_accepted = 0 
                  AND is_archived = 0 
                  AND last_seen_at < :start_time
            ");
            $stmtArchive->execute(['start_time' => $importStartTime]);
            $archivedCount = $stmtArchive->rowCount();
            
            // Benutzerrückmeldung generieren (PH 1.3.5.2)[cite: 3]
            $message = "Import erfolgreich! $importedCount Aufträge verarbeitet. $archivedCount alte Angebote wurden archiviert.";
            $messageClass = "status-success";
        }
    } catch (Exception $e) {
        // Fehler abfangen (PH 1.3.5.1)[cite: 3]
        $message = "Fehler beim Import: " . htmlspecialchars($e->getMessage());
        $messageClass = "status-error";
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Frachtbörse Import - TransportBoss</title>
    <!-- Zentrales Styling (PH 1.3.2.2)[cite: 3] -->
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <!-- Oberflächen-Farbe Ebene 1 (PH 1.4.1.2)[cite: 3] -->
    <div class="main-container">
        <!-- Akzentfarbe Orange (PH 1.4.1.3)[cite: 3] -->
        <h1 class="accent-text">Auftragspool Import (Frachtbörse)</h1>
        
        <?php if ($message): ?>
            <div class="feedback-msg <?= $messageClass ?>"><?= $message ?></div>
        <?php endif; ?>

        <form method="post" action="market_pool.php">
            <label for="import_data">Rohtext aus dem Auftragspool (Kopierte Tabelle) einfügen:</label><br>
            <textarea id="import_data" name="import_data" class="import-textarea" required></textarea><br>
            <button type="submit" class="btn-primary">Aufträge in die Börse laden</button>
        </form>
    </div>
</body>
</html>