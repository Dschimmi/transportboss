<?php
declare(strict_types=1);

/**
 * Modell-Klasse für einen Auftrag (PH 2.5).
 */
class Order
{
    public function __construct(
        private ?string $ingameOrderId,
        private string $fingerprint,
        private string $freightType,
        private string $commodity,
        private bool $isAdr,
        private int $weightTotal,
        private int $weightRemaining,
        private float $revenue,
        private int $fromCityId,
        private int $toCityId,
        private bool $isAccepted = false,
        private bool $isArchived = false,
        private ?int $id = null,
        private ?int $assignedTruckId = null,
        private ?DateTime $assignedAt = null
    ) {}

    // Getter für alle Eigenschaften
    public function getIngameOrderId(): ?string { return $this->ingameOrderId; }
    public function getFingerprint(): string { return $this->fingerprint; }
    public function getFreightType(): string { return $this->freightType; }
    public function getCommodity(): string { return $this->commodity; }
    public function isAdr(): bool { return $this->isAdr; }
    public function getWeightTotal(): int { return $this->weightTotal; }
    public function getWeightRemaining(): int { return $this->weightRemaining; }
    public function getRevenue(): float { return $this->revenue; }
    public function getFromCityId(): int { return $this->fromCityId; }
    public function getToCityId(): int { return $this->toCityId; }
    public function isAccepted(): bool { return $this->isAccepted; }
    public function isArchived(): bool { return $this->isArchived; }
    public function getId(): ?int { return $this->id; }
    public function getAssignedTruckId(): ?int { return $this->assignedTruckId; }
    public function getAssignedAt(): ?DateTime { return $this->assignedAt; }
}