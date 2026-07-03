<?php
declare(strict_types=1);

/**
 * DistanceService: Verwaltet Lese- und Schreibzugriffe auf die Entfernungs-Matrix[cite: 3]
 */
class DistanceService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Normalisiert die IDs: Garantiert, dass $min < $max für redundanzfreie Abfragen[cite: 3]
     *
     * @param int $id1
     * @param int $id2
     * @return array Array mit [min, max]
     */
    private function normalize(int $id1, int $id2): array
    {
        return [min($id1, $id2), max($id1, $id2)];
    }

    /**
     * Ermittelt die Distanz zwischen zwei Städten[cite: 3]
     *
     * @param int $id1
     * @param int $id2
     * @return int Distanz in KM (0 bei identischen IDs, 999 als Fallback)[cite: 3]
     */
    public function getDistance(int $id1, int $id2): int
    {
        // 1. Identitäts-Prüfung ohne DB-Abfrage[cite: 3]
        if ($id1 === $id2) {
            return 0; 
        }

        [$cityA, $cityB] = $this->normalize($id1, $id2);

        $stmt = $this->pdo->prepare("SELECT distance_km FROM distances WHERE city_a_id = :a AND city_b_id = :b");
        $stmt->execute(['a' => $cityA, 'b' => $cityB]);
        
        $result = $stmt->fetchColumn();

        // 2. Existenzprüfung mit Fallback-Wert 999 für den Algorithmus[cite: 3]
        return $result !== false ? (int)$result : 999;
    }

    /**
     * Speichert oder überschreibt eine Distanz in der Matrix[cite: 3]
     *
     * @param int $id1
     * @param int $id2
     * @param int $km
     * @throws InvalidArgumentException Wenn die Distanz negativ ist[cite: 3]
     */
    public function setDistance(int $id1, int $id2, int $km): void
    {
        // 1. Bereichsprüfung[cite: 3]
        if ($km < 0) {
            throw new InvalidArgumentException("Distanz darf nicht negativ sein.");
        }

        if ($id1 === $id2) {
            return; // Keine Selbstreferenzen in der Matrix speichern
        }

        [$cityA, $cityB] = $this->normalize($id1, $id2);

        // 2. Upsert-Verfahren zur Vermeidung von Dubletten-Fehlern[cite: 3]
        $stmt = $this->pdo->prepare("
            INSERT INTO distances (city_a_id, city_b_id, distance_km) 
            VALUES (:a, :b, :km) 
            ON DUPLICATE KEY UPDATE distance_km = :km
        ");
        
        $stmt->execute(['a' => $cityA, 'b' => $cityB, 'km' => $km]);
    }
}