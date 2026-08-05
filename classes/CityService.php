<?php
declare(strict_types=1);

/**
 * CityService: Verwaltet Lese- und Schreibzugriffe auf die Tabelle cities[cite: 3]
 */
class CityService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Prüft zentral und systemweit (DRY), ob ein String ein valider Städtename ist.
     * Schließt IDN-Nummern, reine Zahlen, Geldbeträge und Ingame-Schlüsselwörter aus.
     */
    public static function isValidCityName(string $name): bool
    {
        $clean = trim($name);
        if ($clean === '' || mb_strlen($clean) < 2) {
            return false;
        }

        if (preg_match('/^IDN/i', $clean) 
            || is_numeric($clean) 
            || preg_match('/(Zahlung|Gefahrgut|Anfahrt|VOR ORT|Kurier|Stückgut|Schüttgut|Pritsche|Plane|Koffer|Kühlwagen|Silo|Tankwagen|Schwertransport|ISO-Container|Super-Liner)/i', $clean)) {
            return false;
        }

        return true;
    }

    /**
     * Löst einen Stadtnamen in eine ID auf. Legt die Stadt bei Bedarf neu an (PH 3.2.2.2)[cite: 3].
     *
     * @param string $name Der Name der Stadt aus dem Spiel-Text
     * @param bool $autoCreate Wenn true, wird die Stadt bei Nichtexistenz in der Datenbank angelegt
     * @return int|null Die Datenbank-ID der Stadt, oder null falls nicht gefunden und autoCreate=false
     */
    public function resolveId(string $name, bool $autoCreate = true): ?int
    {
        // 0. BEREINIGUNG: Tabulatoren, Zeilenumbrüche & UTF-8 Codierungsfehler abfangen
        $cleanName = trim(explode("\t", $name)[0]);
        $cleanName = str_replace(["\r", "\n", "\t"], '', $cleanName);
        $cleanName = mb_convert_encoding($cleanName, 'UTF-8', 'UTF-8, ISO-8859-1, WINDOWS-1252');

        $countryCode = 'DE';

        // Dynamische Abfrage der Präfixe aus der DB-Tabelle countries
        $stmtCountries = $this->pdo->query("SELECT country_code, prefix_name FROM countries");
        if ($stmtCountries) {
            $countries = $stmtCountries->fetchAll(PDO::FETCH_ASSOC);
            foreach ($countries as $c) {
                $prefixWithSpace = $c['prefix_name'] . ' ';
                if (str_starts_with($cleanName, $prefixWithSpace)) {
                    $countryCode = $c['country_code'];
                    $cleanName = trim(substr($cleanName, strlen($prefixWithSpace)));
                    break;
                }
            }
        }

        // 1. Suche nach existierender Stadt
        $stmt = $this->pdo->prepare("SELECT id FROM cities WHERE name = :name");
        $stmt->execute(['name' => $cleanName]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            return (int)$id;
        }

        // 2. Stadt existiert nicht -> Neu anlegen mit Ländercode
        if ($autoCreate) {
            if (mb_strlen($cleanName) < 2) {
                return null; 
            }

            $stmtInsert = $this->pdo->prepare("INSERT INTO cities (name, country_code) VALUES (:name, :cc)");
            $stmtInsert->execute(['name' => $cleanName, 'cc' => $countryCode]);
            
            return (int)$this->pdo->lastInsertId();
        }

        return null;
    }

    /**
     * Lädt den Ländercode einer Stadt dynamisch aus der Tabelle cities.
     */
    public function getCountryCode(int $cityId): string
    {
        $stmt = $this->pdo->prepare("SELECT country_code FROM cities WHERE id = ?");
        $stmt->execute([$cityId]);
        $code = $stmt->fetchColumn();
        return $code ? (string)$code : 'DE';
    }

    /**
     * Lädt ein City-Objekt anhand seiner technischen ID
     *
     * @param int $id Die technische Primärschlüssel-ID
     * @return City|null Das initialisierte Objekt oder null bei Fehler
     */
    public function getCityById(int $id): ?City
    {
        $stmt = $this->pdo->prepare("SELECT id, name FROM cities WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return new City((int)$row['id'], $row['name']);
        }

        return null;
    }
    /**
     * Ermittelt alle lizensierten Städte, die aktuell über 0 aktive, 
     * unverplante Lager-Aufträge verfügen (Mangel-Städte / FEHLT).
     *
     * KORREKTUR: Vollständig gekapselt zur Vermeidung von SQL-Redundanz (PH § 1.3.1)
     *
     * @return array Liste der Städtenamen [string]
     */
    public function getEmptyWarehouseCities(): array
    {
        $stmt = $this->pdo->query("
            SELECT c.name 
            FROM cities c
            LEFT JOIN orders o ON c.id = o.from_city_id 
              AND o.is_accepted = 1 
              AND o.is_archived = 0 
              AND o.assigned_truck_id IS NULL
            GROUP BY c.id
            HAVING COUNT(o.id) = 0
        ");
        
        return $stmt ? array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name') : [];
    }

    /**
     * Prüft mathematisch exakt (K_X < N - 1), ob für eine Stadt unvollständige Matrix-Daten vorliegen.
     *
     * @param int $cityId Technische ID der Stadt
     * @return bool True, wenn mindestens eine Verbindung zu allen anderen Städten in distances fehlt
     */
    public function hasIncompleteMatrix(int $cityId): bool
    {
        // 1. Gesamtzahl N aller registrierten Städte ermitteln
        $totalCities = (int)$this->pdo->query("SELECT COUNT(*) FROM cities")->fetchColumn();
        if ($totalCities <= 1) {
            return false;
        }

        // 2. Anzahl K_X aller erfassten Verbindungen für diese Stadt in distances ermitteln
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) 
            FROM distances 
            WHERE city_a_id = :id OR city_b_id = :id
        ");
        $stmt->execute(['id' => $cityId]);
        $recordedConnections = (int)$stmt->fetchColumn();

        // 3. Unvollständig, wenn weniger als N - 1 Verbindungen vorliegen
        return $recordedConnections < ($totalCities - 1);
    }
}