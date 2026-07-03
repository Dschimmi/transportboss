<?php
declare(strict_types=1);

/**
 * Modell-Klasse für einen Fahrer (Personal) gemäß PH 2.3.
 */
class Driver
{
    public function __construct(
        private string $ingameDriverId,
        private string $firstName,
        private string $lastName,
        private int $age,
        private int $skillVal,
        private int $reliabilityVal,
        private bool $adrPermit,
        private int $penaltyPoints,
        private float $salary,
        private bool $isEmployed = true,
        private ?int $id = null,
        private ?int $assignedTruckId = null
    ) {}

    // Getter-Methoden
    public function getId(): ?int { return $this->id; }
    public function getIngameDriverId(): string { return $this->ingameDriverId; }
    public function getFirstName(): string { return $this->firstName; }
    public function getLastName(): string { return $this->lastName; }
    public function getAge(): int { return $this->age; }
    public function getSkillVal(): int { return $this->skillVal; }
    public function getReliabilityVal(): int { return $this->reliabilityVal; }
    public function hasAdrPermit(): bool { return $this->adrPermit; }
    public function getPenaltyPoints(): int { return $this->penaltyPoints; }
    public function getSalary(): float { return $this->salary; }
    public function isEmployed(): bool { return $this->isEmployed; }
    public function getAssignedTruckId(): ?int { return $this->assignedTruckId; }
}