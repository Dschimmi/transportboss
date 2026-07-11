<?php
declare(strict_types=1);

namespace classes;

use DOMDocument;
use DOMXPath;
use Exception;

/**
 * RankingParser
 *
 * Analysiert den kompletten HTML-Quelltext der Ingame-Rangliste
 * und extrahiert Platzierung, Spieler-ID, Spieler-Name, LKW-Anzahl,
 * Firmenwert, Firmenstufe und den Hauptsitz der Speditionen.
 *
 * @author TransportBoss Development
 * @version 2.0.0
 */
class RankingParser
{
    /**
     * Parst den HTML-Quelltext der Rangliste und liefert ein strukturiertes Array zurück.
     *
     * @param string $html Der kopierte HTML-Quellcode
     * @return array Liste der extrahierten Ranglisten-Einträge
     */
    public function parse(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        // XML-Fehler unterdrücken
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $rankings = [];

        // Suche alle Ranglisten-Zeilen im Dokument
        $rows = $xpath->query("//tr[contains(@class, 'rankingline')]");

        if ($rows === false || $rows->length === 0) {
            return [];
        }

        foreach ($rows as $row) {
            // Spalten (td) der aktuellen Zeile laden
            $tds = $xpath->query(".//td", $row);

            // Eine gültige Ingame-Tabellenzeile benötigt exakt 9 Spalten (inkl. Trophy)
            if ($tds === false || $tds->length < 9) {
                continue;
            }

            // 1. ONLINE-STATUS EXTRAHIEREN (Spalte 1, Index 0)
            $isOnline = 0;
            $imgNode = $xpath->query(".//img[contains(@src, 'onlinemarker_')]", $tds->item(0))->item(0);
            if ($imgNode) {
                $src = $imgNode->getAttribute('src');
                if (str_contains(strtolower($src), 'onlinemarker_on')) {
                    $isOnline = 1;
                }
            }

            // 2. PLATZIERUNG (RANK) EXTRAHIEREN (Spalte 2, Index 1, z.B. "160.")
            $rankText = trim($tds->item(1)->textContent);
            $rankVal = (int)preg_replace('/[^\d]/', '', $rankText);

            if ($rankVal <= 0) {
                continue; 
            }

            // Spalte 3 (Index 2) ist die Trophäen-Spalte (Trophy) -> wird übersprungen

            // 3. SPIELER-ID & SPIELER-NAME EXTRAHIEREN (Spalte 4, Index 3)
            $profileId = 0;
            $playerName = 'Unbekannt';
            $linkNode = $xpath->query(".//a[contains(@href, 'profileId=')]", $tds->item(3))->item(0);
            
            if ($linkNode) {
                $playerName = trim($linkNode->textContent);
                $href = $linkNode->getAttribute('href');
                if (preg_match('/profileId=(\d+)/i', $href, $matches)) {
                    $profileId = (int)$matches[1];
                }
            }

            if ($profileId === 0) {
                continue; // Ohne ID verwerfen wir den Datensatz aus Sicherheitsgründen
            }

            // 4. LKW-ANZAHL EXTRAHIEREN (Spalte 5, Index 4)
            $truckCount = (int)trim($tds->item(4)->textContent);

            // Spalte 6 (Index 5) ist eine leere Platzhalter-Spalte im Spiel-Design -> wird übersprungen

            // 5. FIRMENWERT EXTRAHIEREN (Spalte 7, Index 6)
            $companyValue = 0.00;
            
            // Suche den Span mit der Klasse "haben" (positiver Wert)
            $habenSpan = $xpath->query(".//span[contains(@class, 'haben')]", $tds->item(6))->item(0);
            if ($habenSpan) {
                try {
                    // Stufe 1 & 2 der Geldlogik ausführen
                    $cleanedVal = \FinanceMapper::stripSeparators($habenSpan->textContent);
                    $companyValue = \FinanceMapper::parseToNumeric($cleanedVal);
                } catch (Exception $e) {
                    $companyValue = 0.00;
                }
            } else {
                // Check auf Insolvenz-Klasse (.soll, z.B. "-insolvent-")
                $sollSpan = $xpath->query(".//span[contains(@class, 'soll')]", $tds->item(6))->item(0);
                if ($sollSpan) {
                    $companyValue = 0.00;
                }
            }

            // 6. FIRMENSTUFE (TIER) EXTRAHIEREN (Spalte 8, Index 7)
            $companyTier = (int)trim($tds->item(7)->textContent);

            // 7. FIRMENSITZ/ZENTRALE EXTRAHIEREN (Spalte 9, Index 8)
            $hqCityName = 'Unbekannt';
            $cityTd = $tds->item(8);
            if ($cityTd) {
                $hqCityName = $cityTd->getAttribute('title');
                if ($hqCityName === '') {
                    $hqCityName = trim($cityTd->textContent);
                }
            }

            $rankings[] = [
                'profile_id' => $profileId,
                'player_name' => $playerName,
                'rank_val' => $rankVal,
                'truck_count' => $truckCount,
                'company_value' => $companyValue,
                'company_tier' => $companyTier,
                'hq_city_name' => $hqCityName,
                'is_online' => $isOnline
            ];
        }

        return $rankings;
    }
}