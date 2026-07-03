<?php
declare(strict_types=1); // Typsicherheit (PH 1.1.3.1)

/**
 * PersonnelParser: Extrahiert sämtliche Personaldaten (Fahrer, Disponenten, etc.) 
 * aus dem HTML-Quelltext des Stellenmarktes (PH 5.3)[cite: 5].
 */
class PersonnelParser
{
    /**
     * @param string $html Der rohe HTML-Quelltext
     * @return array Ein Array mit assoziativen Arrays aller gefundenen Personen
     * @throws Exception Bei Fehlern im Parsing-Prozess (PH 1.3.5.1)
     */
    public function parse(string $html): array
    {
        $personnel = [];

        // DOMDocument initialisieren (PH 2.3.4.1)[cite: 5]
        $dom = new DOMDocument();
        // HTML-Errors unterdrücken, da wir rohen Quellcode importieren
        libxml_use_internal_errors(true);
        // HTML laden (mb_convert_encoding sichert UTF-8 Integrität)
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        // XPath für gezielte Knoten-Selektion (PH 2.3.4.2)[cite: 5]
        $xpath = new DOMXPath($dom);

        // Alle DIVs mit der Klasse 'humanresources' suchen[cite: 5]
        $nodes = $xpath->query("//div[contains(@class, 'humanresources')]");

        if ($nodes === false || $nodes->length === 0) {
            return []; // Nichts gefunden
        }

        foreach ($nodes as $node) {
            $personData = [];

            // --- 1. Berufsgruppe ermitteln ---
            $jobTitleNode = $xpath->query(".//span[@class='header']", $node);
            if ($jobTitleNode->length > 0) {
                $personData['job_title'] = trim($jobTitleNode->item(0)->nodeValue);
            }

            // --- 2. Ingame ID ermitteln (aus dem selectemp()-Aufruf) ---
            $buttonNode = $xpath->query(".//input[@type='submit' and @name='baction']", $node);
            if ($buttonNode->length > 0) {
                $onclick = $buttonNode->item(0)->getAttribute('onclick');
                // Regex um die Zahl aus 'selectemp(10658917)' zu holen
                if (preg_match('/selectemp\((\d+)\)/', $onclick, $matches)) {
                    $personData['ingame_id'] = $matches[1];
                }
            }

            // --- 3. Name und Alter (Linke Spalte) ---
            // Wir suchen alle DIVs in der ersten Spalte (td) der Tabelle
            $leftColDivs = $xpath->query(".//td[1]/div/div", $node);
            foreach ($leftColDivs as $div) {
                $text = trim($div->nodeValue);
                if (str_starts_with($text, 'Vorname')) {
                    $personData['first_name'] = $this->extractBoldValue($xpath, $div);
                } elseif (str_starts_with($text, 'Nachname')) {
                    $personData['last_name'] = $this->extractBoldValue($xpath, $div);
                } elseif (str_starts_with($text, 'Alter')) {
                    $personData['age'] = (int)$this->extractValueAfterColon($text);
                }
            }

            // --- 4. Qualifikationen und Gehalt (Rechte Spalte) ---
            $rightColDivs = $xpath->query(".//td[2]/div/div", $node);
            foreach ($rightColDivs as $div) {
                $text = trim($div->nodeValue);
                
                // Generischer Skill-Wert (Fahrkönnen, Verwaltung, Kfz.Mech.)
                if (str_contains($text, ':') && !str_starts_with($text, 'Zuverlässigkeit') && !str_starts_with($text, 'Gehaltswunsch')) {
                     // Nur setzen wenn nicht schon belegt durch z.B. Gefahrguterlaubnis
                     if(!isset($personData['skill_val'])){
                         // Bei Bürokräften ist der Skill leer (<span></span>)
                         $skillVal = $this->extractBoldValue($xpath, $div);
                         $personData['skill_val'] = $skillVal ? (int)$skillVal : 0;
                     }
                }
                
                if (str_starts_with($text, 'Zuverlässigkeit')) {
                    $personData['reliability_val'] = (int)$this->extractBoldValue($xpath, $div);
                } elseif (str_starts_with($text, 'Gehaltswunsch')) {
                    $personData['salary'] = (float)$this->extractBoldValue($xpath, $div);
                } elseif (str_starts_with($text, 'Gefahrguterlaubnis')) {
                    $val = $this->extractValueAfterColon($text);
                    $personData['adr_permit'] = ((int)$val > 0);
                } elseif (str_starts_with($text, 'Punkte in der Kartei')) {
                    $personData['penalty_points'] = (int)$this->extractValueAfterColon($text);
                }
            }

            // Sicherstellen, dass optionale Werte für Nicht-Fahrer (z.B. ADR) existieren
            $personData['adr_permit'] = $personData['adr_permit'] ?? false;
            $personData['penalty_points'] = $personData['penalty_points'] ?? 0;

            // Nur hinzufügen, wenn die Pflicht-ID gefunden wurde
            if (isset($personData['ingame_id'])) {
                $personnel[] = $personData;
            }
        }

        return $personnel;
    }

    /**
     * Hilfsmethode: Extrahiert den Text aus einem <span class="bold"> innerhalb eines Knotens.
     */
    private function extractBoldValue(DOMXPath $xpath, DOMNode $node): string
    {
        $boldNode = $xpath->query(".//span[@class='bold']", $node);
        if ($boldNode->length > 0) {
            return trim($boldNode->item(0)->nodeValue);
        }
        return '';
    }

    /**
     * Hilfsmethode: Extrahiert den Wert nach einem Doppelpunkt (z.B. "Alter : 50" -> "50")
     */
    private function extractValueAfterColon(string $text): string
    {
        $parts = explode(':', $text);
        return isset($parts[1]) ? trim($parts[1]) : '';
    }
}