<?php
declare(strict_types=1);

/**
 * TopologyEngine: Berechnet optimale Touren für Fahrzeuge nach der 3-Städte-Regel (PH 4.4).
 */
class TopologyEngine
{
    private PDO $pdo;
    private DistanceService $distanceService;

    public function __construct(PDO $pdo, DistanceService $distanceService)
    {
        $this->pdo = $pdo;
        $this->distanceService = $distanceService;
    }

    /**
     * Findet die besten Auftragsvorschläge für ein Fahrzeug.
     *
     * @param int $truckId Die ID des Fahrzeugs
     * @param int $currentCityId Die aktuelle Stadt-ID des Fahrzeugs
     * @return array Array mit Auftragsvorschlägen (sortiert nach Priorität)
     */
    public function getSuggestionsForTruck(int $truckId, int $currentCityId): array
    {
        // 1. Fahrzeugdaten laden
        $truckStmt = $this->pdo->prepare("SELECT capacity_t, vehicle_type FROM trucks WHERE id = :id");
        $truckStmt->execute(['id' => $truckId]);
        $truck = $truckStmt->fetch(PDO::FETCH_ASSOC);

        if (!$truck) {
            return [];
        }

        // 2. Die 2 nächstgelegenen Städte zur aktuellen Stadt finden
        $neighborCities = $this->getNearestCities($currentCityId, 2);
        $relevantCityIds = array_merge([$currentCityId], $neighborCities);

        // 3. Alle Aufträge in diesen Städten laden (Lager + Marktpool)
        $placeholders = implode(',', array_fill(0, count($relevantCityIds), '?'));
        $orderStmt = $this->pdo->prepare("
            SELECT o.*, c1.name AS from_city_name, c2.name AS to_city_name
            FROM orders o
            JOIN cities c1 ON o.from_city_id = c1.id
            JOIN cities c2 ON o.to_city_id = c2.id
            WHERE o.from_city_id IN ($placeholders)
            AND o.is_archived = 0
            AND o.assigned_truck_id IS NULL
            ORDER BY o.from_city_id = ? DESC, (o.revenue / o.weight_total) DESC
        ");

        // Füge currentCityId als letzten Parameter hinzu
        $params = array_merge($relevantCityIds, [$currentCityId]);
        $orderStmt->execute($params);
        $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Aufträge filtern (Typ, Kapazität, ADR)
        $suggestions = [];
        foreach ($orders as $order) {
            // Fahrzeugtyp prüfen
            if (!$this->isTypeCompatible($order['freight_type'], $truck['vehicle_type'])) {
                continue;
            }

            // Kapazität prüfen
            if ($order['weight_total'] > $truck['capacity_t']) {
                continue;
            }

            // ADR prüfen
            if ($order['is_adr'] && !$this->hasAdrDriverForTruck($truckId)) {
                continue;
            }

            // Slot-Prüfung für Marktaufträge
            if (!$order['is_accepted'] && !$this->hasFreeSlots()) {
                continue;
            }

            $distanceToOrder = $this->distanceService->getDistance($currentCityId, $order['from_city_id']);
            $routeDistance = $this->distanceService->getDistance($order['from_city_id'], $order['to_city_id']);
            $suggestions[] = [
                'order' => $order,
                'distance_to_order' => $distanceToOrder,
                'earning_per_tkm' => $order['revenue'] / ($order['weight_total'] * max($routeDistance, 1)),
                'is_fallback' => false,
                'status' => $order['is_accepted'] ? 'warehouse' : 'market'
            ];
        }

        // 5. Fallback: Falls keine Aufträge in den 3 Städten gefunden wurden
        if (empty($suggestions)) {
            $fallbackOrders = $this->getFallbackSuggestions($truckId, $truck['vehicle_type'], $truck['capacity_t']);
            foreach ($fallbackOrders as $fallbackOrder) {
                $distanceToOrder = $this->distanceService->getDistance($currentCityId, $fallbackOrder['from_city_id']);
                $routeDistance = $this->distanceService->getDistance($fallbackOrder['from_city_id'], $fallbackOrder['to_city_id']);
                $suggestions[] = [
                    'order' => $fallbackOrder,
                    'distance_to_order' => $distanceToOrder,
                    'earning_per_tkm' => $fallbackOrder['revenue'] / ($fallbackOrder['weight_total'] * max($routeDistance, 1)),
                    'is_fallback' => true,
                    'status' => $fallbackOrder['is_accepted'] ? 'warehouse' : 'market'
                ];
            }
        }

        // 6. Nach Priorität sortieren
        usort($suggestions, function($a, $b) {
            if ($a['distance_to_order'] !== $b['distance_to_order']) {
                return $a['distance_to_order'] <=> $b['distance_to_order'];
            }
            return $b['earning_per_tkm'] <=> $a['earning_per_tkm'];
        });

        return $suggestions;
    }

    /**
     * Findet die n nächstgelegenen Städte zu einer gegebenen Stadt.
     *
     * @param int $cityId Die Referenz-Stadt-ID
     * @param int $limit Anzahl der Nachbarstädte
     * @return array Array mit Stadt-IDs
     */
    private function getNearestCities(int $cityId, int $limit): array
    {
        $stmt = $this->pdo->prepare("
            (SELECT city_b_id AS city_id, distance_km
            FROM distances
            WHERE city_a_id = :city_id)
            UNION ALL
            (SELECT city_a_id AS city_id, distance_km
            FROM distances
            WHERE city_b_id = :city_id)
            ORDER BY distance_km ASC
            LIMIT $limit
        ");
        $stmt->execute(['city_id' => $cityId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'city_id');
    }

    /**
     * Prüft, ob ein Frachttyp mit einem Fahrzeugtyp kompatibel ist.
     */
    private function isTypeCompatible(string $freightType, string $vehicleType): bool
    {
        if ($freightType === $vehicleType) {
            return true;
        }

        // Mapping von Frachttyp (aus dem Spiel) -> Fahrzeugtyp (aus trucks.vehicle_type)
        $mapping = [
            'Plane(Wetterschutz)' => 'Plane',
            'Kühlwaren'            => 'Kühlwagen',
            'Kofferwagen'          => 'Koffer',
            'Flüssigkeiten'        => 'Tankwagen',
        ];

        if (isset($mapping[$freightType])) {
            return $mapping[$freightType] === $vehicleType;
        }

        return false;
    }

    /**
     * Gibt alle kompatiblen Frachttypen für einen Fahrzeugtyp zurück.
     */
    private function getCompatibleFreightTypes(string $vehicleType): array
    {
        $types = [$vehicleType];
        switch ($vehicleType) {
            case 'Plane':
                $types[] = 'Plane(Wetterschutz)';
                break;
            case 'Kühlwagen':
                $types[] = 'Kühlwaren';
                break;
            case 'Koffer':
                $types[] = 'Kofferwagen';
                break;
            case 'Tankwagen':
                $types[] = 'Flüssigkeiten';
                break;
        }
        return $types;
    }

    /**
     * Prüft, ob das Fahrzeug einen Fahrer mit ADR-Erlaubnis hat.
     *
     * @param int $truckId Die Fahrzeug-ID
     * @return bool
     */
    private function hasAdrDriverForTruck(int $truckId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT d.adr_permit
            FROM drivers d
            JOIN trucks t ON (d.assigned_truck_id = t.id OR t.assigned_driver_id = d.ingame_driver_id)
            WHERE t.id = :truck_id AND d.is_employed = 1
        ");
        $stmt->execute(['truck_id' => $truckId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result && $result['adr_permit'];
    }

    /**
     * Prüft, ob freie Slots für Marktaufträge verfügbar sind.
     *
     * @return bool
     */
    private function hasFreeSlots(): bool
    {
        $limitStmt = $this->pdo->query("SELECT cfg_value FROM config WHERE cfg_key = 'max_dispo_slots'");
        $globalLimit = $limitStmt ? (int)$limitStmt->fetchColumn() : 26;
        if ($globalLimit <= 0) {
            $globalLimit = 26;
        }
        $stmt = $this->pdo->query("SELECT COUNT(*) AS count FROM orders WHERE is_accepted = 1 AND is_archived = 0");
        $usedSlots = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
        return ($globalLimit - $usedSlots) > 0;
    }

    /**
     * Führt einen globalen Scan durch, falls die 3-Städte-Regel keine Ergebnisse liefert.
     *
     * @param int $truckId Die Fahrzeug-ID
     * @param string $vehicleType Der Fahrzeugtyp
     * @param int $capacity Die Kapazität des Fahrzeugs
     * @return array Array mit Fallback-Aufträgen
     */
    private function getFallbackSuggestions(int $truckId, string $vehicleType, int $capacity): array
    {
        $hasAdr = $this->hasAdrDriverForTruck($truckId);
        $compatibleTypes = $this->getCompatibleFreightTypes($vehicleType);
        
        $placeholders = [];
        $params = [
            'capacity' => $capacity,
            'has_adr' => (int)$hasAdr
        ];
        
        foreach ($compatibleTypes as $index => $type) {
            $key = 'type_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $type;
        }
        
        $placeholdersStr = implode(',', $placeholders);

        $stmt = $this->pdo->prepare("
            SELECT o.*, c1.name AS from_city_name, c2.name AS to_city_name
            FROM orders o
            JOIN cities c1 ON o.from_city_id = c1.id
            JOIN cities c2 ON o.to_city_id = c2.id
            WHERE o.is_archived = 0
            AND o.freight_type IN ($placeholdersStr)
            AND o.weight_total <= :capacity
            AND (o.is_adr = 0 OR :has_adr = 1)
            AND o.assigned_truck_id IS NULL
            ORDER BY (o.revenue / o.weight_total) DESC
            LIMIT 10
        ");
        
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}