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
<!-- Zu ersetzender Block in market_vehicles.php -->
<body>
    <?php require_once 'nav.php'; ?>
    <div class="fluid-container" style="max-width: 1000px; margin: 0 auto;">
        <h1 class="accent-text">Fahrzeughandel Import</h1>
        
        <?php if ($message): ?>
            <div class="feedback-msg <?= $messageClass ?>"><?= $message ?></div>
            
            <!-- Dynamische Tabelle zur Kontrolle der geparsten Daten -->
            <?php if (!empty($parsedVehicles) && $messageClass === 'status-success'): ?>
                <div style="margin-bottom: 20px; overflow-x: auto;">
                    <h3 class="accent-text" style="font-size: 1em; margin-bottom: 10px;">Kontrolle: Geparste Fahrzeugdaten</h3>
                    <table class="data-table" style="font-size: 0.85em; white-space: nowrap;">
                        <thead>
                            <tr>
                                <?php 
                                // Spaltenköpfe dynamisch aus dem ersten Element generieren (Objekt oder Array)
                                $firstItem = $parsedVehicles[0];
                                $isObject = is_object($firstItem);
                                $props = $isObject ? (new ReflectionClass($firstItem))->getProperties() : array_keys($firstItem);
                                
                                foreach ($props as $prop) {
                                    $name = $isObject ? $prop->getName() : $prop;
                                    echo '<th>' . htmlspecialchars($name) . '</th>';
                                }
                                ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($parsedVehicles as $item): ?>
                                <tr>
                                    <?php 
                                    // Zeilen dynamisch auslesen
                                    foreach ($props as $prop) {
                                        if ($isObject) {
                                            $prop->setAccessible(true); // Zugriff auf private Eigenschaften erlauben
                                            $val = $prop->getValue($item);
                                        } else {
                                            $val = $item[$prop];
                                        }
                                        
                                        if (is_bool($val)) $val = $val ? 'Ja' : 'Nein';
                                        echo '<td>' . htmlspecialchars((string)$val) . '</td>';
                                    }
                                    ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <form method="post" action="market_vehicles.php">
            <label for="import_data">HTML-Quelltext aus dem Fahrzeughandel einfügen:</label><br>
            <textarea id="import_data" name="import_data" class="import-textarea" required></textarea><br>
            <button type="submit" class="btn-primary">Gebrauchtwagen importieren</button>
        </form>
    </div>
</body>
</html>