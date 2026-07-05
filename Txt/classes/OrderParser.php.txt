<?php
declare(strict_types=1);

require_once 'FinanceMapper.php';
require_once 'CityService.php';

/**
 * OrderParser: Extrahiert Aufträge aus dem HTML-Text des Auftragspools und des Lagers (PH 3.2.1).
 */
class OrderParser
{
    private CityService $cityService;

    public function __construct(CityService $cityService)
    {
        $this->cityService = $cityService;
    }

    /**
     * @param string $html Der rohe Text aus dem Spiel (aus der Textarea kopiert)
     * @param bool $isAccepted True, wenn es aus dem Lager kommt. False, wenn es vom Markt kommt.
     * @return array Array mit initialisierten Order-Objekten
     */
    public function parse(string $html, bool $isAccepted): array
    {
        $orders = [];
        
        // Da die Daten nicht in strukturiertem HTML (wie beim Personal) kopiert werden, 
        // sondern oft als roher Copy-Paste-Text aus einer Tabelle, normalisieren wir die Zeilenumbrüche.
        $text = str_replace(["\r\n", "\r"], "\n", $html);
        
        // Wir splitten nach dem typischen Muster "Zahlung: ... \n Vrzg.Strf. ... \n 0 / 0 / 0 / 0"
        // was das Ende eines jeden Auftrags in der kopierten Tabelle markiert.
        $blocks = preg_split('/Vrzg\.Strf\.\s*[\d,.]+\s*\n\s*\d+\s*\/\s*\d+\s*\/\s*\d+\s*\/\s*\d+/i', $text);

        foreach ($blocks as $block) {
            $block = trim($block);
            if (empty($block)) continue;

            $order = $this->parseBlock($block, $isAccepted);
            if ($order !== null) {
                $orders[] = $order;
            }
        }

        return $orders;
    }

    /**
     * Parst einen einzelnen Auftrags-Block.
     */
    private function parseBlock(string $block, bool $isAccepted): ?Order
    {
        $lines = explode("\n", $block);
        // Leere Zeilen bereinigen
        $lines = array_values(array_filter(array_map('trim', $lines), fn($line) => $line !== ''));

        if (count($lines) < 5) return null;

        // --- 1. Frachttyp und Gefahrgut (PH 3.3.1)[cite: 3] ---
        // Zeile 1: z.B. "Silotransport Silo" -> Wir nehmen das letzte Wort als Typ
        $typeParts = explode(' ', $lines[0]);
        $freightType = end($typeParts);

        // Zeile 2: z.B. "(Baustoffe) 27 t" ODER "(Getreide) [Gefahrgut] 22 t"
        $line2 = $lines[1];
        
        // ADR-Markierung prüfen und entfernen (PH 3.3.1.2 & 3.3.1.3)[cite: 3]
        $isAdr = false;
        if (preg_match('/\[\s?Gefahrgut\s?\]/i', $line2)) {
            $isAdr = true;
            $line2 = preg_replace('/\[\s?Gefahrgut\s?\]/i', '', $line2);
        }

        // Warenbezeichnung (in Klammern)
        $commodity = '';
        if (preg_match('/\((.*?)\)/', $line2, $matches)) {
            $commodity = $matches[1];
        }

        // Gewicht (Zahl vor dem 't')
        $weight = 0;
        if (preg_match('/(\d+)\s*t/i', $line2, $matches)) {
            $weight = (int)$matches[1];
        }

        // --- 2. Städte extrahieren und in IDs auflösen (PH 3.2.2.2)[cite: 3] ---
        // Zeile 3 und 4 sind die Städte
        $fromCityName = $lines[2];
        $toCityName = $lines[3];

        $fromCityId = $this->cityService->resolveId($fromCityName, true);
        $toCityId = $this->cityService->resolveId($toCityName, true);

        // Wenn eine Stadt aufgrund der Sicherheitsregel (zu kurz) abgewiesen wurde, Auftrag überspringen
        if ($fromCityId === null || $toCityId === null) {
            return null;
        }

        // --- 3. Finanzen (PH 3.1)[cite: 3] ---
        // Eine der unteren Zeilen beginnt mit "Zahlung:"
        $revenue = 0.0;
        foreach ($lines as $line) {
            if (str_starts_with($line, 'Zahlung:')) {
                $rawPrice = str_replace('Zahlung:', '', $line);
                $cleanPrice = FinanceMapper::stripSeparators(trim($rawPrice));
                $revenue = FinanceMapper::parseToNumeric($cleanPrice);
                break;
            }
        }

        // --- 4. Ingame ID & Fingerprint (PH 3.4.1)[cite: 3] ---
        // Ingame-ID gibt es nur im Lager, wir lassen sie bei Börsen-Aufträgen auf NULL
        $ingameId = null; 
        
        // MD5 Fingerprint generieren (PH 3.4.1.2)[cite: 3]
        $fingerprintString = sprintf("%s|%s|%s|%d|%d|%d|%f", 
            $freightType, $commodity, $isAdr ? '1' : '0', 
            $fromCityId, $toCityId, $weight, $revenue
        );
        $fingerprint = md5($fingerprintString);

        return new Order(
            $ingameId, 
            $fingerprint,
            $freightType, 
            $commodity, 
            $isAdr, 
            $weight, // Total
            $weight, // Remaining (bei neuem Import identisch)
            $revenue, 
            $fromCityId, 
            $toCityId, 
            $isAccepted
        );
    }
}