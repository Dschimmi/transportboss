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
    /**
     * Speichert einen neuen Auftrag oder aktualisiert bestehende Daten (Upsert).
     *
     * @param array|Order $orderData Assoziatives Array oder Order-Objekt mit Auftragsdaten
     * @return void
     */
    public function save($orderData): void
    {
        // Falls ein Order-Objekt übergeben wurde, in ein Array konvertieren
        if ($orderData instanceof Order) {
            $orderData = [
                'ingame_order_id'   => $orderData->getIngameOrderId(),
                'freight_type'      => $orderData->getFreightType(),
                'commodity'         => $orderData->getCommodity(),
                'is_adr'            => $orderData->isAdr(),
                'weight_total'      => $orderData->getWeightTotal(),
                'weight_remaining'  => $orderData->getWeightRemaining(),
                'revenue'           => $orderData->getRevenue(),
                'from_city_id'      => $orderData->getFromCityId(),
                'to_city_id'        => $orderData->getToCityId(),
                'is_accepted'       => $orderData->isAccepted(),
                'is_archived'       => $orderData->isArchived(),
                'assigned_truck_id' => $orderData->getAssignedTruckId(),
                'assigned_at'       => $orderData->getAssignedAt()?->format('Y-m-d H:i:s'),
            ];
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO orders (
                ingame_order_id, freight_type, commodity, is_adr, weight_total, weight_remaining,
                revenue, from_city_id, to_city_id, is_accepted, is_archived, assigned_truck_id,
                assigned_at, last_seen_at
            ) VALUES (
                :ingame_order_id, :freight_type, :commodity, :is_adr, :weight_total, :weight_remaining,
                :revenue, :from_city_id, :to_city_id, :is_accepted, :is_archived, :assigned_truck_id,
                :assigned_at, :last_seen_at
            ) ON DUPLICATE KEY UPDATE
                weight_remaining = VALUES(weight_remaining),
                last_seen_at = VALUES(last_seen_at),
                is_archived = 0
        ");

        $stmt->execute([
            'ingame_order_id' => $orderData['ingame_order_id'] ?? null,
            'freight_type' => $orderData['freight_type'] ?? null,
            'commodity' => $orderData['commodity'] ?? null,
            'is_adr' => (int)($orderData['is_adr'] ?? false),
            'weight_total' => (int)($orderData['weight_total'] ?? 0),
            'weight_remaining' => (int)($orderData['weight_remaining'] ?? 0),
            'revenue' => (float)($orderData['revenue'] ?? 0),
            'from_city_id' => (int)($orderData['from_city_id'] ?? 0),
            'to_city_id' => (int)($orderData['to_city_id'] ?? 0),
            'is_accepted' => (int)($orderData['is_accepted'] ?? false),
            'is_archived' => (int)($orderData['is_archived'] ?? false),
            'assigned_truck_id' => $orderData['assigned_truck_id'] ?? null,
            'assigned_at' => $orderData['assigned_at'] ?? null,
            'last_seen_at' => date('Y-m-d H:i:s')
        ]);
    }
    /**
     * Überführt einen Auftrag manuell in das Archiv (PH 4.5.5)
     * 
     * @param int $orderId Die technische Datenbank-ID
     * @return bool
     */
    public function archiveOrder(int $orderId): bool {
        $stmt = $this->pdo->prepare("
            UPDATE orders 
            SET is_archived = 1, 
                completed_at = NOW(),
                assigned_truck_id = NULL 
            WHERE id = ?
        ");
        return $stmt->execute([$orderId]);
    }
}