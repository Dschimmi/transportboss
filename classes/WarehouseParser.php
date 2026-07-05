<?php
declare(strict_types=1);

namespace classes;

use Exception;

/**
 * WarehouseParser
 *
 * Analysiert den mehrzeiligen, tabellarisch einkopierten Text aus dem eigenen Lager
 * (angenommene Aufträge) und extrahiert strukturierte Datenobjekte inklusive der Ingame-ID (IDN)
 * sowie dem Mengenfortschritt (Teilladungen).
 *
 * @author TransportBoss Development
 * @version 1.1.1
 */
class WarehouseParser
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
     * Parst den einkopierten Textblock des eigenen Lagers.
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
         * Wir durchlaufen den Text zeilenweise auf der Suche nach einer Ingame-IDN oder 
         * einem Fracht-Header, der den Beginn eines eigenen Lager-Auftrags-Blocks markiert.
         */
        while ($i < $lineCount) {
            $line = $cleanLines[$i];

            // Prüfen, ob die Zeile eine Ingame-IDN (z. B. "IDN10620759") enthält oder ein Fracht-Header ist
            if ($this->isWarehouseHeader($line, $ingameId)) {
                try {
                    // Block-Sicherheit prüfen: Ein vollständiger Block benötigt mindestens 7 Folgezeilen
                    if ($i + 7 >= $lineCount) {
                        break;
                    }

                    // Falls die IDN auf einer eigenen Zeile stand und die nächste Zeile den Frachttyp enthält
                    $headerLine = $line;
                    $offset = 1;
                    if ($ingameId !== '' && $this->isFreightHeaderOnly($cleanLines[$i + 1])) {
                        $headerLine = $cleanLines[$i + 1];
                        $offset = 2;
                    }

                    // 1. Frachttyp bestimmen und normalisieren
                    $freightType = $this->normalizeFreightType($headerLine);

                    // 2. Ware, Gefahrgut-Status und Gewicht bestimmen (z.B. "(Baustoffe) 8 / 20 t")
                    $commodityLine = $cleanLines[$i + $offset];
                    $isAdr = str_contains(strtolower($commodityLine), '[gefahrgut]') ? 1 : 0;
                    
                    // Ware extrahieren (Text in runden Klammern)
                    $commodity = 'Unbekannt';
                    if (preg_match('/\(([^)]+)\)/', $commodityLine, $matches)) {
                        $commodity = trim($matches[1]);
                    }

                    // Gewicht extrahieren: Berücksichtigung von Fortschritten wie "8 / 20 t"
                    $weightRemaining = 0;
                    $weightTotal = 0;
                    if (preg_match('/(\d+)\s*\/\s*(\d+)\s*t/i', $commodityLine, $matches)) {
                        // Fraktionierter Wert gefunden: Restmenge / Gesamtmenge
                        $weightRemaining = (int)$matches[1];
                        $weightTotal = (int)$matches[2];
                    } elseif (preg_match('/(\d+)\s*t/i', $commodityLine, $matches)) {
                        // Einfacher Wert gefunden: Gesamtgewicht = Restgewicht
                        $weightRemaining = (int)$matches[1];
                        $weightTotal = (int)$matches[1];
                    }

                    // 3. Start- und Zielort extrahieren
                    $fromCityName = $cleanLines[$i + $offset + 1];
                    $toCityName = $cleanLines[$i + $offset + 2];

                    // 4. Distanz auslesen
                    $distanceLine = $cleanLines[$i + $offset + 3];
                    $distance = 0;
                    if (preg_match('/(\d+)\s*km/i', $distanceLine, $matches)) {
                        $distance = (int)$matches[1];
                    }

                    // 5. Erlös auslesen (Zahlung: x,xxx.xx)
                    $revenueLine = $cleanLines[$i + $offset + 4];
                    $revenue = 0.00;
                    if (preg_match('/Zahlung:\s*([\d,.]+)/i', $revenueLine, $matches)) {
                        $rawRevenue = $matches[1];
                        $cleanedRevenue = str_replace(',', '', $rawRevenue); // Tausenderpunkte entfernen
                        $revenue = (float)$cleanedRevenue;
                    }

                    // 6. Städte über den CityService in IDs auflösen
                    $fromCityId = $this->cityService->resolveId($fromCityName, true);
                    $toCityId = $this->cityService->resolveId($toCityName, true);

                    // Falls keine IDN extrahiert werden konnte, generieren wir einen Fallback-String
                    $finalIngameId = $ingameId !== '' ? $ingameId : null;

                    // Strukturiertes Lager-Auftrags-Array hinzufügen
                    $orders[] = [
                        'ingame_order_id' => $finalIngameId,
                        'fingerprint' => null, // Wir identifizieren Lageraufträge primär über die IDN
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
                        'is_accepted' => 1, // Fest im eigenen Lager verankert
                        'distance_km' => $distance
                    ];

                    // Index um die verarbeitete Blockgröße weiterbewegen
                    $i += ($offset + 7);
                    continue;

                } catch (Exception $e) {
                    // Stabilitätssicherung: Fehlerhafte Blöcke überspringen
                    $i++;
                    continue;
                }
            }
            $i++;
        }

        return $orders;
    }

    /**
     * Erkennt, ob eine Zeile den Start eines Lager-Frachtblocks bildet und extrahiert ggf. die IDN.
     */
    private function isWarehouseHeader(string $line, string &$ingameId): bool
    {
        $ingameId = '';
        // Sucht nach Mustern wie IDN10620759 oder rein numerischen IDNs (Lager-Format)
        if (preg_match('/(IDN\s*\d+|\b\d{8,}\b)/i', $line, $matches)) {
            $ingameId = str_replace(' ', '', strtoupper($matches[1]));
            return true;
        }
        return $this->isFreightHeaderOnly($line);
    }

    /**
     * Prüft rein auf das Vorhandensein eines bekannten Fracht-Keywords.
     */
    private function isFreightHeaderOnly(string $line): bool
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