<?php
declare(strict_types=1);

namespace classes;

use Exception;

/**
 * OrderParser
 *
 * Analysiert den mehrzeiligen, tabellarisch einkopierten Text aus der Ingame-Frachtbörse (Pool)
 * und extrahiert strukturierte Auftrags-Datenobjekte.
 *
 * @author TransportBoss Development
 * @version 1.1.1
 */
class OrderParser
{
    /**
     * @var \CityService Lokaler Dienst zur Auflösung von Stadtnamen in IDs (Korrektur: globaler Namespace-Indikator)
     */
    private \CityService $cityService;

    /**
     * @param \CityService $cityService Der Service zur Stadt-ID-Auflösung (Korrektur: globaler Namespace-Indikator)
     */
    public function __construct(\CityService $cityService)
    {
        $this->cityService = $cityService;
    }

    /**
     * Parst den rohen Textblock der Frachtbörse.
     *
     * @param string $rawText Der einkopierte Text aus der Frachtbörse
     * @param bool $isAccepted Standardmäßig false für Börsenaufträge
     * @return array Liste der strukturierten Aufträge
     * @throws Exception Bei Fehlern in der Stadt-Auflösung
     */
    public function parse(string $rawText, bool $isAccepted = false): array
    {
        $rawText = trim($rawText);
        if ($rawText === '') {
            return [];
        }

        // Zeilenweise Aufteilung des Rohtextes
        $lines = explode("\n", $rawText);
        $cleanLines = [];

        // Vorbereitung: Zeilen trimmen und Leerzeilen entfernen
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $cleanLines[] = $trimmed;
            }
        }

        $orders = [];
        $lineCount = count($cleanLines);
        $i = 0;

        /**
         * Da jeder Auftrag im Kopier-Layout über mehrere Zeilen gestreckt ist,
         * suchen wir nach bekannten Fracht-Indikatoren als Ankerpunkt für einen Block.
         */
        while ($i < $lineCount) {
            $line = $cleanLines[$i];

            // Prüfen, ob die aktuelle Zeile den Beginn eines Auftrags markiert (Fracht-Header)
            if ($this->isOrderHeader($line)) {
                try {
                    // Multi-Block Toleranz: Reicht der Resttext für einen Block? Falls nein, weiterscannen statt abzubrechen
                    if ($i + 5 >= $lineCount) {
                        $i++;
                        continue;
                    }

                    // 1. Frachttyp bestimmen
                    $rawFreightType = $line;
                    $freightType = $this->normalizeFreightType($rawFreightType);

                    // 2. Ware, Gefahrgut-Status und Gewicht bestimmen
                    $commodityLine = $cleanLines[$i + 1];
                    $isAdr = str_contains(strtolower($commodityLine), '[gefahrgut]') ? 1 : 0;
                    
                    $commodity = 'Unbekannt';
                    if (preg_match('/\(([^)]+)\)/', $commodityLine, $matches)) {
                        $commodity = trim($matches[1]);
                        
                        // Genereller Umlaut-Heilungs-Filter für Ingame-Warennamen
                        $commodity = str_replace(
                            ['Laborzube?r', 'Gartenger?te', 'M?bel', 'St?ckwaren', 'St?ckgut', 'Fl?ssigkeiten', 'K?hlwaren', 'Sch?ttgut'],
                            ['Laborzubehör', 'Gartengeräte', 'Möbel', 'Stückwaren', 'Stückgut', 'Flüssigkeiten', 'Kühlwaren', 'Schüttgut'],
                            $commodity
                        );
                    }

                    $weight = 0;
                    if (preg_match('/(\d+)\s*t/i', $commodityLine, $matches)) {
                        $weight = (int)$matches[1];
                    }

                    // 3. Start- und Zielort extrahieren
                    $fromCityName = trim($cleanLines[$i + 2]);
                    $toCityName = trim($cleanLines[$i + 3]);

                    // 4. Distanz auslesen
                    $distanceLine = $cleanLines[$i + 4];
                    $distance = 0;
                    if (preg_match('/(\d+)\s*km/i', $distanceLine, $matches)) {
                        $distance = (int)$matches[1];
                    }

                    // 5. Erlös auslesen
                    $revenueLine = $cleanLines[$i + 5];
                    $revenue = 0.00;
                    if (preg_match('/Zahlung:\s*([\d,.]+)/i', $revenueLine, $matches)) {
                        $rawRevenue = $matches[1];
                        $cleanedRevenue = str_replace(',', '', $rawRevenue);
                        $revenue = (float)$cleanedRevenue;
                    }

                    // Validierung: Nur aufnehmen, wenn Start, Ziel und Gewicht plausibel sind
                    if ($weight > 0 && $fromCityName !== '' && $toCityName !== '' && $fromCityName !== $toCityName) {
                        $fromCityId = $this->cityService->resolveId($fromCityName, true);
                        $toCityId = $this->cityService->resolveId($toCityName, true);

                        if ($fromCityId && $toCityId) {
                            $fingerprintSource = $freightType . '|' . $commodity . '|' . $fromCityId . '|' . $toCityId . '|' . $weight . '|' . $revenue;
                            $fingerprint = md5($fingerprintSource);

                            $orders[] = [
                                'ingame_order_id' => null,
                                'fingerprint' => $fingerprint,
                                'freight_type' => $freightType,
                                'commodity' => $commodity,
                                'is_adr' => $isAdr,
                                'weight_total' => $weight,
                                'weight_remaining' => $weight,
                                'revenue' => $revenue,
                                'from_city_id' => $fromCityId,
                                'to_city_id' => $toCityId,
                                'from_city_name' => $fromCityName,
                                'to_city_name' => $toCityName,
                                'is_accepted' => $isAccepted ? 1 : 0,
                                'distance_km' => $distance
                            ];
                        }
                    }

                    $i += 6; // Nächsten Block im Multi-Import anspringen
                    continue;

                } catch (Exception $e) {
                    $i++;
                    continue;
                }
            }
            $i++;
        }

        return $orders;
    }

    /**
     * Erkennt, ob eine Zeile den Start-Header eines Frachtblocks darstellt.
     */
    private function isOrderHeader(string $line): bool
    {
        $headers = [
            'silotransport', 'silo', 'flüssigkeit', 'flüssigkeiten', 'kühlwaren', 
            'schüttgutt', 'schüttgut', 'kurier', 'pritsche', 'iso-container', 
            'schwertransport', 'koffer', 'kofferwagen', 'plane', 'stückgut'
        ];
        $lowerLine = strtolower($line);
        foreach ($headers as $header) {
            if (str_contains($lowerLine, $header)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalisiert den einkopierten Frachttyp auf die standardisierte LKW-Kategorie.
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