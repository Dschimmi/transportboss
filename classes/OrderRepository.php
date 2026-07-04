<?php
declare(strict_types=1);

/**
 * OrderRepository: Kapselt alle Datenbank-Operationen für Aufträge.
 */
class OrderRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Lädt alle offenen Aufträge (nicht archiviert, nicht zugewiesen).
     *
     * @return int Anzahl der offenen Aufträge
     */
    public function getOpenOrdersCount(): int
    {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) AS count
            FROM orders
            WHERE is_archived = 0
            AND (assigned_truck_id IS NULL OR assigned_truck_id = 0)
        ");
        return (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }

    /**
     * Berechnet den gesamten Umsatz aller Aufträge.
     *
     * @return float Gesamterlös
     */
    public function getTotalRevenue(): float
    {
        $stmt = $this->pdo->query("SELECT SUM(revenue) AS total FROM orders WHERE is_archived = 0");
        return (float)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    }

    /**
     * Lädt den letzten Auftrag für ein Fahrzeug (für virtuelles Tourende).
     *
     * @param int $truckId Die Fahrzeug-ID
     * @return array|null Assoziatives Array des letzten Auftrags oder NULL
     */
    public function getLastOrderForTruck(int $truckId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT o.*
            FROM orders o
            WHERE o.assigned_truck_id = :truck_id
            AND o.is_archived = 0
            ORDER BY o.assigned_at DESC
            LIMIT 1
        ");
        $stmt->execute(['truck_id' => $truckId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Weist einen Auftrag einem Fahrzeug zu.
     *
     * @param int $orderId Die Auftrags-ID
     * @param int $truckId Die Fahrzeug-ID
     * @return void
     */
    public function assignToTruck(int $orderId, int $truckId): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE orders
            SET assigned_truck_id = :truck_id, assigned_at = NOW(), is_accepted = 1
            WHERE id = :order_id
        ");
        $stmt->execute([
            'truck_id' => $truckId,
            'order_id' => $orderId
        ]);
    }

    /**
     * Entfernt einen Auftrag von einem Fahrzeug und löst Kaskaden-Storno aus.
     *
     * @param int $orderId Die Auftrags-ID
     * @return void
     */
    public function unassignFromTruck(int $orderId): void
    {
        // 1. Auftrag entladen
        $stmt = $this->pdo->prepare("
            UPDATE orders
            SET assigned_truck_id = NULL, assigned_at = NULL
            WHERE id = :order_id
        ");
        $stmt->execute(['order_id' => $orderId]);

        // 2. Kaskaden-Storno: Alle nachfolgenden Aufträge desselben Fahrzeugs löschen
        $truckId = $this->pdo->prepare("
            SELECT assigned_truck_id FROM orders WHERE id = :order_id
        ");
        $truckId->execute(['order_id' => $orderId]);
        $truckId = $truckId->fetchColumn();

        if ($truckId) {
            $this->pdo->exec("
                UPDATE orders
                SET assigned_truck_id = NULL, assigned_at = NULL
                WHERE assigned_truck_id = $truckId
                AND assigned_at > (SELECT assigned_at FROM orders WHERE id = $orderId)
            ");
        }
    }
}