<?php
declare(strict_types=1);

namespace classes;

use DOMDocument;
use DOMXPath;
use Exception;

/**
 * OwnFleetParser
 *
 * Analysiert strukturell den HTML-Quelltext der eigenen Fahrzeugübersicht 
 * (/pages/Fuhrpark.shtml) über DOMDocument und XPath-Selektoren.
 * Extrahiert ID, Kilometerstand, Zustand und aktuellen Standort der LKW.
 *
 * @author TransportBoss Development
 * @version 2.1.0
 */
class OwnFleetParser
{
    /**
     * Parst das HTML und liefert strukturierte Fahrzeug-Updates zurück.
     *
     * @param string $html Der kopierte HTML-Quelltext
     * @param array $availableCitiesMap Liste lizensierter Städte [lowercased_name => city_id]
     * @return array Liste extrahierter LKW-Daten
     */
    public function parse(string $html, array $availableCitiesMap): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        // XML-Fehler unterdrücken
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        // UTF-8 Codierung beim Laden erzwingen
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $trucks = [];

        // Wir iterieren über alle Info-Knoten, da diese garantiert ID und Zustand beieinander halten
        $infoNodes = $xpath->query("//div[contains(@class, 'vehicleinfo')]");

        if ($infoNodes === false || $infoNodes->length === 0) {
            return [];
        }

        foreach ($infoNodes as $infoNode) {
            $vehicleId = null;
            $km = null;
            $condition = null;
            $cityId = null;

            // 1. LKW-ID AUSLESEN (aus den Links im Info-Knoten)
            $linkNodes = $xpath->query(".//a[contains(@href, 'selectedLkw=') or contains(@href, 'psv=')]", $infoNode);
            if ($linkNodes && $linkNodes->length > 0) {
                $href = $linkNodes->item(0)->getAttribute('href');
                if (preg_match('/(?:selectedLkw|psv)=(\d{6,8})/i', $href, $idMatches)) {
                    $vehicleId = $idMatches[1];
                }
            }

            if ($vehicleId === null) {
                continue;
            }

            // 2. FAHRZEUGZUSTAND (%) AUSLESEN (direkt aus dem statelabel-Span)
            $stateLabel = $xpath->query(".//span[contains(@class, 'statelabel')]", $infoNode)->item(0);
            if ($stateLabel) {
                $condition = (float)trim($stateLabel->textContent);
            } else {
                if (preg_match('/Zustand\s*:\s*([\d.]+)/ui', $infoNode->textContent, $condMatches)) {
                    $condition = (float)$condMatches[1];
                }
            }

            // 3. ZUM ZUGEHÖRIGEN LKW-CARD CONTAINER SPRINGEN (Preceding-Sibling)
            // Holt das erste vorherige DIV-Element, ignoriert dabei alle Whitespaces
            $container = $xpath->query("preceding-sibling::div[1]", $infoNode)->item(0);

            if ($container) {
                // A. Kilometerstand parsen (z.B. "km-Stand:881,282.0" -> 881282)
                $statsBottom = $xpath->query(".//div[contains(@class, 'vehiclestatsbottom')]", $container)->item(0);
                if ($statsBottom) {
                    if (preg_match('/km-Stand\s*:\s*([\d,.]+)/i', $statsBottom->textContent, $kmMatches)) {
                        $cleanKm = str_replace([',', ' '], '', $kmMatches[1]);
                        if (str_contains($cleanKm, '.')) {
                            $parts = explode('.', $cleanKm);
                            $cleanKm = $parts[0];
                        }
                        $km = (int)$cleanKm;
                    }
                }

                // B. Standort (Stadt) auflösen
                // Fall A: Fahrzeug parkt -> suche "parkt in : [Stadt]"
                $statsNode = $xpath->query(".//div[contains(@class, 'vehiclestats')]", $container)->item(0);
                if ($statsNode && str_contains(mb_strtolower($statsNode->textContent), 'parkt in')) {
                    if (preg_match('/parkt in\s*:\s*([^-\s]+)/ui', $statsNode->textContent, $parkMatches)) {
                        $cityName = trim($parkMatches[1]);
                        $lowerCity = mb_strtolower($cityName);
                        if (isset($availableCitiesMap[$lowerCity])) {
                            $cityId = $availableCitiesMap[$lowerCity];
                        }
                    }
                }

                // Fall B: Fahrzeug fährt -> suche Startstadt in der Route (z.B. "Bielefeld - Aachen")
                if ($cityId === null) {
                    $trackNode = $xpath->query(".//div[contains(@class, 'vehiclestatstrack')]", $container)->item(0);
                    if ($trackNode) {
                        if (preg_match('/([A-ZÄÖÜa-zäöüß]+)\s*-\s*[A-ZÄÖÜa-zäöüß]+/u', $trackNode->textContent, $routeMatches)) {
                            $startCityName = trim($routeMatches[1]);
                            $lowerCity = mb_strtolower($startCityName);
                            if (isset($availableCitiesMap[$lowerCity])) {
                                $cityId = $availableCitiesMap[$lowerCity];
                            }
                        }
                    }
                }

                // Fall C: Fallback-Scan über den Container-Text
                if ($cityId === null) {
                    $lowerText = mb_strtolower($container->textContent);
                    foreach ($availableCitiesMap as $cityName => $id) {
                        if ($cityName === '') continue;
                        $pattern = '/\b' . preg_quote($cityName, '/') . '\b/u';
                        if (preg_match($pattern, $lowerText)) {
                            $cityId = $id;
                            break;
                        }
                    }
                }
            }

            $trucks[] = [
                'ingame_vehicle_id' => $vehicleId,
                'km_stand' => $km,
                'condition_pct' => $condition,
                'current_city_id' => $cityId
            ];
        }

        return $trucks;
    }
}