<?php
declare(strict_types=1);

/**
 * TruckRepository: Kapselt alle Datenbank-Operationen für die Fahrzeuge.
 */
class TruckRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Speichert einen neuen LKW oder aktualisiert seine Bewegungsdaten (Upsert).
     *
     * @param Truck $truck Das zu speichernde Fahrzeug-Objekt
     * @return void
     */
    public function save(Truck $truck): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO trucks (
                ingame_vehicle_id, user_label, vehicle_type, capacity_t, year_built,
                km_stand, current_city_id, has_tuning_motor, has_tuning_aero, has_tuning_stau,
                is_active_planning, is_focussed, assigned_driver_id
            ) VALUES (
                :ingame_id, :label, :type, :cap, :year,
                :km, :city, :motor, :aero, :stau,
                :active, :focussed, :driver_id
            ) ON DUPLICATE KEY UPDATE
                km_stand = :km_update,
                current_city_id = :city_update,
                has_tuning_motor = :motor_update,
                has_tuning_aero = :aero_update,
                has_tuning_stau = :stau_update,
                is_active_planning = :active_update,
                is_focussed = :focussed_update,
                assigned_driver_id = :driver_id_update
        ");

        $stmt->execute([
            'ingame_id'   => $truck->getIngameVehicleId(),
            'label'       => $truck->getUserLabel(),
            'type'        => $truck->getVehicleType(),
            'cap'         => $truck->getCapacityT(),
            'year'        => $truck->getYearBuilt(),
            'km'          => $truck->getKmStand(),
            'city'        => $truck->getCurrentCityId(),
            'motor'       => (int)$truck->hasTuningMotor(),
            'aero'        => (int)$truck->hasTuningAero(),
            'stau'        => (int)$truck->hasTuningStau(),
            'active'      => (int)$truck->isActivePlanning(),
            'focussed'    => (int)$truck->isFocussed(),
            'driver_id'   => $truck->getAssignedDriverId(),

            // Update-Parameter
            'km_update'       => $truck->getKmStand(),
            'city_update'     => $truck->getCurrentCityId(),
            'motor_update'    => (int)$truck->hasTuningMotor(),
            'aero_update'     => (int)$truck->hasTuningAero(),
            'stau_update'      => (int)$truck->hasTuningStau(),
            'active_update'   => (int)$truck->isActivePlanning(),
            'focussed_update'  => (int)$truck->isFocussed(),
            'driver_id_update' => $truck->getAssignedDriverId()
        ]);
    }

    /**
     * Lädt alle im Besitz befindlichen LKWs mit zugewiesenem Fahrer (disponierbar).
     * Sortiert nach: 1. Job-Anzahl (ASC), 2. Standort (A-Z), 3. Kapazität (DESC).
     *
     * @return array Array mit assoziativen Arrays aller disponiblen LKWs
     */
    public function getAllOwned(): array
    {
        $stmt = $this->pdo->query("
            SELECT
                t.*,
                c.name AS current_city_name,
                COUNT(o.id) AS job_count
            FROM trucks t
            LEFT JOIN cities c ON t.current_city_id = c.id
            LEFT JOIN orders o ON t.id = o.assigned_truck_id AND o.is_archived = 0
            WHERE t.assigned_driver_id IS NOT NULL
            GROUP BY t.id
            ORDER BY
                job_count ASC,
                c.name ASC,
                t.capacity_t DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lädt ein Fahrzeug anhand seiner ID.
     *
     * @param int $id Die Fahrzeug-ID
     * @return array|null Assoziatives Array des Fahrzeugs oder NULL
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT t.*, c.name AS current_city_name
            FROM trucks t
            LEFT JOIN cities c ON t.current_city_id = c.id
            WHERE t.id = :id
        ");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Verknüpft einen LKW mit einer Fahrer-ID (oder hebt die Zuweisung auf, wenn null).
     *
     * @param string $truckIngameId Die Ingame-ID des LKWs
     * @param string|null $driverIngameId Die Ingame-ID des Fahrers (oder null zum Entkoppeln)
     */
    public function assignDriver(string $truckIngameId, ?string $driverIngameId): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE trucks
            SET assigned_driver_id = :driver_id
            WHERE ingame_vehicle_id = :truck_id
        ");
        $stmt->execute([
            'driver_id' => $driverIngameId,
            'truck_id'  => $truckIngameId
        ]);
    }

    /**
     * Lädt alle aktiven Fahrzeuge für die Disposition (is_active_planning = 1).
     *
     * @return array Array mit assoziativen Arrays der aktiven Fahrzeuge
     */
    public function getActiveForPlanning(): array
    {
        $stmt = $this->pdo->query("
            SELECT t.*, c.name AS current_city_name
            FROM trucks t
            LEFT JOIN cities c ON t.current_city_id = c.id
            WHERE t.is_active_planning = 1 AND t.assigned_driver_id IS NOT NULL
            ORDER BY c.name ASC, t.capacity_t DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}