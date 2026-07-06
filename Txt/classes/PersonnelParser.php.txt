<?php
declare(strict_types=1);

namespace classes;

use DOMDocument;
use DOMXPath;
use Exception;

/**
 * PersonnelParser: Extrahiert strukturierte Personaldaten (Fahrer & Disponenten) 
 * aus dem HTML-Quelltext des Stellenmarkts.
 */
class PersonnelParser
{
    /**
     * Parst den übergebenen HTML-Quelltext des Stellenmarkts.
     *
     * @param string $html Der kopierte HTML-Quellcode
     * @return array Liste der extrahierten Personen mit allen relevanten Attributen
     * @throws Exception Falls Probleme bei der XML/DOM-Initialisierung auftreten
     */
    public function parse(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        // Interne LibXML-Fehler unterdrücken, um fehlerhaftes HTML zu tolerieren
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        
        // UTF-8 Erzwingung für Sonderzeichen beim Laden
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        
        // Finde alle Bewerbungskarten (Knoten mit der CSS-Klasse "humanresources")
        $nodes = $xpath->query("//div[contains(@class, 'humanresources')]");

        if ($nodes === false || $nodes->length === 0) {
            return [];
        }

        $personnelList = [];

        foreach ($nodes as $node) {
            // 1. Berufsbezeichnung extrahieren
            $jobTitle = '';
            $titleNode = $xpath->query(".//div[contains(@class, 'title')]/span[contains(@class, 'header')]", $node);
            if ($titleNode && $titleNode->length > 0) {
                $jobTitle = trim($titleNode->item(0)->textContent);
            }

            // 2. Ingame-ID aus dem Submit-Button (selectemp-Parameter) gewinnen
            $ingameId = '';
            $buttonNode = $xpath->query(".//input[@type='submit' or @type='button']", $node);
            if ($buttonNode && $buttonNode->length > 0) {
                $onclick = $buttonNode->item(0)->getAttribute('onclick');
                if (preg_match('/selectemp\((\d+)\)/', $onclick, $matches)) {
                    $ingameId = $matches[1];
                }
            }

            // Hole den reinen Textinhalt des Containers für feinkörnige Regex-Suchen
            $fullText = $node->textContent;

            // 3. Vorname und Nachname isolieren
            $firstName = '';
            $lastName = '';
            
            $firstNameNode = $xpath->query(".//div[contains(text(), 'Vorname') or contains(text(), 'Vorname :')]/span", $node);
            if ($firstNameNode && $firstNameNode->length > 0) {
                $firstName = trim($firstNameNode->item(0)->textContent);
            }
            
            $lastNameNode = $xpath->query(".//div[contains(text(), 'Nachname') or contains(text(), 'Nachname :')]/span", $node);
            if ($lastNameNode && $lastNameNode->length > 0) {
                $lastName = trim($lastNameNode->item(0)->textContent);
            }

            // 4. Alter auslesen
            $age = 0;
            if (preg_match('/Alter\s*:\s*(\d+)/ui', $fullText, $matches)) {
                $age = (int)$matches[1];
            }

            // 5. Zuverlässigkeit extrahieren
            $reliabilityVal = 0;
            if (preg_match('/Zuverlässigkeit\s*:\s*(\d+)/ui', $fullText, $matches)) {
                $reliabilityVal = (int)$matches[1];
            }

            // 6. Gehaltswunsch auslesen und bereinigen (Stufe 1 & 2 der Geldlogik)
            $salary = 0.00;
            $salaryNode = $xpath->query(".//div[contains(text(), 'Gehaltswunsch') or contains(text(), 'Gehaltswunsch :')]/span", $node);
            if ($salaryNode && $salaryNode->length > 0) {
                $rawSalary = trim($salaryNode->item(0)->textContent);
                $cleanedSalary = str_replace(',', '', $rawSalary); // Tausender-Kommas entfernen (Stufe 1)
                $cleanedSalary = preg_replace('/[^\d.]/', '', $cleanedSalary); // Nur Ziffern und Dezimalpunkt behalten
                $salary = (float)$cleanedSalary; // Konvertierung in Float (Stufe 2)
            }

            // 7. Rollenspezifischen Skill ermitteln
            $skillVal = 0;
            $normalizedJob = strtolower($jobTitle);

            if ($normalizedJob === 'disponent') {
                if (preg_match('/Verwaltung\s*:\s*(\d+)/ui', $fullText, $matches)) {
                    $skillVal = (int)$matches[1];
                }
            } elseif ($normalizedJob === 'fahrer') {
                if (preg_match('/Fahrkönnen\s*:\s*(\d+)/ui', $fullText, $matches)) {
                    $skillVal = (int)$matches[1];
                }
            } elseif ($normalizedJob === 'kfz-techniker') {
                if (preg_match('/Kfz\.Mech\.\s*:\s*(\d+)/ui', $fullText, $matches)) {
                    $skillVal = (int)$matches[1];
                }
            }

            // 8. Gefahrguterlaubnis (ADR) extrahieren
            $adrPermit = 0;
            if (preg_match('/Gefahrguterlaubnis\s*:\s*(\d+)/ui', $fullText, $matches)) {
                $adrPermit = (int)$matches[1];
            }

            // 9. Punkte in der Kartei extrahieren
            $penaltyPoints = 0;
            if (preg_match('/Punkte\s+in\s+der\s+Kartei\s*:\s*(\d+)/ui', $fullText, $matches)) {
                $penaltyPoints = (int)$matches[1];
            }

            // Personaldatensatz nur aufnehmen, wenn eine gültige Ingame-ID extrahiert werden konnte
            if ($ingameId !== '') {
                $personnelList[] = [
                    'job_title' => $jobTitle,
                    'ingame_id' => $ingameId,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'age' => $age,
                    'skill_val' => $skillVal,
                    'reliability_val' => $reliabilityVal,
                    'salary' => $salary,
                    'adr_permit' => $adrPermit,
                    'penalty_points' => $penaltyPoints
                ];
            }
        }

        return $personnelList;
    }
}