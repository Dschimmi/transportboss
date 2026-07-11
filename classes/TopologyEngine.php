<?php
declare(strict_types=1);

/**
 * TopologyEngine: Berechnet optimale Touren für Fahrzeuge nach der 3-Städte-Regel (PH 4.4).
 * Erweitert um das taktische Radar und die distanzoptimierte Fallback-Suche.
 *
 * @author TransportBoss Development
 * @version 2.3.0
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
     * Findet die besten Auftragsvorschläge für ein Fahrzeug (Autopilot-Modus).
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
            AND o.weight_remaining > 0
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
     * Prüft, ob ein Frachttyp mit einem Fahrzeugtyp kompatibel ist (PH 3.3 Matrix-Abgleich).
     */
    private function isTypeCompatible(string $freightType, string $vehicleType): bool
    {
        $normalize = function(string $type): string {
            $lower = strtolower($type);
            if (str_contains($lower, 'silo')) return 'Silo';
            if (str_contains($lower, 'flüssig') || str_contains($lower, 'tank')) return 'Tankwagen';
            if (str_contains($lower, 'kühl')) return 'Kühlwagen';
            if (str_contains($lower, 'schütt')) return 'Schüttgut';
            if (str_contains($lower, 'kurier')) return 'Kurier';
            if (str_contains($lower, 'pritsche')) return 'Pritsche';
            if (str_contains($lower, 'iso')) return 'ISO-Container';
            if (str_contains($lower, 'schwer')) return 'Schwertransport';
            if (str_contains($lower, 'koffer')) return 'Koffer';
            if (str_contains($lower, 'plane')) return 'Plane';
            if (str_contains($lower, 'stück')) return 'Stückgut';
            return $type;
        };

        $fType = $normalize($freightType);
        $vType = $normalize($vehicleType);

        if ($vType === $fType) {
            return true;
        }

        // Vollständige Kompatibilitätsmatrix aus dem Pflichtenheft (PH 3.3)
        $matrix = [
            'Kurier' => ['Kurier', 'Stückgut', 'Pritsche', 'Plane', 'Koffer'],
            'Stückgut' => ['Stückgut', 'Kurier', 'Pritsche', 'Plane', 'Koffer'],
            'Schüttgut' => ['Schüttgut'],
            'Pritsche' => ['Pritsche', 'Schüttgut'],
            'Plane' => ['Plane', 'Stückgut', 'Pritsche'],
            'Koffer' => ['Koffer', 'Stückgut', 'Pritsche', 'Plane'],
            'Kühlwagen' => ['Kühlwagen', 'Stückgut', 'Pritsche', 'Plane', 'Koffer'],
            'Silo' => ['Silo'],
            'Tankwagen' => ['Tankwagen'],
            'Schwertransport' => ['Schwertransport'],
            'ISO-Container' => ['ISO-Container'],
            'Super-Liner' => ['Super-Liner', 'Stückgut', 'Pritsche', 'Plane', 'Koffer']
        ];

        return in_array($fType, $matrix[$vType] ?? [], true);
    }

    /**
     * Gibt alle kompatiblen Frachttypen für einen Fahrzeugtyp nach PH 3.3 zurück.
     */
    private function getCompatibleFreightTypes(string $vehicleType): array
    {
        $normalize = function(string $type): string {
            $lower = strtolower($type);
            if (str_contains($lower, 'silo')) return 'Silo';
            if (str_contains($lower, 'flüssig') || str_contains($lower, 'tank')) return 'Tankwagen';
            if (str_contains($lower, 'kühl')) return 'Kühlwagen';
            if (str_contains($lower, 'schütt')) return 'Schüttgut';
            if (str_contains($lower, 'kurier')) return 'Kurier';
            if (str_contains($lower, 'pritsche')) return 'Pritsche';
            if (str_contains($lower, 'iso')) return 'ISO-Container';
            if (str_contains($lower, 'schwer')) return 'Schwertransport';
            if (str_contains($lower, 'koffer')) return 'Koffer';
            if (str_contains($lower, 'plane')) return 'Plane';
            if (str_contains($lower, 'stück')) return 'Stückgut';
            return $type;
        };

        $vType = $normalize($vehicleType);
        
        $matrix = [
            'Kurier' => ['Kurier', 'Stückgut', 'Pritsche', 'Plane', 'Koffer'],
            'Stückgut' => ['Stückgut', 'Kurier', 'Pritsche', 'Plane', 'Koffer'],
            'Schüttgut' => ['Schüttgut'],
            'Pritsche' => ['Pritsche', 'Schüttgut'],
            'Plane' => ['Plane', 'Stückgut', 'Pritsche'],
            'Koffer' => ['Koffer', 'Stückgut', 'Pritsche', 'Plane'],
            'Kühlwagen' => ['Kühlwagen', 'Stückgut', 'Pritsche', 'Plane', 'Koffer'],
            'Silo' => ['Silo'],
            'Tankwagen' => ['Tankwagen'],
            'Schwertransport' => ['Schwertransport'],
            'ISO-Container' => ['ISO-Container'],
            'Super-Liner' => ['Super-Liner', 'Stückgut', 'Pritsche', 'Plane', 'Koffer']
        ];

        $compatibleNormalized = $matrix[$vType] ?? [$vType];
        
        // Expansion auf Ingame-Synonyme zur präzisen Abdeckung in SQL
        $allPossibleTypes = [];
        foreach ($compatibleNormalized as $norm) {
            $allPossibleTypes[] = $norm;
            if ($norm === 'Plane') {
                $allPossibleTypes[] = 'Plane(Wetterschutz)';
            } elseif ($norm === 'Kühlwagen') {
                $allPossibleTypes[] = 'Kühlwaren';
            } elseif ($norm === 'Koffer') {
                $allPossibleTypes[] = 'Kofferwagen';
            } elseif ($norm === 'Tankwagen') {
                $allPossibleTypes[] = 'Flüssigkeiten';
            }
        }
        
        return array_unique($allPossibleTypes);
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
     * Prüft, ob freie Slots für Marktaufträge verfügbar sind (resistent gegen Splitting-Doubletten).
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

        // Ermittelt die real belegten Slots (zusammengefasste Ingame-IDs ohne Split-Suffixes)
        $stmt = $this->pdo->query("
            SELECT (
                SELECT COUNT(DISTINCT SUBSTRING_INDEX(ingame_order_id, '-', 1))
                FROM orders
                WHERE is_archived = 0
                  AND ingame_order_id IS NOT NULL
                  AND (is_accepted = 1 OR assigned_truck_id IS NOT NULL)
            ) + (
                SELECT COUNT(*)
                FROM orders
                WHERE is_archived = 0
                  AND ingame_order_id IS NULL
                  AND assigned_truck_id IS NOT NULL
            ) AS count
        ");
        $usedSlots = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
        return ($globalLimit - $usedSlots) > 0;
    }

    /**
     * Führt einen auf PH 3.3 basierenden globalen Scan durch, falls die 3-Städte-Regel keine Ergebnisse liefert.
     * KORREKTUR: Berechnet die Anfahrtsdistanzen und sortiert STRENG nach der kürzesten Anfahrt (empty_run_dist) in PHP!
     *
     * @param int $truckId Die Fahrzeug-ID
     * @param string $vehicleType Der Fahrzeugtyp
     * @param int $capacity Die Kapazität des Fahrzeugs
     * @return array Array mit Fallback-Aufträgen
     */
    private function getFallbackSuggestions(int $truckId, string $vehicleType, int $capacity): array
    {
        // Hole die aktuelle Position des LKW für den Distanzabgleich
        $stmtTruckCity = $this->pdo->prepare("SELECT current_city_id FROM trucks WHERE id = ?");
        $stmtTruckCity->execute([$truckId]);
        $currentCityId = (int)($stmtTruckCity->fetchColumn() ?: 0);

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
            AND o.weight_remaining > 0
            AND o.freight_type IN ($placeholdersStr)
            AND o.weight_total <= :capacity
            AND (o.is_adr = 0 OR :has_adr = 1)
            AND o.assigned_truck_id IS NULL
            LIMIT 20
        ");
        
        $stmt->execute($params);
        $fallbackOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($fallbackOrders as $fo) {
            $distance = $this->distanceService->getDistance($currentCityId, (int)$fo['from_city_id']);
            $fo['empty_run_dist'] = $distance;
            $results[] = $fo;
        }

        // Sortiere streng nach der kürzesten Anfahrt (empty_run_dist ASC)
        usort($results, function($a, $b) {
            if ($a['empty_run_dist'] !== $b['empty_run_dist']) {
                return $a['empty_run_dist'] <=> $b['empty_run_dist'];
            }
            // Tie-Breaker bei identischer Anfahrt: Rentabilität des Auftrags
            $profitA = $a['revenue'] / max(1, (int)$a['weight_total']);
            $profitB = $b['revenue'] / max(1, (int)$b['weight_total']);
            return $profitB <=> $profitA;
        });

        return $results;
    }

    /**
     * Taktisches Radar: Findet ALLE kompatiblen Aufträge im 3-Städte-Radius (Auswahl-Garantie, PH § 9.3.4)
     * und bereichert sie um den vorausschauenden Ketten-Radar-Indikator.
     *
     * KORREKTUR:
     * - Prüft die Planungs-Checkbox (liefert [] bei is_active_planning = 0)
     * - Keine fehleranfälligen Parameter-Mischungen im SQL mehr
     * - Sortiert die Frachten streng nach kürzester physischer Anfahrt (0 km zuerst)
     * - Füllt die Vorschläge über die Fallback-Engine stabil auf mindestens 3 Optionen auf (Padding)
     * - Schließt bereits verplante 0-Tonnen-Splitreste konsequent aus (weight_remaining > 0)
     *
     * @param int $truckId Die ID des LKW
     * @param int $currentCityId Start- oder Tourende-Stadt des LKW
     * @return array Liste aller Optionen mit Ketten-Indikator
     */
    public function getRadarScanForTruck(int $truckId, int $currentCityId): array
    {
        // 1. Fahrzeugdaten und Planungsstatus auslesen
        $truckStmt = $this->pdo->prepare("SELECT capacity_t, vehicle_type, is_active_planning FROM trucks WHERE id = ?");
        $truckStmt->execute([$truckId]);
        $truck = $truckStmt->fetch(PDO::FETCH_ASSOC);

        // Sicherheits-Check: Falls LKW nicht existiert oder nicht aktiv geschaltet ist, sofort abbrechen!
        if (!$truck || (int)$truck['is_active_planning'] === 0) {
            return [];
        }

        $capacity = (int)$truck['capacity_t'];
        $vehicleType = $truck['vehicle_type'];

        // 2. Die 3-Städte-Nachbarschaft ermitteln
        $neighborCities = $this->getNearestCities($currentCityId, 2);
        $relevantCityIds = array_merge([$currentCityId], $neighborCities);

        $placeholders = implode(',', array_fill(0, count($relevantCityIds), '?'));
        
        // 3. Regionale Suche im 3-Städte-Radius (Ausschalten von bereits verplanten Restposten)
        $orderStmt = $this->pdo->prepare("
            SELECT o.*, c1.name AS from_city_name, c2.name AS to_city_name
            FROM orders o
            JOIN cities c1 ON o.from_city_id = c1.id
            JOIN cities c2 ON o.to_city_id = c2.id
            WHERE o.from_city_id IN ($placeholders)
            AND o.is_archived = 0
            AND o.weight_remaining > 0
            AND o.assigned_truck_id IS NULL
            ORDER BY o.from_city_id = ? DESC, (o.revenue / o.weight_total) DESC
            LIMIT 15
        ");

        $params = array_merge($relevantCityIds, [$currentCityId]);
        $orderStmt->execute($params);
        $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

        $radarScan = [];
        $driverHasAdr = $this->hasAdrDriverForTruck($truckId);

        foreach ($orders as $order) {
            // Kompatibilitäts-Checks
            if (!$this->isTypeCompatible($order['freight_type'], $vehicleType)) {
                continue;
            }

            if ($order['is_adr'] && !$driverHasAdr) {
                continue;
            }

            // Slot-Prüfung für Marktaufträge
            if (!$order['is_accepted'] && !$this->hasFreeSlots()) {
                continue;
            }

            $distanceToOrder = $this->distanceService->getDistance($currentCityId, (int)$order['from_city_id']);
            $routeDistance = $this->distanceService->getDistance((int)$order['from_city_id'], (int)$order['to_city_id']);
            
            $loadedWeight = min((int)$order['weight_remaining'], $capacity);
            $isSplit = (int)$order['weight_remaining'] > $capacity;

            // Simuliert vorausschauend die Ketten-Tiefe ab dem Zielort (PH § 7)
            $radarScan[] = [
                'order' => $order,
                'loaded_weight' => $loadedWeight,
                'available_weight' => (int)$order['weight_remaining'],
                'is_split' => $isSplit,
                'empty_run_dist' => $distanceToOrder,
                'earning_per_tkm' => $order['revenue'] / ($order['weight_total'] * max($routeDistance, 1)),
                'is_fallback' => false,
                'status' => $order['is_accepted'] ? 'warehouse' : 'market',
                'radar_indicator' => $this->simulateRadarChain((int)$order['to_city_id'], $truckId, $vehicleType, $capacity)
            ];
        }

        // 4. DIE 3er-GARANTIE (PADDING):
        // Haben wir weniger als 3 regionale Ergebnisse, ziehen wir globale Alternativen hinzu,
        // ebenfalls streng sortiert nach kürzester Anfahrt!
        if (count($radarScan) < 3) {
            $fallbackOrders = $this->getFallbackSuggestions($truckId, $vehicleType, $capacity);
            $existingIds = array_column(array_column($radarScan, 'order'), 'id');

            foreach ($fallbackOrders as $fallbackOrder) {
                // Keine Duplikate einfügen
                if (in_array($fallbackOrder['id'], $existingIds, true)) {
                    continue;
                }

                $distanceToOrder = $fallbackOrder['empty_run_dist']; // Bereits in der Fallback-Engine berechnet!
                $routeDistance = $this->distanceService->getDistance((int)$fallbackOrder['from_city_id'], (int)$fallbackOrder['to_city_id']);
                
                $loadedWeight = min((int)$fallbackOrder['weight_remaining'], $capacity);
                $isSplit = (int)$fallbackOrder['weight_remaining'] > $capacity;

                $radarScan[] = [
                    'order' => $fallbackOrder,
                    'loaded_weight' => $loadedWeight,
                    'available_weight' => (int)$fallbackOrder['weight_remaining'],
                    'is_split' => $isSplit,
                    'empty_run_dist' => $distanceToOrder,
                    'earning_per_tkm' => $fallbackOrder['revenue'] / ($fallbackOrder['weight_total'] * max($routeDistance, 1)),
                    'is_fallback' => true,
                    'status' => $fallbackOrder['is_accepted'] ? 'warehouse' : 'market',
                    'radar_indicator' => $this->simulateRadarChain((int)$fallbackOrder['to_city_id'], $truckId, $vehicleType, $capacity)
                ];

                if (count($radarScan) >= 3) {
                    break;
                }
            }
        }

        // 5. Priorisierte Sortierung: 1. Leerfahrt-Entfernung (ASC), 2. Erlös pro tkm (DESC)
        usort($radarScan, function($a, $b) {
            if ($a['empty_run_dist'] !== $b['empty_run_dist']) {
                return $a['empty_run_dist'] <=> $b['empty_run_dist'];
            }
            return $b['earning_per_tkm'] <=> $a['earning_per_tkm'];
        });

        return $radarScan;
    }

    /**
     * Simuliert vorausschauend die Ketten-Tiefe für eine Option (max 5 Schritte) nach PH § 7.3.
     *
     * @param int $startCityId Zielort des aktuellen Vorschlags (Startpunkt der Simulation)
     * @param int $truckId Die Fahrzeug-ID
     * @param string $vehicleType Der Fahrzeugtyp
     * @param int $capacity Die Kapazität des LKW
     * @return array Array mit 'type' (1=Grün, 2=Orange, 3=Rot) und 'label' (Anzeigetext)
     */
    private function simulateRadarChain(int $startCityId, int $truckId, string $vehicleType, int $capacity): array
    {
        $hasAdr = $this->hasAdrDriverForTruck($truckId);
        $currentCityId = $startCityId;
        $depth = 0;
        $maxDepth = 5; // Begrenzung der Vorschau-Tiefe
        $neighborUsed = false;
        $fallbackRequired = false;
        $usedIds = []; // Verhindert Endlosschleifen mit derselben ID in der Simulation

        while ($depth < $maxDepth) {
            // 1. STUFE 1: Suche nach Direkt-Anschlüssen (0 km Leerfahrt)
            $stmt0 = $this->pdo->prepare("
                SELECT id, to_city_id, freight_type 
                FROM orders 
                WHERE from_city_id = ? 
                  AND is_archived = 0 
                  AND assigned_truck_id IS NULL
                  AND weight_remaining > 0
                  AND weight_total <= ?
                  AND (is_adr = 0 OR ? = 1)
            ");
            $stmt0->execute([$currentCityId, $capacity, (int)$hasAdr]);
            $directs = $stmt0->fetchAll(PDO::FETCH_ASSOC);

            $foundJob = null;
            foreach ($directs as $d) {
                if (in_array($d['id'], $usedIds, true)) continue;
                if ($this->isTypeCompatible($d['freight_type'], $vehicleType)) {
                    $foundJob = $d;
                    break;
                }
            }

            if ($foundJob) {
                $usedIds[] = $foundJob['id'];
                $currentCityId = (int)$foundJob['to_city_id'];
                $depth++;
                continue;
            }

            // 2. STUFE 2: Keine Direktfracht -> 3-Städte-Regel (Nachbarschafts-Überbrückung)
            $neighborCities = $this->getNearestCities($currentCityId, 2);
            if (!empty($neighborCities)) {
                $placeholders = implode(',', array_fill(0, count($neighborCities), '?'));
                $stmtNear = $this->pdo->prepare("
                    SELECT id, to_city_id, freight_type 
                    FROM orders 
                    WHERE from_city_id IN ($placeholders) 
                      AND is_archived = 0 
                      AND assigned_truck_id IS NULL
                      AND weight_remaining > 0
                      AND weight_total <= ?
                      AND (is_adr = 0 OR ? = 1)
                ");
                $params = array_merge($neighborCities, [$capacity, (int)$hasAdr]);
                $stmtNear->execute($params);
                $nears = $stmtNear->fetchAll(PDO::FETCH_ASSOC);

                foreach ($nears as $n) {
                    if (in_array($n['id'], $usedIds, true)) continue;
                    if ($this->isTypeCompatible($n['freight_type'], $vehicleType)) {
                        $foundJob = $n;
                        break;
                    }
                }
            }

            if ($foundJob) {
                $usedIds[] = $foundJob['id'];
                $currentCityId = (int)$foundJob['to_city_id'];
                $depth++;
                $neighborUsed = true;
                continue;
            }

            // 3. STUFE 3: Globaler Fallback-Transfer erforderlich (Sackgasse)
            $fallbackRequired = true;
            break;
        }

        // Rückgabe des formatierten Indikators
        if ($fallbackRequired && $depth < 2) {
            return [
                'type' => 3,
                'label' => 'Achtung: Transferfahrt nötig'
            ];
        }

        $labelSuffix = $neighborUsed ? ' (inkl. 3SR)' : '';
        return [
            'type' => $neighborUsed ? 2 : 1,
            'label' => ($depth > 0 ? ($depth . '+') : '0') . ' Aufträge' . $labelSuffix
        ];
    }
}