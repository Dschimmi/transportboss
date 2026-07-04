<?php
declare(strict_types=1);

/**
 * DriverRepository: Kapselt alle Datenbank-Operationen für die Fahrer.
 */
class DriverRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Speichert einen neuen Fahrer oder aktualisiert seine Qualifikationsdaten (Upsert).
     *
     * @param Driver $driver Das zu speichernde Fahrer-Objekt
     * @return void
     */
    public function save(Driver $driver): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO drivers (
                ingame_driver_id, first_name, last_name, age,
                skill_val, reliability_val, adr_permit, penalty_points, salary, is_employed
            ) VALUES (
                :ingame_id, :first_name, :last_name, :age,
                :skill, :reliability, :adr, :penalty, :salary, :is_employed
            ) ON DUPLICATE KEY UPDATE
                skill_val = :skill_update,
                reliability_val = :reliability_update,
                salary = :salary_update,
                penalty_points = :penalty_update,
                adr_permit = :adr_update
        ");

        $stmt->execute([
            'ingame_id'   => $driver->getIngameDriverId(),
            'first_name'  => $driver->getFirstName(),
            'last_name'   => $driver->getLastName(),
            'age'         => $driver->getAge(),
            'skill'       => $driver->getSkillVal(),
            'reliability' => $driver->getReliabilityVal(),
            'adr'         => (int)$driver->hasAdrPermit(),
            'penalty'     => $driver->getPenaltyPoints(),
            'salary'      => $driver->getSalary(),
            'is_employed' => (int)$driver->isEmployed(),

            // Update-Parameter für ON DUPLICATE KEY UPDATE
            'skill_update'       => $driver->getSkillVal(),
            'reliability_update' => $driver->getReliabilityVal(),
            'salary_update'      => $driver->getSalary(),
            'penalty_update'     => $driver->getPenaltyPoints(),
            'adr_update'         => (int)$driver->hasAdrPermit()
        ]);
    }

    /**
     * Lädt alle aktuell eingestellten Fahrer.
     *
     * @return array Assoziatives Array aller aktiven Fahrer
     */
    public function getAllEmployed(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM drivers WHERE is_employed = 1");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lädt einen Fahrer anhand seiner ID.
     *
     * @param int|string $id Die Fahrer-ID (kann int oder string sein)
     * @return array|null Assoziatives Array des Fahrers oder NULL
     */
    public function getById($id): ?array
    {
        if (is_int($id)) {
            $stmt = $this->pdo->prepare("SELECT * FROM drivers WHERE id = :id");
            $stmt->execute(['id' => $id]);
        } else {
            $stmt = $this->pdo->prepare("SELECT * FROM drivers WHERE ingame_driver_id = :ingame_id");
            $stmt->execute(['ingame_id' => $id]);
        }
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}