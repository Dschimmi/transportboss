<?php
declare(strict_types=1);

namespace classes;

use Exception;

/**
 * WarehouseParser
 *
 * Analysiert den einzeiligen, Tabulator-getrennten Kopiertext (TSV) aus dem eigenen 
 * Ingame-Lager und extrahiert spaltenbasiert alle relevanten Auftragsdaten rein horizontal.
 *
 * @author TransportBoss Development
 * @version 1.2.2
 */
class WarehouseParser
{
    /**
     * @var \CityService Dienst zur Auflösung von Stadtnamen in IDs
     */
    private \CityService $cityService;

    /**
     * @param \CityService $cityService Der Service zur Stadt-ID-Auflösung
     */
    public function __construct(\CityService $cityService)
    {
        $this->cityService = $cityService;
    }

    /**
     * Parst den einzeiligen, tabellarischen Kopiertext des eigenen Lagers.
     * Verarbeitet den Datensatz streng horizontal pro Zeile.
     *
     * @param string $rawText Der einkopierte Text des Lagers
     * @return array Liste der extrahierten Lageraufträge mit IDN-Zuweisung
     * @throws Exception Bei Fehlern in der Stadt-Auflösung
     */
    public function parse(string $rawText): array
    {
        $rawText = trim($rawText);
        if ($rawText === '') {
            return [];
        }

        // Text zeilenweise aufteilen (jede Zeile ist ein eigenständiger Auftrag)
        $lines = explode("\n", $rawText);
        $orders = [];

        foreach ($lines as $line) {
            $row = trim($line);
            if ($row === '') {
                continue;
            }

            // Spalten per Tabulator trennen (Standard beim Kopieren)
            $cols = explode("\t", $row);

            // Fallback: Falls Leerzeichen statt Tabulatoren kopiert wurden
            if (count($cols) < 5) {
                $cols = preg_split('/\s{2,}/', $row);
            }

            // Ein gültiger Zeilen-Datensatz benötigt mindestens 6 Spalten (IDN, Gewicht, Typ, Start, Ziel, Erlös)
            if (count($cols) < 6) {
                continue;
            }

            try {
                // Spalte 0: Ingame-ID (z. B. "IDN10667107")
                $ingameId = str_replace(' ', '', strtoupper(trim($cols[0])));
                if ($ingameId !== '' && !str_starts_with($ingameId, 'IDN')) {
                    $ingameId = 'IDN' . $ingameId;
                }

                // Spalte 1: Gewicht (z. B. "1 / 1 t" oder "19 / 27 t")
                $weightLine = trim($cols[1]);
                $weightRemaining = 0;
                $weightTotal = 0;

                if (preg_match('/(\d+)\s*\/\s*(\d+)\s*t/i', $weightLine, $matches)) {
                    $weightRemaining = (int)$matches[1];
                    $weightTotal = (int)$matches[2];
                } elseif (preg_match('/(\d+)\s*t/i', $weightLine, $matches)) {
                    $weightRemaining = (int)$matches[1];
                    $weightTotal = (int)$matches[1];
                }

                // Spalte 2: Frachttyp & Ware (z. B. "Kurier (Direktzustellung)")
                $freightLine = trim($cols[2]);
                $isAdr = str_contains(strtolower($freightLine), '[gefahrgut]') ? 1 : 0;
                
                $commodity = 'Unbekannt';
                if (preg_match('/\(([^)]+)\)/', $freightLine, $matches)) {
                    $commodity = trim($matches[1]);
                }

                // Fracht-Header isolieren und normalisieren
                $rawFreightType = trim(preg_replace('/\s*\([^)]+\)/', '', $freightLine));
                $freightType = $this->normalizeFreightType($rawFreightType);

                // Spalte 3: Startort (z. B. "Deutschland Halle")
                $rawFromCity = trim($cols[3]);
                $fromCityName = $this->cleanCountryPrefix($rawFromCity);

                // Spalte 4: Zielort (z. B. "Deutschland Wiesbaden")
                $rawToCity = trim($cols[4]);
                $toCityName = $this->cleanCountryPrefix($rawToCity);

                // Spalte 5: Erlös (z. B. "1,070.23")
                $rawRevenue = trim($cols[5]);
                // Stufe 1 & 2 der Geldlogik
                $cleanedRevenue = str_replace(',', '', $rawRevenue);
                $cleanedRevenue = preg_replace('/[^\d.]/', '', $cleanedRevenue);
                $revenue = (float)$cleanedRevenue;

                // Städte über den CityService auflösen (PH 3.2.1.3)
                $fromCityId = $this->cityService->resolveId($fromCityName, true);
                $toCityId = $this->cityService->resolveId($toCityName, true);

                $orders[] = [
                    'ingame_order_id' => $ingameId,
                    'fingerprint' => null, // Identifikation erfolgt über die IDN
                    'freight_type' => $freightType,
                    'commodity' => $commodity,
                    'is_adr' => $isAdr,
                    'weight_total' => $weightTotal,
                    'weight_remaining' => $weightRemaining,
                    'revenue' => $revenue,
                    'from_city_id' => $fromCityId,
                    'to_city_id' => $toCityId,
                    'from_city_name' => $fromCityName,
                    'to_city_name' => $toCityName,
                    'is_accepted' => 1,
                    'distance_km' => 0 // Wird in den Sichten live berechnet
                ];

            } catch (Exception $e) {
                // Bei Fehlern Zeile überspringen
                continue;
            }
        }

        return $orders;
    }

    /**
     * Schneidet Länderpräfixe ab.
     */
    private function cleanCountryPrefix(string $cityName): string
    {
        $prefixes = [
            'Deutschland ', 'Österreich ', 'Schweiz ', 'Niederlande ', 
            'Belgien ', 'Luxemburg ', 'Dänemark ', 'Polen ', 
            'Tschechien ', 'Frankreich '
        ];
        return trim(str_replace($prefixes, '', $cityName));
    }

    /**
     * Normalisiert den einkopierten Frachttyp.
     */
    private function normalizeFreightType(string $rawType): string
    {
        $lower = strtolower($rawType);
        if (str_contains($lower, 'silo')) return 'Silo';
        if (str_contains($lower, 'flüssig')) return 'Tankwagen';
        if (str_contains($lower, 'kühl')) return 'Kühlwagen';
        if (str_contains($lower, 'schütt')) return 'Schüttgut';
        if (str_contains($lower, 'kurier')) return 'Kurier';
        if (str_contains($lower, 'pritsche')) return 'Pritsche';
        if (str_contains($lower, 'iso')) return 'ISO-Container';
        if (str_contains($lower, 'schwer')) return 'Schwertransport';
        if (str_contains($lower, 'koffer')) return 'Koffer';
        if (str_contains($lower, 'plane')) return 'Plane';
        return 'Stückgut';
    }
}