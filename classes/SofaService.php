<?php
declare(strict_types=1);

require_once __DIR__ . '/CityService.php';
require_once __DIR__ . '/DistanceService.php';
require_once __DIR__ . '/OrderRepository.php';
require_once __DIR__ . '/TopologyEngine.php';

/**
 * SofaService
 *
 * Kapselt die komplette Geschäfts- und Dispositionslogik für Sonderfahrten (SoFa) nach Freiburg.
 * Kaskadiertes Entladen bis zum Freiburg-nächsten Tourstopp + Garantie der Ankunft in Freiburg (max. 5 Hops).
 *
 * @author TransportBoss Development
 * @version 1.1.0
 */
class SofaService
{
    private PDO $pdo;
    private DistanceService $distanceService;
    private OrderRepository $orderRepo;

    public function __construct(PDO $pdo, DistanceService $distanceService, OrderRepository $orderRepo)
    {
        $this->pdo = $pdo;
        $this->distanceService = $distanceService;
        $this->orderRepo = $orderRepo;
    }

    /**
     * Schaltet eine Sonderfahrt nach Freiburg für einen LKW frei.
     * Führt kaskadierendes Entladen durch und garantiert das Erreichen von Freiburg in max. 5 Hops.
     *
     * @param int $truckId Technische ID des LKW
     * @param string $reason Begründungstext für die Sonderfahrt
     * @return array Statusdaten ['success' => bool, 'message' => string]
     */
    public function initiateSofa(int $truckId, string $reason): array
    {
        // 1. Freiburg-ID ermitteln
        $stmtFreiburg = $this->pdo->prepare("SELECT id FROM cities WHERE name = 'Freiburg' LIMIT 1");
        $stmtFreiburg->execute();
        $freiburgId = (int)$stmtFreiburg->fetchColumn();

        if ($freiburgId <= 0) {
            return ['success' => false, 'message' => 'Stadt Freiburg nicht im System registriert.'];
        }

        // 2. LKW- und Fahrerdaten laden
        $stmtTruck = $this->pdo->prepare("
            SELECT t.*, d.adr_permit 
            FROM trucks t
            LEFT JOIN drivers d ON t.assigned_driver_id = d.ingame_driver_id
            WHERE t.id = ?
        ");
        $stmtTruck->execute([$truckId]);
        $truck = $stmtTruck->fetch(PDO::FETCH_ASSOC);

        if (!$truck) {
            return ['success' => false, 'message' => 'Fahrzeug nicht gefunden.'];
        }

        $driverHasAdr = !empty($truck['adr_permit']);
        $truckCapacity = (int)$truck['capacity_t'];
        $vehicleType = $truck['vehicle_type'];

        $this->pdo->beginTransaction();
        try {
            // 3. KASKADIERENDES ENTLADEN BIS ZUM FREIBURG-NÄCHSTEN STOPP
            $stmtOrders = $this->pdo->prepare("
                SELECT id, ingame_order_id, weight_total, weight_loaded, weight_remaining, from_city_id, to_city_id, assigned_at
                FROM orders 
                WHERE assigned_truck_id = ? AND is_archived = 0
                ORDER BY assigned_at ASC
            ");
            $stmtOrders->execute([$truckId]);
            $assignedOrders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

            $currentStartCityId = (int)$truck['current_city_id'];
            $bestCityId = $currentStartCityId;
            $minDistToFreiburg = $this->distanceService->getDistance($currentStartCityId, $freiburgId);
            $keepJobIds = [];

            // 1. Sammle alle Job-IDs, die die Distanz nach Freiburg ECHT verkürzen
            foreach ($assignedOrders as $ord) {
                $distToFreiburg = $this->distanceService->getDistance((int)$ord['to_city_id'], $freiburgId);
                if ($distToFreiburg < $minDistToFreiburg) {
                    $minDistToFreiburg = $distToFreiburg;
                    $bestCityId = (int)$ord['to_city_id'];
                    $keepJobIds[] = (int)$ord['id'];
                } else {
                    // Kappe die Tour beim ERSTEN Stopp, der die Distanz vergrößert!
                    break;
                }
            }

            // 2. Entlade AUSNAHMSLOS alle Aufträge, die nicht in $keepJobIds stehen!
            foreach (array_reverse($assignedOrders) as $uOrd) {
                if (!in_array((int)$uOrd['id'], $keepJobIds, true)) {
                    if (OrderRepository::isClone($uOrd)) {
                        $this->orderRepo->refundClone($uOrd);
                        $this->orderRepo->deleteClone((int)$uOrd['id']);
                    } else {
                        $this->orderRepo->releaseSingleOrder((int)$uOrd['id']);
                    }
                }
            }

            // 4. A*-PATHFINDING NACH FREIBURG (GARANTIERTE ANKUNFT IN FREIBURG IN MAX 5 HOPS)
            $stmtRemainingJobs = $this->pdo->prepare("SELECT COUNT(*) FROM orders WHERE assigned_truck_id = ? AND is_archived = 0");
            $stmtRemainingJobs->execute([$truckId]);
            $hopCount = (int)$stmtRemainingJobs->fetchColumn();

            $currentPos = $bestCityId;

            // A*-Suchknoten für die Graphensuche nach Freiburg
            $queue = [[
                'pos' => $currentPos,
                'hops' => $hopCount,
                'path' => [],
                'cost' => $this->distanceService->getDistance($currentPos, $freiburgId)
            ]];

            $bestPath = null;
            $minCostFound = 999999;

            // Alle freien LAGER-Aufträge laden (is_accepted = 1), OHNE Tonnage-Sperren & OHNE 3SR!
            $stmtWarehouse = $this->pdo->query("
                SELECT o.* 
                FROM orders o
                WHERE o.is_accepted = 1 
                  AND o.is_archived = 0 
                  AND o.assigned_truck_id IS NULL 
                  AND o.weight_remaining > 0
            ");
            $allWarehouseOrders = $stmtWarehouse->fetchAll(PDO::FETCH_ASSOC);

            while (!empty($queue)) {
                // Besten Knoten nach geschätzten Gesamtkosten auswählen (A* Heuristik)
                usort($queue, fn($a, $b) => $a['cost'] <=> $b['cost']);
                $node = array_shift($queue);

                $nodePos = $node['pos'];
                $nodeHops = $node['hops'];
                $nodePath = $node['path'];
                $distToFreiburg = $this->distanceService->getDistance($nodePos, $freiburgId);

                // Ziel erreicht: Fahrzeug steht in Freiburg!
                if ($distToFreiburg === 0) {
                    if ($node['cost'] < $minCostFound) {
                        $minCostFound = $node['cost'];
                        $bestPath = $nodePath;
                    }
                    break;
                }

                if ($nodeHops >= 5) {
                    continue; // Max. 5 Hops erreicht
                }

                // Zweig 1: Passende Lageraufträge einbinden
                $foundAnyOrder = false;
                foreach ($allWarehouseOrders as $cand) {
                    if (!TopologyEngine::isTypeCompatible($cand['freight_type'], $vehicleType)) continue;
                    if ((int)$cand['is_adr'] === 1 && !$driverHasAdr) continue;

                    $candFrom = (int)$cand['from_city_id'];
                    $candTo = (int)$cand['to_city_id'];
                    $emptyRun = $this->distanceService->getDistance($nodePos, $candFrom);
                    $candRest = $this->distanceService->getDistance($candTo, $freiburgId);

                    // Nur Aufträge zulassen, die Anfahrt sparen und näher nach Freiburg führen
                    if ($emptyRun < $distToFreiburg && $candRest < $distToFreiburg) {
                        $newCost = $node['cost'] + $emptyRun;
                        $newPath = $nodePath;
                        $newPath[] = $cand;

                        $queue[] = [
                            'pos' => $candTo,
                            'hops' => $nodeHops + 1,
                            'path' => $newPath,
                            'cost' => $newCost + $candRest
                        ];
                        $foundAnyOrder = true;
                    }
                }

                // Zweig 2: Falls kein Lagerauftrag weiterführt -> Direkter Transfer nach Freiburg
                if (!$foundAnyOrder) {
                    $transferCost = $node['cost'] + $distToFreiburg;
                    if ($transferCost < $minCostFound) {
                        $minCostFound = $transferCost;
                        $bestPath = $nodePath;
                    }
                }
            }

            // Gefundenen A*-Pfad an Lageraufträgen zuweisen
            if (!empty($bestPath)) {
                foreach ($bestPath as $bestJob) {
                    $weightRemaining = (int)$bestJob['weight_remaining'];
                    $weightTotal = (int)$bestJob['weight_total'];
                    $revenue = (float)$bestJob['revenue'];

                    if ($weightRemaining > $truckCapacity || $weightRemaining < $weightTotal) {
                        $loadedWeight = min($weightRemaining, $truckCapacity);
                        $remAfter = $weightRemaining - $loadedWeight;
                        $propRev = round(($revenue / $weightTotal) * $loadedWeight, 2);

                        $stmtUpM = $this->pdo->prepare("UPDATE orders SET weight_remaining = ? WHERE id = ?");
                        $stmtUpM->execute([$remAfter, (int)$bestJob['id']]);

                        $splitIdn = null;
                        if (!empty($bestJob['ingame_order_id'])) {
                            $baseIdn = explode('-', $bestJob['ingame_order_id'])[0];
                            $stmtS = $this->pdo->prepare("SELECT ingame_order_id FROM orders WHERE ingame_order_id LIKE ?");
                            $stmtS->execute([$baseIdn . '-%']);
                            $suffs = $stmtS->fetchAll(PDO::FETCH_COLUMN);

                            $maxS = 0;
                            foreach ($suffs as $sIdn) {
                                $parts = explode('-', $sIdn);
                                if (isset($parts[1]) && (int)$parts[1] > $maxS) {
                                    $maxS = (int)$parts[1];
                                }
                            }
                            $splitIdn = $baseIdn . '-' . ($maxS + 1);
                        }

                        $stmtInSplit = $this->pdo->prepare("
                            INSERT INTO orders (
                                ingame_order_id, fingerprint, freight_type, commodity, is_adr, 
                                weight_total, weight_remaining, weight_loaded, revenue, from_city_id, to_city_id, 
                                is_accepted, is_archived, assigned_truck_id, assigned_at, last_seen_at
                            ) VALUES (
                                :idn, :fingerprint, :freight_type, :commodity, :is_adr, 
                                :weight_total, 0, :weight_loaded, :revenue, :from_city_id, :to_city_id, 
                                1, 0, :truck_id, NOW(6), NOW()
                            )
                        ");
                        $stmtInSplit->execute([
                            'idn' => $splitIdn,
                            'fingerprint' => $bestJob['fingerprint'],
                            'freight_type' => $bestJob['freight_type'],
                            'commodity' => $bestJob['commodity'],
                            'is_adr' => $bestJob['is_adr'],
                            'weight_total' => $weightTotal,
                            'weight_loaded' => $loadedWeight,
                            'revenue' => $propRev,
                            'from_city_id' => $bestJob['from_city_id'],
                            'to_city_id' => $bestJob['to_city_id'],
                            'truck_id' => $truckId
                        ]);
                    } else {
                        $this->orderRepo->assignToTruck((int)$bestJob['id'], $truckId);
                    }
                }
            }

            // 5. SoFa-Status am LKW setzen
            $stmtSetSofa = $this->pdo->prepare("UPDATE trucks SET is_sofa = 1, sofa_reason = ? WHERE id = ?");
            $stmtSetSofa->execute([$reason, $truckId]);

            $this->pdo->commit();
            return ['success' => true, 'message' => 'Sonderfahrt nach Freiburg erfolgreich initiiert. Ziel-Ankunft in Freiburg ist garantiert.'];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => 'Fehler bei der SoFa-Initialisierung: ' . $e->getMessage()];
        }
    }

    /**
     * Beendet/Deaktiviert die Sonderfahrt eines LKWs.
     */
    public function cancelSofa(int $truckId): void
    {
        $stmt = $this->pdo->prepare("UPDATE trucks SET is_sofa = 0, sofa_reason = NULL WHERE id = ?");
        $stmt->execute([$truckId]);
    }
}