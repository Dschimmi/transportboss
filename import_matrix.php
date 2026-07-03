<?php
declare(strict_types=1); // Typsicherheit (PH 1.1.3.1)

require_once 'db_connect.php'; // Zentrale DB-Verbindung (PH 1.1.3.4)

/**
 * MatrixImporter: Liest die JS-Datei ein und befüllt cities & distances
 */
class MatrixImporter
{
    private PDO $pdo;
    private array $cityCache = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function importFromJsFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            die("Datei nicht gefunden: " . $filePath);
        }

        // 1. Dateiinhalt komplett einlesen
        $jsContent = file_get_contents($filePath);

        // 2. Rohdaten zwischen den Backticks (`) via Regex extrahieren
        preg_match('/`([^`]+)`/', $jsContent, $matches);
        if (!isset($matches[1])) {
            die("Fehler: Keine Daten in Backticks gefunden.");
        }

        // 3. Zeilenweise verarbeiten
        $lines = explode("\n", trim($matches[1]));
        $distCount = 0;

        // Prepared Statement für den Upsert der Distanzen (PH 2.2.4.3)
        $stmtDist = $this->pdo->prepare("
            INSERT INTO distances (city_a_id, city_b_id, distance_km) 
            VALUES (:a, :b, :km) 
            ON DUPLICATE KEY UPDATE distance_km = :km
        ");

        foreach ($lines as $line) {
            $line = trim($line);
            // Leere Zeilen und JS-Kommentare überspringen
            if (empty($line) || str_starts_with($line, '//')) continue;

            $parts = explode(';', $line);
            if (count($parts) !== 3) continue;

            $cityName1 = trim($parts[0]);
            $cityName2 = trim($parts[1]);
            $km = (int)trim($parts[2]);

            // Selbstreferenzen ignorieren (Entfernung 0 zu sich selbst)
            if ($cityName1 === $cityName2) continue;

            $id1 = $this->getOrCreateCity($cityName1);
            $id2 = $this->getOrCreateCity($cityName2);

            // Normalisierung der IDs: city_a_id muss kleiner als city_b_id sein (PH 2.2.1.3 & 2.2.4.1)
            $cityA = min($id1, $id2);
            $cityB = max($id1, $id2);

            $stmtDist->execute([
                'a' => $cityA,
                'b' => $cityB,
                'km' => $km
            ]);

            $distCount++;
        }

        echo "<h2>Import erfolgreich!</h2>";
        echo "<p>Städte im Cache/DB: " . count($this->cityCache) . "</p>";
        echo "<p>Importierte Distanzen: " . $distCount . "</p>";
    }

    /**
     * Löst den Stadtnamen in eine ID auf oder legt die Stadt neu an (PH 3.2.2.2).
     */
    private function getOrCreateCity(string $name): int
    {
        // Cache prüfen um DB-Abfragen zu sparen
        if (isset($this->cityCache[$name])) {
            return $this->cityCache[$name];
        }

        $stmt = $this->pdo->prepare("SELECT id FROM cities WHERE name = :name");
        $stmt->execute(['name' => $name]);
        $id = $stmt->fetchColumn();

        // Wenn Stadt nicht existiert -> Neu anlegen
        if (!$id) {
            $stmtInsert = $this->pdo->prepare("INSERT INTO cities (name) VALUES (:name)");
            $stmtInsert->execute(['name' => $name]);
            $id = (int)$this->pdo->lastInsertId();
        } else {
            $id = (int)$id;
        }

        $this->cityCache[$name] = $id;
        return $id;
    }
}

// Skript ausführen und auf die lokale js-Datei verweisen
$importer = new MatrixImporter($pdo);
$importer->importFromJsFile(__DIR__ . '/entfernungen.js');