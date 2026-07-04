<?php
declare(strict_types=1); // Typsicherheit (PH 1.1.3.1)[cite: 3]

// Einbinden der benötigten Ressourcen[cite: 3]
require_once 'db_connect.php';
require_once 'classes/Driver.php';
require_once 'classes/DriverRepository.php';
require_once 'classes/PersonnelParser.php';

$message = '';
$messageClass = '';

// POST-Request verarbeiten[cite: 3]
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['import_data'])) {
    $htmlData = $_POST['import_data'];
    
    try {
        // Parser instanziieren und alle Personaldaten extrahieren[cite: 3]
        $parser = new PersonnelParser();
        $parsedPersonnel = $parser->parse($htmlData);
        
        $driverRepo = new DriverRepository($pdo);
        $importedDrivers = 0;
        $otherPersonnel = 0;
        
        // Iteration über alle gefundenen Personen[cite: 3]
        foreach ($parsedPersonnel as $person) {
            // Aktuell speichern wir nur "Fahrer" in der DB (PH 1.3.3.1)[cite: 3]
            if (strtolower($person['job_title']) === 'fahrer') {
                $driver = new Driver(
                    $person['ingame_id'],
                    $person['first_name'],
                    $person['last_name'],
                    $person['age'],
                    $person['skill_val'],
                    $person['reliability_val'],
                    $person['adr_permit'],
                    $person['penalty_points'],
                    $person['salary'],
                    true // is_employed[cite: 3]
                );
                
                // Fahrer in die Datenbank schreiben (Upsert)[cite: 3]
                $driverRepo->save($driver);
                $importedDrivers++;
            } else {
                $otherPersonnel++;
            }
        }
        
        // Erfolgsmeldung für das UI vorbereiten (PH 1.3.5.2)[cite: 3]
        $message = "Import erfolgreich! $importedDrivers Fahrer verarbeitet. ($otherPersonnel weiteres Personal gefunden, aber ignoriert).";
        $messageClass = "status-success";
        
    } catch (Exception $e) {
        // Fehler abfangen und kontrolliert ausgeben (PH 1.3.5.1)[cite: 3]
        $message = "Fehler beim Import: " . htmlspecialchars($e->getMessage());
        $messageClass = "status-error";
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Personal-Import - TransportBoss</title>
    <!-- Zentrales Styling (PH 1.3.2.2)[cite: 3] -->
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <?php require_once 'nav.php'; ?>
    <div class="fluid-container" style="max-width: 1000px; margin: 0 auto;">
        <h1 class="accent-text">Stellenmarkt Import</h1>
        
        <?php if ($message): ?>
            <div class="feedback-msg <?= $messageClass ?>"><?= $message ?></div>
            
            <!-- Dynamische Tabelle zur Kontrolle der geparsten Daten -->
            <?php if (!empty($parsedPersonnel) && $messageClass === 'status-success'): ?>
                <div style="margin-bottom: 20px; overflow-x: auto;">
                    <h3 class="accent-text" style="font-size: 1em; margin-bottom: 10px;">Kontrolle: Geparste Personaldaten</h3>
                    <table class="data-table" style="font-size: 0.85em; white-space: nowrap;">
                        <thead>
                            <tr>
                                <?php 
                                // Spaltenköpfe dynamisch aus dem ersten Element generieren (Objekt oder Array)
                                $firstItem = $parsedPersonnel[0];
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
                            <?php foreach ($parsedPersonnel as $item): ?>
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
        
        <form method="post" action="personnel.php">
            <label for="import_data">HTML-Quelltext aus dem Stellenmarkt einfügen:</label><br>
            <textarea id="import_data" name="import_data" class="import-textarea" required></textarea><br>
            <button type="submit" class="btn-primary">Personal importieren</button>
        </form>
    </div>
</body>
</html>