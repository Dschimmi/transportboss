<?php
declare(strict_types=1);

/**
 * DriverRepository: Kapselt alle Datenbank-Operationen für die Fahrer[cite: 3].
 */
class DriverRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Speichert einen neuen Fahrer oder aktualisiert seine Qualifikationsdaten (Upsert)[cite: 3].
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

        // Die Stammdaten (Name, Alter) werden bei einem Update nicht überschrieben, 
        // da diese sich im Spiel nicht verändern, wohl aber die Qualifikationen (PH 2.3.5.2)[cite: 3].
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
}