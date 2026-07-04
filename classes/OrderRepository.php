<?php
declare(strict_types=1);

class OrderRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Speichert einen Auftrag (PH 3.4.2) mit Fingerprint-Dubletten-Schutz[cite: 3].
     */
    public function save(Order $order): void
    {
        // 1. Aktiv-Prüfung: Existiert bereits ein identischer, nicht archivierter Auftrag?[cite: 3]
        $stmt = $this->pdo->prepare("SELECT id FROM orders WHERE fingerprint = :fp AND is_archived = 0 LIMIT 1");
        $stmt->execute(['fp' => $order->getFingerprint()]);
        
        if ($stmt->fetch()) {
            // Treffer: Nur last_seen_at aktualisieren (PH 3.4.2.3.1)[cite: 3]
            $update = $this->pdo->prepare("UPDATE orders SET last_seen_at = CURRENT_TIMESTAMP WHERE fingerprint = :fp AND is_archived = 0");
            $update->execute(['fp' => $order->getFingerprint()]);
            return;
        }

        // 2. Kein Treffer: Neu anlegen[cite: 3]
        $insert = $this->pdo->prepare("
            INSERT INTO orders (
                ingame_order_id, fingerprint, freight_type, commodity, is_adr, 
                weight_total, weight_remaining, revenue, from_city_id, to_city_id, 
                is_accepted, last_seen_at
            ) VALUES (
                :idn, :fp, :type, :com, :adr, 
                :w_tot, :w_rem, :rev, :from, :to, 
                :acc, CURRENT_TIMESTAMP
            )
        ");

        $insert->execute([
            'idn'   => $order->getIngameOrderId(),
            'fp'    => $order->getFingerprint(),
            'type'  => $order->getFreightType(),
            'com'   => $order->getCommodity(),
            'adr'   => (int)$order->isAdr(),
            'w_tot' => $order->getWeightTotal(),
            'w_rem' => $order->getWeightRemaining(),
            'rev'   => $order->getRevenue(),
            'from'  => $order->getFromCityId(),
            'to'    => $order->getToCityId(),
            'acc'   => (int)$order->isAccepted()
        ]);
    }

    /**
     * Synchronisiert einen Lager-Auftrag mit der Datenbank (PH 3.4.4)[cite: 3].
     */
    public function syncWarehouseOrder(Order $warehouseOrder): bool
    {
        // 1. Prüfen, ob der Auftrag schon über seine feste IDN bekannt ist
        $stmt = $this->pdo->prepare("SELECT id FROM orders WHERE ingame_order_id = :idn LIMIT 1");
        $stmt->execute(['idn' => $warehouseOrder->getIngameOrderId()]);
        $existingId = $stmt->fetchColumn();

        // 2. Heuristik-Match: Suchen des ursprünglichen Pool-Auftrags (PH 3.4.2)[cite: 3]
        if (!$existingId) {
            $stmtMatch = $this->pdo->prepare("
                SELECT id FROM orders 
                WHERE is_archived = 0 
                  AND ingame_order_id IS NULL
                  AND commodity = :com 
                  AND from_city_id = :from 
                  AND to_city_id = :to
                  AND weight_total = :w_tot
                  AND ABS(revenue - :rev) < 0.1
                LIMIT 1
            ");
            $stmtMatch->execute([
                'com'   => $warehouseOrder->getCommodity(),
                'from'  => $warehouseOrder->getFromCityId(),
                'to'    => $warehouseOrder->getToCityId(),
                'w_tot' => $warehouseOrder->getWeightTotal(),
                'rev'   => $warehouseOrder->getRevenue()
            ]);
            $existingId = $stmtMatch->fetchColumn();
        }

        // 3. Update durchführen: IDN setzen und Restgewicht aktualisieren
        if ($existingId) {
            $update = $this->pdo->prepare("
                UPDATE orders 
                SET is_accepted = 1, 
                    ingame_order_id = :idn, 
                    weight_remaining = :w_rem, 
                    last_seen_at = CURRENT_TIMESTAMP 
                WHERE id = :id
            ");
            $update->execute([
                'idn'   => $warehouseOrder->getIngameOrderId(),
                'w_rem' => $warehouseOrder->getWeightRemaining(),
                'id'    => $existingId
            ]);
            return true;
        }

        return false;
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
     * Zählt alle offenen Aufträge (nicht archiviert, nicht zugewiesen).
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
}