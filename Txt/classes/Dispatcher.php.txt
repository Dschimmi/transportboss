<?php
declare(strict_types=1);

/**
 * Modell-Klasse für einen Disponenten (PH 2.3. analog).
 */
class Dispatcher
{
    public function __construct(
        private string $ingameDispatcherId,
        private string $firstName,
        private string $lastName,
        private int $age,
        private int $skillVal,
        private int $reliabilityVal,
        private float $salary,
        private bool $isEmployed = true,
        private ?int $id = null
    ) {}

    // Getter-Methoden
    public function getId(): ?int { return $this->id; }
    public function getIngameDispatcherId(): string { return $this->ingameDispatcherId; }
    public function getFirstName(): string { return $this->firstName; }
    public function getLastName(): string { return $this->lastName; }
    public function getAge(): int { return $this->age; }
    public function getSkillVal(): int { return $this->skillVal; }
    public function getReliabilityVal(): int { return $this->reliabilityVal; }
    public function getSalary(): float { return $this->salary; }
    public function isEmployed(): bool { return $this->isEmployed; }
}