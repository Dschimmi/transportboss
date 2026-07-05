<?php
declare(strict_types=1); // Typsicherheit (PH 1.1.3.1)

/**
 * Modell-Klasse für einen geografischen Standort (PH 2.1.5.1)[cite: 3]
 */
class City
{
    private int $id;
    private string $name;

    public function __construct(int $id, string $name)
    {
        $this->id = $id;
        $this->name = $name;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }
}