<?php
declare(strict_types=1);

require_once 'FinanceMapper.php';
require_once 'CityService.php';

/**
 * WarehouseParser: Extrahiert Aufträge aus dem eigenen Lager (angenommene Aufträge)[cite: 3].
 */
class WarehouseParser
{
    private CityService $cityService;

    public function __construct(CityService $cityService)
    {
        $this->cityService = $cityService;
    }

    public function parse(string $text): array
    {
        $orders = [];
        
        // Text normalisieren
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        
        // Anhand der IDN splitten, um die Blöcke zu isolieren
        $parts = preg_split('/IDN(\d+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        
        // $parts[0] ist der Text vor der ersten IDN, danach wechseln sich IDN und Inhalt ab
        for ($i = 1; $i < count($parts); $i += 2) {
            $ingameId = 'IDN' . $parts[$i];
            $block = trim($parts[$i+1]);
            
            $order = $this->parseBlock($ingameId, $block);
            if ($order !== null) {
                $orders[] = $order;
            }
        }
        
        return $orders;
    }

    private function parseBlock(string $ingameId, string $block): ?Order
    {
        $lines = explode("\n", $block);
        $lines = array_values(array_filter(array_map('trim', $lines), fn($line) => $line !== ''));
        
        if (count($lines) < 5) return null;
        
        // 1. Gewicht (Remaining / Total) - z.B. "3 / 31 t"
        $weightRemaining = 0;
        $weightTotal = 0;
        if (preg_match('/(\d+)\s*\/\s*(\d+)\s*t/', $lines[0], $wMatches)) {
            $weightRemaining = (int)$wMatches[1];
            $weightTotal = (int)$wMatches[2];
        }
        
        // 2. Ware (Commodity)
        $commodity = str_replace(['(', ')'], '', $lines[1]);
        
        // 3. Städte (Zeile 2 und 3, da leere Zeilen gefiltert wurden)
        $fromCityId = $this->cityService->resolveId($lines[2], true);
        $toCityId = $this->cityService->resolveId($lines[3], true);
        
        if ($fromCityId === null || $toCityId === null) {
            return null;
        }
        
        // 4. Preis (Umsatz)
        $revenue = 0.0;
        if (isset($lines[4])) {
            $cleanPrice = FinanceMapper::stripSeparators($lines[4]);
            $revenue = FinanceMapper::parseToNumeric($cleanPrice);
        }
        
        // Dummy-Werte für nicht vorhandene Felder in der Lager-Ansicht
        // (werden beim Datenbank-Match ignoriert und nicht überschrieben)
        $fingerprint = '';
        $freightType = 'Unknown'; 
        $isAdr = false; 
        
        return new Order(
            $ingameId,
            $fingerprint,
            $freightType,
            $commodity,
            $isAdr,
            $weightTotal,
            $weightRemaining,
            $revenue,
            $fromCityId,
            $toCityId,
            true // isAccepted = true[cite: 3]
        );
    }
}