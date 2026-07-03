<?php
declare(strict_types=1);

require_once 'FinanceMapper.php';

/**
 * VehicleMarketParser: Extrahiert alle Gebrauchtwagen-Angebote aus dem Fahrzeughandel (PH 5.1).
 */
class VehicleMarketParser
{
    /**
     * Intelligentes Mapping: Bild-Dateiname -> Datenbank ENUM
     */
    private const TYPE_MAPPING = [
        'lkw_kurier.png'          => 'Kurier',
        'lkw_kleintransport.png'  => 'Stückgut',
        'lkw_schuettgut.png'      => 'Schüttgut',
        'lkw_pritsche.png'        => 'Pritsche',
        'lkw_plane.png'           => 'Plane',
        'lkw_koffer.png'          => 'Koffer',
        'lkw_kuehl.png'           => 'Kühlwagen',
        'lkw_silo.png'            => 'Silo',
        'lkw_tankwagen.png'       => 'Tankwagen',
        'lkw_schwertransport.png' => 'Schwertransport',
        'lkw_isocontainer.png'    => 'ISO-Container',
        'lkw_gigaliner.png'       => 'Super-Liner'
    ];

    /**
     * @param string $html Der rohe HTML-Quelltext
     * @return array Ein Array mit assoziativen Arrays aller gefundenen LKW
     * @throws Exception Bei Fehlern im Parsing-Prozess
     */
    public function parse(string $html): array
    {
        $vehicles = [];

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Alle DIVs mit der Klasse 'fahrzeugoffer' suchen
        $nodes = $xpath->query("//div[contains(@class, 'fahrzeugoffer')]");

        if ($nodes === false || $nodes->length === 0) {
            return [];
        }

        foreach ($nodes as $node) {
            $vehicleData = [];

            // --- 1. ID und Preis aus dem openPhone-Script extrahieren ---
            $contactNode = $xpath->query(".//span[@class='handykontakt']/a", $node);
            if ($contactNode->length > 0) {
                $onclick = $contactNode->item(0)->getAttribute('onclick');
                // Beispiel: openPhone(4151822,0,'202,070.00',...)
                if (preg_match('/openPhone\((\d+),[^,]+,\'([^\']+)\'/', $onclick, $matches)) {
                    $vehicleData['ingame_vehicle_id'] = $matches[1];
                    // Preis über die 3-Stufen-Geldlogik bereinigen (PH 3.1)
                    $rawPrice = FinanceMapper::stripSeparators($matches[2]);
                    $vehicleData['price'] = FinanceMapper::parseToNumeric($rawPrice);
                }
            }

            // --- 2. Verkaufsort (Location) ---
            $locationNode = $xpath->query(".//span[@class='location']", $node);
            if ($locationNode->length > 0) {
                $rawLocation = trim($locationNode->item(0)->nodeValue);
                // Klammern entfernen z.B. "(Karlsruhe)" -> "Karlsruhe"
                $vehicleData['location_label'] = trim(str_replace(['(', ')'], '', $rawLocation));
            }

            // --- 3. Fahrzeugtyp und Tuning aus dem Bild ---
            $imgNode = $xpath->query(".//div[@class='vehicleimage']/img", $node);
            if ($imgNode->length > 0) {
                $src = $imgNode->item(0)->getAttribute('src');
                $filename = basename($src);
                
                // Automatisches Mapping auf das ENUM
                $vehicleData['vehicle_type'] = self::TYPE_MAPPING[$filename] ?? 'Unbekannt';

                // Tuning extrahieren: "Aerodynamik Paket: ja , Motor Tuning: ja , Stauwarner: ja"
                $title = strtolower($imgNode->item(0)->getAttribute('title'));
                $hasMotor = str_contains($title, 'motor tuning: ja');
                $hasAero = str_contains($title, 'aerodynamik paket: ja');
                $hasStau = str_contains($title, 'stauwarner: ja');

                $vehicleData['has_tuning_motor'] = $hasMotor;
                $vehicleData['has_tuning_aero'] = $hasAero;
                $vehicleData['has_tuning_stau'] = $hasStau;

                // Tuning-Wert summieren (PH 2.6.3.1)
                $tuningValue = 0;
                if ($hasMotor) $tuningValue += 3000;
                if ($hasAero) $tuningValue += 4000;
                if ($hasStau) $tuningValue += 1000;
                $vehicleData['tuning_value_total'] = $tuningValue;
            }

            // --- 4. Technische Daten aus der Tabelle ---
            $tdNodes = $xpath->query(".//div[@class='stats']//td", $node);
            for ($i = 0; $i < $tdNodes->length; $i++) {
                $text = trim($tdNodes->item($i)->nodeValue);

                if (str_starts_with($text, 'Fracht:')) {
                    // Kapazität extrahieren (z.B. "Flüssigkeiten 20 t" -> "20")
                    if (preg_match('/(\d+)\s*t/i', $text, $capMatches)) {
                        $vehicleData['capacity_t'] = (int)$capMatches[1];
                    }
                } elseif (str_starts_with($text, 'Baujahr:')) {
                    $valNode = $tdNodes->item($i + 1);
                    // z.B. "05/2013" -> "2013"
                    if ($valNode && preg_match('/(\d{4})/', $valNode->nodeValue, $yearMatches)) {
                        $vehicleData['year_built'] = (int)$yearMatches[1];
                    }
                } elseif (str_starts_with($text, 'km-Stand:')) {
                    $valNode = $tdNodes->item($i + 1);
                    if ($valNode) {
                        $rawKm = preg_replace('/[^\d]/', '', $valNode->nodeValue);
                        $vehicleData['km_stand'] = (int)$rawKm;
                    }
                } elseif (str_starts_with($text, 'Zustand:')) {
                    $valNode = $tdNodes->item($i + 1);
                    if ($valNode) {
                        $vehicleData['condition_pct'] = (float)trim($valNode->nodeValue);
                    }
                }
            }

            // Nur hinzufügen, wenn die Pflicht-ID gefunden wurde
            if (isset($vehicleData['ingame_vehicle_id'])) {
                $vehicles[] = $vehicleData;
            }
        }

        return $vehicles;
    }
}