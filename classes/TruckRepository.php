<?php
declare(strict_types=1);

/**
 * TruckRepository: Kapselt alle Datenbank-Operationen für die Fahrzeuge[cite: 3].
 */
class TruckRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Speichert einen neuen LKW oder aktualisiert seine Bewegungsdaten (Upsert) (PH 2.4.4.3)[cite: 3].
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
                is_active_planning, is_focussed
            ) VALUES (
                :ingame_id, :label, :type, :cap, :year, 
                :km, :city, :motor, :aero, :stau, 
                :active, :focussed
            ) ON DUPLICATE KEY UPDATE 
                km_stand = :km_update,
                current_city_id = :city_update,
                has_tuning_motor = :motor_update,
                has_tuning_aero = :aero_update,
                has_tuning_stau = :stau_update
        ");

        // Stammdaten-Schutz (PH 2.4.5.1): Baujahr und Typ werden beim Update nicht überschrieben[cite: 3].
        $stmt->execute([
            'ingame_id' => $truck->getIngameVehicleId(),
            'label'     => $truck->getUserLabel(),
            'type'      => $truck->getVehicleType(),
            'cap'       => $truck->getCapacityT(),
            'year'      => $truck->getYearBuilt(),
            'km'        => $truck->getKmStand(),
            'city'      => $truck->getCurrentCityId(),
            'motor'     => (int)$truck->hasTuningMotor(),
            'aero'      => (int)$truck->hasTuningAero(),
            'stau'      => (int)$truck->hasTuningStau(),
            'active'    => (int)$truck->isActivePlanning(),
            'focussed'  => (int)$truck->isFocussed(),
            
            // Update-Parameter für ON DUPLICATE KEY UPDATE
            'km_update'    => $truck->getKmStand(),
            'city_update'  => $truck->getCurrentCityId(),
            'motor_update' => (int)$truck->hasTuningMotor(),
            'aero_update'  => (int)$truck->hasTuningAero(),
            'stau_update'  => (int)$truck->hasTuningStau()
        ]);
    }
    /**
     * Lädt alle im Besitz befindlichen LKWs.
     * 
     * @return array Assoziatives Array aller eigenen LKWs
     */
    public function getAllOwned(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM trucks");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
}