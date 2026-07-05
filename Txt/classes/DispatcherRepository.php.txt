<?php
declare(strict_types=1);

/**
 * DispatcherRepository: Kapselt alle Datenbank-Operationen für Disponenten.
 */
class DispatcherRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Speichert einen neuen Disponenten oder aktualisiert seine Daten (Upsert).
     *
     * @param Dispatcher $dispatcher Das zu speichernde Dispatcher-Objekt
     * @return void
     */
    public function save(Dispatcher $dispatcher): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO dispatchers (
                ingame_dispatcher_id, first_name, last_name, age,
                skill_val, reliability_val, salary, is_employed
            ) VALUES (
                :ingame_id, :first_name, :last_name, :age,
                :skill, :reliability, :salary, :is_employed
            ) ON DUPLICATE KEY UPDATE
                skill_val = VALUES(skill_val),
                reliability_val = VALUES(reliability_val),
                salary = VALUES(salary),
                is_employed = VALUES(is_employed)
        ");

        $stmt->execute([
            'ingame_id'   => $dispatcher->getIngameDispatcherId(),
            'first_name'  => $dispatcher->getFirstName(),
            'last_name'   => $dispatcher->getLastName(),
            'age'         => $dispatcher->getAge(),
            'skill'       => $dispatcher->getSkillVal(),
            'reliability' => $dispatcher->getReliabilityVal(),
            'salary'      => $dispatcher->getSalary(),
            'is_employed' => (int)$dispatcher->isEmployed()
        ]);
    }

    /**
     * Lädt alle aktuell eingestellten Disponenten.
     *
     * @return array Assoziatives Array aller aktiven Disponenten
     */
    public function getAllEmployed(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM dispatchers WHERE is_employed = 1");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Entlässt einen Disponenten (setzt is_employed auf FALSE).
     *
     * @param int $dispatcherId Die ID des zu entlassenden Disponenten
     * @return void
     */
    public function dismiss(int $dispatcherId): void
    {
        $stmt = $this->pdo->prepare("UPDATE dispatchers SET is_employed = 0 WHERE id = :id");
        $stmt->execute(['id' => $dispatcherId]);
    }

    /**
     * Lädt einen Disponenten anhand seiner Ingame-ID.
     *
     * @param string $ingameId Die Ingame-ID des Disponenten
     * @return array|null Assoziatives Array des Disponenten oder NULL, falls nicht gefunden
     */
    public function getByIngameId(string $ingameId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM dispatchers WHERE ingame_dispatcher_id = :ingame_id");
        $stmt->execute(['ingame_id' => $ingameId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}