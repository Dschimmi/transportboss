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
     * Löst einen Stadtnamen in eine ID auf. Legt die Stadt bei Bedarf neu an (PH 3.2.2.2)[cite: 3].
     *
     * @param string $name Der Name der Stadt aus dem Spiel-Text
     * @param bool $autoCreate Wenn true, wird die Stadt bei Nichtexistenz in der Datenbank angelegt
     * @return int|null Die Datenbank-ID der Stadt, oder null falls nicht gefunden und autoCreate=false
     */
    public function resolveId(string $name, bool $autoCreate = true): ?int
    {
        // 1. Suche nach existierender Stadt (Normalisierung unnötig, da MySQL standardmäßig case-insensitive sucht)[cite: 3]
        $stmt = $this->pdo->prepare("SELECT id FROM cities WHERE name = :name");
        $stmt->execute(['name' => $name]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            return (int)$id;
        }

        // 2. Stadt existiert nicht -> Neu anlegen, falls erlaubt[cite: 3]
        if ($autoCreate) {
            // String-Integrität: Namen kürzer als 2 Zeichen abweisen (PH 3.2.2.4.2)[cite: 3]
            if (mb_strlen(trim($name)) < 2) {
                return null; 
            }

            $stmtInsert = $this->pdo->prepare("INSERT INTO cities (name) VALUES (:name)");
            $stmtInsert->execute(['name' => trim($name)]);
            
            // ID-Rückgabe der Neuanlage[cite: 3]
            return (int)$this->pdo->lastInsertId();
        }

        return null;
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
}