<?php
declare(strict_types=1); // Typsicherheit (PH 1.1.3.1)[cite: 3]

/**
 * Modell-Klasse für ein Fahrzeug (LKW) gemäß PH 2.4[cite: 3].
 */
class Truck
{
    public function __construct(
        private string $ingameVehicleId,
        private string $vehicleType,
        private int $capacityT,
        private int $yearBuilt,
        private int $kmStand,
        private int $currentCityId,
        private bool $hasTuningMotor = false,
        private bool $hasTuningAero = false,
        private bool $hasTuningStau = false,
        private bool $isActivePlanning = false,
        private bool $isFocussed = false,
        private ?string $userLabel = null,
        private ?int $id = null
    ) {}

    // Getter-Methoden
    public function getId(): ?int { return $this->id; }
    public function getIngameVehicleId(): string { return $this->ingameVehicleId; }
    public function getVehicleType(): string { return $this->vehicleType; }
    public function getCapacityT(): int { return $this->capacityT; }
    public function getYearBuilt(): int { return $this->yearBuilt; }
    public function getKmStand(): int { return $this->kmStand; }
    public function getCurrentCityId(): int { return $this->currentCityId; }
    public function hasTuningMotor(): bool { return $this->hasTuningMotor; }
    public function hasTuningAero(): bool { return $this->hasTuningAero; }
    public function hasTuningStau(): bool { return $this->hasTuningStau; }
    public function isActivePlanning(): bool { return $this->isActivePlanning; }
    public function isFocussed(): bool { return $this->isFocussed; }
    public function getUserLabel(): ?string { return $this->userLabel; }

    /**
     * Gibt die Ingame-Fahrer-ID des zugewiesenen Fahrers zurück (PH 2.4).
     * Unterstützt sowohl CamelCase- als auch snake_case-Eigenschaften.
     *
     * @return string|null Die Ingame-ID des Fahrers oder null
     */
    public function getAssignedDriverId(): ?string
    {
        if (isset($this->assigned_driver_id)) {
            return (string)$this->assigned_driver_id;
        }
        if (isset($this->assignedDriverId)) {
            return (string)$this->assignedDriverId;
        }
        return null;
    }
}