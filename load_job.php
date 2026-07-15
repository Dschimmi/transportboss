<?php
declare(strict_types=1);

/**
 * load_job.php
 *
 * Controller zur sicheren, transaktionsbasierten Zuweisung eines Auftrags
 * an ein Fahrzeug (Tagesplanung). Unterstützt das automatische, proportionale
 * Splitting von Übergewicht-Frachten auf Datenbankebene.
 *
 * @author TransportBoss Development
 * @version 1.1.0
 */

require_once 'db_connect.php';

/**
 * JobLoader
 *
 * Verwaltet den Zuweisungsprozess eines Auftrags zu einem Fahrzeug.
 * Berechnet Teilmengen-Splits und stellt die referenzielle Datenintegrität sicher.
 */
class JobLoader
{
    private PDO $pdo;

    /**
     * @param PDO $pdo Die aktive Datenbankverbindung
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Führt die Zuweisung aus. Teilt den Auftrag bei Überladung proportional auf.
     *
     * @param int $truckId Die technische ID des LKW
     * @param int $orderId Die technische ID des Auftrags
     * @throws Exception Bei Datenbankfehlern oder unvollständigen Daten
     */
    public function execute(int $truckId, int $orderId): void
    {
        $this->pdo->beginTransaction();

        try {
            // LKW-Kapazität ermitteln
            $stmtTruck = $this->pdo->prepare("SELECT capacity_t FROM trucks WHERE id = ?");
            $stmtTruck->execute([$truckId]);
            $capacity = $stmtTruck->fetchColumn();

            if ($capacity === false) {
                throw new Exception("Fahrzeug mit ID {$truckId} nicht gefunden.");
            }
            $capacity = (int)$capacity;

            // Auftragsdaten ermitteln
            $stmtOrder = $this->pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $stmtOrder->execute([$orderId]);
            $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new Exception("Auftrag mit ID {$orderId} nicht gefunden.");
            }

            // Sicherheits-Interlock: Prüfen, ob der Auftrag bereits vergeben wurde (PH 2.5.4)
            if ($order['assigned_truck_id'] !== null) {
                throw new Exception("Dieser Auftrag wurde bereits einem anderen Fahrzeug zugewiesen!");
            }

            $weightRemaining = (int)$order['weight_remaining'];
            $weightTotal = (int)$order['weight_total'];
            $revenue = (float)$order['revenue'];

            // Sicherheits-Interlock: Prüfen, ob überhaupt noch Tonnage übrig ist
            if ($weightRemaining <= 0) {
                throw new Exception("Dieser Auftrag hat keine verbleibende Tonnage mehr!");
            }

            if ($weightRemaining > $capacity) {
                // --- FALL A: SPLITTING (Teillieferung notwendig) ---
                $loadedWeight = $capacity;
                $remainingWeight = $weightRemaining - $loadedWeight;

                // 1. Proportionalen Erlös für die geladene Teilladung berechnen
                $proportionalRevenue = round(($revenue / $weightTotal) * $loadedWeight, 2);

                // 2. Original-Auftrag im Pool updaten (Mengenreduktion)
                $stmtUpdateOrig = $this->pdo->prepare("
                    UPDATE orders 
                    SET weight_remaining = ? 
                    WHERE id = ?
                ");
                $stmtUpdateOrig->execute([$remainingWeight, $orderId]);

                // 3. Neuen Klon-Auftrag für die transportierte Teilladung anlegen und dem LKW zuweisen
                // Erzeuge eine eindeutige IDN für das Teilstück, um Unique-Sperren zu umgehen (z.B. IDN10645786-1)
                // KORREKTUR: Max-Suffix-Inkrementor statt COUNT(*) verhindert Kollisionen bei gelöschten Zwischengliedern
                $splitIdn = null;
                if ($order['ingame_order_id']) {
                    $stmtSuffixes = $this->pdo->prepare("SELECT ingame_order_id FROM orders WHERE ingame_order_id LIKE ?");
                    $stmtSuffixes->execute([$order['ingame_order_id'] . '-%']);
                    $existingSuffixes = $stmtSuffixes->fetchAll(PDO::FETCH_COLUMN);

                    $maxSuffix = 0;
                    foreach ($existingSuffixes as $existingIdn) {
                        $parts = explode('-', $existingIdn);
                        if (isset($parts[1])) {
                            $suffixNum = (int)$parts[1];
                            if ($suffixNum > $maxSuffix) {
                                $maxSuffix = $suffixNum;
                            }
                        }
                    }
                    $nextSuffix = $maxSuffix + 1;
                    $splitIdn = $order['ingame_order_id'] . '-' . $nextSuffix;
                }

                $stmtInsertSplit = $this->pdo->prepare("
                    INSERT INTO orders (
                        ingame_order_id, fingerprint, freight_type, commodity, is_adr, 
                        weight_total, weight_remaining, revenue, from_city_id, to_city_id, 
                        is_accepted, is_archived, assigned_truck_id, assigned_at, last_seen_at
                    ) VALUES (
                        :idn, :fingerprint, :freight_type, :commodity, :is_adr, 
                        :weight_total, :weight_remaining, :revenue, :from_city_id, :to_city_id, 
                        :is_accepted, 0, :assigned_truck_id, NOW(), NOW()
                    )
                ");
                $stmtInsertSplit->execute([
                    'idn' => $splitIdn,
                    'fingerprint' => $order['fingerprint'],
                    'freight_type' => $order['freight_type'],
                    'commodity' => $order['commodity'],
                    'is_adr' => $order['is_adr'],
                    'weight_total' => $loadedWeight,
                    'weight_remaining' => $loadedWeight,
                    'revenue' => $proportionalRevenue,
                    'from_city_id' => $order['from_city_id'],
                    'to_city_id' => $order['to_city_id'],
                    'is_accepted' => (int)$order['is_accepted'],
                    'assigned_truck_id' => $truckId
                ]);

            } else {
                // --- FALL B: KOMPLETT-LADUNG (Kein Split notwendig) ---
                $stmtAssign = $this->pdo->prepare("
                    UPDATE orders 
                    SET assigned_truck_id = ?, 
                        assigned_at = NOW(), 
                        last_seen_at = NOW() 
                    WHERE id = ?
                ");
                $stmtAssign->execute([$truckId, $orderId]);
            }

            $this->pdo->commit();

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}

// POST-Request verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['truck_id'], $_POST['order_id'])) {
    $truckId = (int)$_POST['truck_id'];
    $orderId = (int)$_POST['order_id'];

    try {
        $loader = new JobLoader($pdo);
        $loader->execute($truckId, $orderId);
    } catch (Exception $e) {
        // KORREKTUR: Zeigt den genauen Fehlergrund an, anstatt ihn stillschweigend zu ignorieren
        die("Fataler Fehler beim Zuweisen des Auftrags: " . $e->getMessage());
    }
    // Zurück zur Disposition mit dem ausgewählten LKW im Fokus
    header("Location: dispatcher_board.php?focus_truck_id=" . $truckId);
    exit;
}

// Fallback-Redirect bei ungültigem Direktaufruf
header('Location: dispatcher_board.php');
exit;