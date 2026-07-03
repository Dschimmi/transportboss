<?php
declare(strict_types=1);

// Einbinden der benötigten Ressourcen
require_once 'db_connect.php';
require_once 'classes/FinanceMapper.php';
require_once 'classes/VehicleMarketParser.php';
require_once 'classes/VehicleMarketRepository.php';

$message = '';
$messageClass = '';

// POST-Request verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['import_data'])) {
    $htmlData = $_POST['import_data'];
    
    try {
        // Parser instanziieren und LKW-Daten extrahieren
        $parser = new VehicleMarketParser();
        $parsedVehicles = $parser->parse($htmlData);
        
        if (empty($parsedVehicles)) {
            $message = "Keine Fahrzeuge im Quelltext gefunden. Bitte den kopierten Text prüfen.";
            $messageClass = "status-error";
        } else {
            // Repository instanziieren und Daten speichern (inkl. ROI-Berechnung)
            $repo = new VehicleMarketRepository($pdo);
            $importedCount = $repo->saveBatch($parsedVehicles);
            
            // Erfolgsmeldung
            $message = "Import erfolgreich! $importedCount Gebrauchtwagen verarbeitet und ROI-Scores aktualisiert.";
            $messageClass = "status-success";
        }
        
    } catch (Exception $e) {
        // Fehler abfangen und kontrolliert ausgeben
        $message = "Fehler beim Import: " . htmlspecialchars($e->getMessage());
        $messageClass = "status-error";
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Gebrauchtwagen Import - TransportBoss</title>
    <!-- Zentrales Styling -->
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <!-- Oberflächen-Farbe Ebene 1 -->
    <div class="main-container">
        <!-- Akzentfarbe Orange -->
        <h1 class="accent-text">Fahrzeughandel Import</h1>
        
        <?php if ($message): ?>
            <div class="feedback-msg <?= $messageClass ?>"><?= $message ?></div>
        <?php endif; ?>

        <form method="post" action="market_vehicles.php">
            <label for="import_data">HTML-Quelltext aus dem Fahrzeughandel einfügen:</label><br>
            <textarea id="import_data" name="import_data" class="import-textarea" required></textarea><br>
            <button type="submit" class="btn-primary">Gebrauchtwagen importieren</button>
        </form>
    </div>
</body>
</html>