<?php
declare(strict_types=1);

/**
 * VehicleMarketRepository: Kapselt die Datenbank-Operationen und ROI-Berechnung 
 * für den Gebrauchtwagenmarkt (PH 2.6 & 5.1)[cite: 3].
 */
class VehicleMarketRepository
{
    private PDO $pdo;
    private ?int $gameYear = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Speichert ein Array von extrahierten Fahrzeugen in der Datenbank (Upsert)[cite: 3].
     *
     * @param array $vehicles Die vom VehicleMarketParser extrahierten Fahrzeugdaten
     * @return int Anzahl der erfolgreich verarbeiteten Datensätze
     */
    public function saveBatch(array $vehicles): int
    {
        if (empty($vehicles)) {
            return 0;
        }

        $gameYear = $this->getGameYear();
        $count = 0;

        $stmt = $this->pdo->prepare("
            INSERT INTO market_history (
                ingame_vehicle_id, location_label, vehicle_type, capacity_t, 
                year_built, km_stand, condition_pct, price, tuning_value_total, roi_score
            ) VALUES (
                :id, :loc, :type, :cap, :year, 
                :km, :cond, :price, :tuning, :roi
            ) ON DUPLICATE KEY UPDATE 
                location_label = :loc_upd,
                km_stand = :km_upd,
                condition_pct = :cond_upd,
                price = :price_upd,
                tuning_value_total = :tuning_upd,
                roi_score = :roi_upd,
                recorded_at = CURRENT_TIMESTAMP
        ");

        foreach ($vehicles as $data) {
            $roiScore = $this->calculateRoiScore($data, $gameYear);

            $stmt->execute([
                'id'     => $data['ingame_vehicle_id'],
                'loc'    => $data['location_label'],
                'type'   => $data['vehicle_type'],
                'cap'    => $data['capacity_t'],
                'year'   => $data['year_built'],
                'km'     => $data['km_stand'],
                'cond'   => $data['condition_pct'],
                'price'  => $data['price'],
                'tuning' => $data['tuning_value_total'],
                'roi'    => $roiScore,
                
                // Update-Parameter für existierende Anzeigen (PH 2.6.2.3)[cite: 3]
                'loc_upd'    => $data['location_label'],
                'km_upd'     => $data['km_stand'],
                'cond_upd'   => $data['condition_pct'],
                'price_upd'  => $data['price'],
                'tuning_upd' => $data['tuning_value_total'],
                'roi_upd'    => $roiScore
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * Berechnet den ROI-Score basierend auf Preis, Kapazität, Zustand und Mali (PH 2.6.3)[cite: 3].
     */
    private function calculateRoiScore(array $data, int $gameYear): int
    {
        // 1. Effektiver Preis (PH 2.6.3.1)[cite: 3]
        $effectivePrice = $data['price'] - $data['tuning_value_total'];
        if ($effectivePrice < 0) {
            $effectivePrice = 0; // Absicherung gegen logische Fehler
        }

        // 2. Qualitäts-Faktor Basis (PH 2.6.3.2)[cite: 3]
        $conditionFactor = $data['condition_pct'] / 100.0;
        
        // 3. Alters-Mali (PH 2.6.3.2)[cite: 3]
        $age = $gameYear - $data['year_built'];
        $ageMali = 1.0;
        if ($age >= 15) {
            $ageMali = 0.3; // 70% Reduktion[cite: 3]
        } elseif ($age >= 8) {
            $ageMali = 0.6; // 40% Reduktion[cite: 3]
        }

        // 4. Laufleistungs-Mali (PH 2.6.3.2)[cite: 3]
        $kmMali = 1.0;
        if ($data['km_stand'] >= 2000000) {
            $kmMali = 0.5; // 50% Reduktion[cite: 3]
        } elseif ($data['km_stand'] >= 1000000) {
            $kmMali = 0.8; // 20% Reduktion[cite: 3]
        }

        $finalCondition = $conditionFactor * $ageMali * $kmMali;

        // Absicherung gegen Division durch Null
        if ($finalCondition <= 0 || $data['capacity_t'] <= 0) {
            return 0;
        }

        // 5. Endformel (PH 2.6.3.3)[cite: 3]
        $roiScore = $effectivePrice / ($data['capacity_t'] * $finalCondition);
        
        return (int)round($roiScore);
    }

    /**
     * Ermittelt das im System konfigurierte Spiel-Jahr für die Altersberechnung.
     */
    private function getGameYear(): int
    {
        if ($this->gameYear !== null) {
            return $this->gameYear;
        }

        $stmt = $this->pdo->query("SELECT cfg_value FROM config WHERE cfg_key = 'game_year'");
        $val = $stmt->fetchColumn();
        
        $this->gameYear = $val !== false ? (int)$val : (int)date('Y');
        return $this->gameYear;
    }
}