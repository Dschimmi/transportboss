<?php
declare(strict_types=1);

/**
 * personnel.php
 *
 * Import-Schnittstelle für den Stellenmarkt. Verarbeitet einkopierten HTML-Quellcode,
 * extrahiert strukturierte Profile von Fahrern sowie Disponenten über die Parser-Logik
 * und schreibt diese persistent in die entsprechenden Datenbankstrukturen.
 *
 * @author TransportBoss Development
 * @version 1.1.2
 */

// Zentrale Abhängigkeiten laden
require_once 'db_connect.php';
require_once 'classes/Driver.php';
require_once 'classes/DriverRepository.php';
require_once 'classes/PersonnelParser.php';

// Expliziter Import der Namespaces für die Typsicherheit
use classes\PersonnelParser;

// Globale Statusvariablen für das UI-Feedback initialisieren
$message = '';
$messageClass = '';

/**
 * Prüfen, ob ein valider Daten-Import via POST-Request initiiert wurde.
 * Erwartet den einkopierten HTML-Text der Stellenmarkt-Anzeigen im Feld 'import_data'.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['import_data'])) {
    $htmlData = $_POST['import_data'];
    
    try {
        // Parser instanziieren und HTML-Struktur analysieren
        $parser = new PersonnelParser();
        $parsedPersonnel = $parser->parse($htmlData);
        
        // Repository zur Abwicklung der Fahrer-Datenbanktransaktionen laden
        $driverRepo = new DriverRepository($pdo);
        
        // Zähler für statistische Rückmeldung im UI
        $importedDrivers = 0;
        $importedDispatchers = 0;
        $otherPersonnel = 0;
        
        /**
         * Iterative Verarbeitung aller vom Parser erfassten Personen-Datensätze.
         * Filterung und Zuordnung zu den entsprechenden Datenbank-Tabellen.
         */
        foreach ($parsedPersonnel as $person) {
            $jobTitle = strtolower($person['job_title']);
            
            // Verarbeitungs-Pfad A: Fahrerprofile
            if ($jobTitle === 'fahrer') {
                // Instanziierung des Fahrer-Objekts nach dem Domänen-Modell
                $driver = new Driver(
                    $person['ingame_id'],
                    $person['first_name'],
                    $person['last_name'],
                    $person['age'],
                    $person['skill_val'],
                    $person['reliability_val'],
                    (bool)$person['adr_permit'], // Expliziter Cast auf bool zur Vermeidung von Typen-Mismatches
                    $person['penalty_points'],
                    $person['salary'],
                    false // Korrektur: is_employed = false, da es sich um eine Bewerbung handelt
                );
                
                // Fahrer-Datensatz via Repository persistent schreiben oder aktualisieren (Upsert)
                $driverRepo->save($driver);
                $importedDrivers++;
                
            // Verarbeitungs-Pfad B: Disponentenprofile
            } elseif ($jobTitle === 'disponent') {
                // Prepared Statement zur sicheren Abwicklung des Disponenten-Upserts (SQL-Injection-Schutz)
                $stmtDisp = $pdo->prepare("
                    INSERT INTO dispatchers (ingame_dispatcher_id, first_name, last_name, age, skill_val, reliability_val, salary, is_employed)
                    VALUES (:ingame_id, :first, :last, :age, :skill, :reliability, :salary, 0)
                    ON DUPLICATE KEY UPDATE 
                        first_name = :first,
                        last_name = :last,
                        age = :age,
                        skill_val = :skill,
                        reliability_val = :reliability,
                        salary = :salary
                ");
                
                // Parameter binden und query ausführen
                $stmtDisp->execute([
                    'ingame_id' => $person['ingame_id'],
                    'first' => $person['first_name'],
                    'last' => $person['last_name'],
                    'age' => (int)$person['age'],
                    'skill' => (int)$person['skill_val'], // Verwaltungsskill
                    'reliability' => (int)$person['reliability_val'],
                    'salary' => (float)$person['salary']
                ]);
                $importedDispatchers++;
                
            // Verarbeitungs-Pfad C: Nicht unterstützte Personalrollen
            } else {
                $otherPersonnel++;
            }
        }
        
        // Erfolgsmeldung für die View zusammenbauen
        $message = "Import beendet! {$importedDrivers} Fahrer-Bewerber und {$importedDispatchers} Disponenten-Bewerber erfolgreich verarbeitet.";
        $messageClass = "status-success";
        
    } catch (Exception $e) {
        // Fehlerbehandlung: Ausnahmen abfangen und kontrolliert im UI anzeigen
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
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <?php require_once 'nav.php'; ?>
    <div class="fluid-container" style="max-width: 1000px; margin: 0 auto;">
        <h1 class="accent-text">Stellenmarkt Import</h1>
        
        <!-- Feedback-Meldungen für den Anwender -->
        <?php if ($message): ?>
            <div class="feedback-msg <?= $messageClass ?>"><?= $message ?></div>
        <?php endif; ?>
        
        <!-- Importformular -->
        <form method="post" action="personnel.php">
            <label for="import_data">HTML-Quelltext aus dem Stellenmarkt einfügen (Fahrer & Disponenten):</label><br>
            <textarea id="import_data" name="import_data" class="import-textarea" required></textarea><br>
            <button type="submit" class="btn-primary">Personal importieren</button>
        </form>
    </div>
</body>
</html>