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

    // Getter (Auszug für die wichtigsten Operationen)
    public function getIngameOrderId(): ?string { return $this->ingameOrderId; }
    public function getFingerprint(): string { return $this->fingerprint; }
    public function isAdr(): bool { return $this->isAdr; }
    public function getRevenue(): float { return $this->revenue; }
    // ... weitere Getter nach Bedarf
}