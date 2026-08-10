<?php
declare(strict_types=1);

/**
 * CityService: Verwaltet Lese- und Schreibzugriffe auf die Tabelle cities[cite: 3]
 */
class CityService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Prüft zentral und systemweit (DRY), ob ein String ein valider Städtename ist.
     * Schließt IDN-Nummern, reine Zahlen, Geldbeträge und Ingame-Schlüsselwörter aus.
     */
    public static function isValidCityName(string $name): bool
    {
        $clean = trim($name);
        if ($clean === '' || mb_strlen($clean) < 2) {
            return false;
        }

        if (preg_match('/^IDN/i', $clean) 
            || is_numeric($clean) 
            || preg_match('/(Zahlung|Gefahrgut|Anfahrt|VOR ORT|Kurier|Stückgut|Schüttgut|Pritsche|Plane|Koffer|Kühlwagen|Silo|Tankwagen|Schwertransport|ISO-Container|Super-Liner)/i', $clean)) {
            return false;
        }

        return true;
    }

    /**
     * Löst einen Stadtnamen in eine ID auf. Legt die Stadt bei Bedarf neu an (PH 3.2.2.2)[cite: 3].
     *
     * @param string $name Der Name der Stadt aus dem Spiel-Text
     * @param bool $autoCreate Wenn true, wird die Stadt bei Nichtexistenz in der Datenbank angelegt
     * @return int|null Die Datenbank-ID der Stadt, oder null falls nicht gefunden und autoCreate=false
     */
    public function resolveId(string $name, bool $autoCreate = true): ?int
    {
        // 0. BEREINIGUNG: Tabulatoren, Zeilenumbrüche & UTF-8 Codierungsfehler abfangen
        $cleanName = trim(explode("\t", $name)[0]);
        $cleanName = str_replace(["\r", "\n", "\t"], '', $cleanName);
        $cleanName = mb_convert_encoding($cleanName, 'UTF-8', 'UTF-8, ISO-8859-1, WINDOWS-1252');

        $countryCode = 'DE';

        // Dynamische Abfrage der Präfixe aus der DB-Tabelle countries
        $stmtCountries = $this->pdo->query("SELECT country_code, prefix_name FROM countries");
        if ($stmtCountries) {
            $countries = $stmtCountries->fetchAll(PDO::FETCH_ASSOC);
            foreach ($countries as $c) {
                $prefixWithSpace = $c['prefix_name'] . ' ';
                if (str_starts_with($cleanName, $prefixWithSpace)) {
                    $countryCode = $c['country_code'];
                    $cleanName = trim(substr($cleanName, strlen($prefixWithSpace)));
                    break;
                }
            }
        }

        // 1. Suche nach existierender Stadt
        $stmt = $this->pdo->prepare("SELECT id FROM cities WHERE name = :name");
        $stmt->execute(['name' => $cleanName]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            return (int)$id;
        }

        // 2. Stadt existiert nicht -> Neu anlegen mit Ländercode
        if ($autoCreate) {
            if (mb_strlen($cleanName) < 2) {
                return null; 
            }

            $stmtInsert = $this->pdo->prepare("INSERT INTO cities (name, country_code) VALUES (:name, :cc)");
            $stmtInsert->execute(['name' => $cleanName, 'cc' => $countryCode]);
            
            return (int)$this->pdo->lastInsertId();
        }

        return null;
    }

    /**
     * Lädt den Ländercode einer Stadt dynamisch aus der Tabelle cities.
     */
    public function getCountryCode(int $cityId): string
    {
        $stmt = $this->pdo->prepare("SELECT country_code FROM cities WHERE id = ?");
        $stmt->execute([$cityId]);
        $code = $stmt->fetchColumn();
        return $code ? (string)$code : 'DE';
    }

    /**
     * Lädt ein City-Objekt anhand seiner technischen ID
     *
     * @param int $id Die technische Primärschlüssel-ID
     * @return City|null Das initialisierte Objekt oder null bei Fehler
     */
    public function getCityById(int $id): ?City
    {
        $stmt = $this->pdo->prepare("SELECT id, name FROM cities WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return new City((int)$row['id'], $row['name']);
        }

        return null;
    }
    /**
     * Ermittelt alle lizensierten Städte, die aktuell über 0 aktive, 
     * unverplante Lager-Aufträge verfügen (Mangel-Städte / FEHLT).
     *
     * KORREKTUR: Vollständig gekapselt zur Vermeidung von SQL-Redundanz (PH § 1.3.1)
     *
     * @return array Liste der Städtenamen [string]
     */
    public function getEmptyWarehouseCities(): array
    {
        $stmt = $this->pdo->query("
            SELECT c.name 
            FROM cities c
            LEFT JOIN orders o ON c.id = o.from_city_id 
              AND o.is_accepted = 1 
              AND o.is_archived = 0 
              AND o.assigned_truck_id IS NULL
            GROUP BY c.id
            HAVING COUNT(o.id) = 0
        ");
        
        return $stmt ? array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name') : [];
    }

    /**
         * Prüft mathematisch exakt (K_X < N - 1), ob für eine Stadt unvollständige Matrix-Daten vorliegen.
         *
         * @param int $cityId Technische ID der Stadt
         * @return bool True, wenn mindestens eine Verbindung zu allen anderen Städten in distances fehlt
         */
        public function hasIncompleteMatrix(int $cityId): bool
        {
            // 1. Gesamtzahl N aller registrierten Städte ermitteln
            $totalCities = (int)$this->pdo->query("SELECT COUNT(*) FROM cities")->fetchColumn();
            if ($totalCities <= 1) {
                return false;
            }

            // 2. Anzahl K_X aller erfassten Verbindungen für diese Stadt in distances ermitteln
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) 
                FROM distances 
                WHERE city_a_id = :id OR city_b_id = :id
            ");
            $stmt->execute(['id' => $cityId]);
            $recordedConnections = (int)$stmt->fetchColumn();

            // 3. Unvollständig, wenn weniger als N - 1 Verbindungen vorliegen
            return $recordedConnections < ($totalCities - 1);
        }

        /**
         * Ermittelt für alle Standorte diejenigen LKW-Klassen, die dort demnächst leer werden,
         * für die vor Ort jedoch aktuell 0 kompatible, regelkonforme Anschlussfrachten (0 km Anfahrt) bereitstehen.
         *
         * @return array [city_id => ['city_id' => int, 'city_name' => string, 'stranded_types' => [type_name => count]]]
         */
        public function getStrandedTruckTypesPerCity(): array
        {
            // 1. Alle disponiblen LKW, Fahrer-ADR-Status und ihr virtuelles Tourende (oder Standort) ermitteln
            $stmtTrucks = $this->pdo->query("
                SELECT t.id, t.vehicle_type, t.capacity_t, t.min_weight_t, t.max_weight_t, t.current_city_id,
                       d.adr_permit,
                       COALESCE(
                           (SELECT to_city_id FROM orders WHERE assigned_truck_id = t.id AND is_archived = 0 ORDER BY assigned_at DESC LIMIT 1),
                           t.current_city_id
                       ) AS tour_end_city_id
                FROM trucks t
                LEFT JOIN drivers d ON t.assigned_driver_id = d.ingame_driver_id
                WHERE t.assigned_driver_id IS NOT NULL
            ");
            $trucks = $stmtTrucks->fetchAll(PDO::FETCH_ASSOC);

            if (empty($trucks)) {
                return [];
            }

            // Lade alle owned LKWs zur Auswertung der Tonnagen-Sicherheitsweiche
            $stmtAllOwned = $this->pdo->query("SELECT id, capacity_t, vehicle_type, min_weight_t, max_weight_t FROM trucks");
            $allOwnedTrucks = $stmtAllOwned->fetchAll(PDO::FETCH_ASSOC);

            // 2. Alle gesicherten LAGER-Aufträge (is_accepted = 1) im System als Anschlüsse laden
            $stmtOrders = $this->pdo->query("
                SELECT from_city_id, freight_type, is_adr, weight_remaining
                FROM orders 
                WHERE is_archived = 0 AND assigned_truck_id IS NULL AND weight_remaining > 0 AND is_accepted = 1
            ");
            $availableOrders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

            // Map der verfügbaren Frachtaufträge pro Startstadt aufbauen
            $cityOrdersMap = [];
            foreach ($availableOrders as $ord) {
                $cityOrdersMap[(int)$ord['from_city_id']][] = $ord;
            }

            // 3. Prüfen, ob für jeden LKW an seinem Zielort eine regelkonforme Anschlussfracht (0 km) existiert
            $strandedPerCity = [];

            foreach ($trucks as $tr) {
                $endCityId = (int)$tr['tour_end_city_id'];
                $vType = $tr['vehicle_type'];
                $hasDriverAdr = !empty($tr['adr_permit']);

                $startOrders = $cityOrdersMap[$endCityId] ?? [];
                $hasCompatibleOrder = false;

                foreach ($startOrders as $cand) {
                    // A. Fahrzeugtyp-Kompatibilität prüfen
                    if (!TopologyEngine::isTypeCompatible($cand['freight_type'], $vType)) {
                        continue;
                    }

                    // B. ADR-Berechtigung des Fahrers prüfen
                    if ((int)$cand['is_adr'] === 1 && !$hasDriverAdr) {
                        continue;
                    }

                    // C. Tonnage-Prüfung inkl. Notfall-Freigabe der Sicherheitsweiche
                    $weight = (int)$cand['weight_remaining'];
                    $min = (int)($tr['min_weight_t'] ?? 0);
                    $max = (int)($tr['max_weight_t'] ?? 0);

                    $allowedByMin = ($weight >= $min);
                    $allowedByMax = ($max === 0 || $weight <= $max);

                    if (!$allowedByMin || !$allowedByMax) {
                        $anyOtherCanHaul = false;
                        foreach ($allOwnedTrucks as $otherTruck) {
                            if ((int)$otherTruck['id'] === (int)$tr['id']) continue;
                            if (!TopologyEngine::isTypeCompatible($cand['freight_type'], $otherTruck['vehicle_type'])) continue;

                            $otherMin = (int)($otherTruck['min_weight_t'] ?? 0);
                            $otherMax = (int)($otherTruck['max_weight_t'] ?? 0);
                            if ($weight >= $otherMin && ($otherMax === 0 || $weight <= $otherMax)) {
                                $anyOtherCanHaul = true;
                                break;
                            }
                        }
                        if ($anyOtherCanHaul) {
                            continue; // Sperre bleibt aktiv -> dieser LKW darf die Fracht nicht nehmen
                        }
                    }

                    // Wenn alle Kriterien erfüllt sind, existiert eine gültige Anschlussfracht!
                    $hasCompatibleOrder = true;
                    break;
                }

                // Falls KEINE regelkonforme Fracht an diesem Standort startet -> LKW-Klasse samt Restriktionen eintragen
                if (!$hasCompatibleOrder) {
                    if (!isset($strandedPerCity[$endCityId])) {
                        $cityName = $this->pdo->query("SELECT name FROM cities WHERE id = $endCityId")->fetchColumn() ?: 'Unbekannt';
                        $strandedPerCity[$endCityId] = [
                            'city_id' => $endCityId,
                            'city_name' => $cityName,
                            'stranded_types' => [],
                            'stranded_total_tonnage' => 0
                        ];
                    }
                    $strandedPerCity[$endCityId]['stranded_types'][$vType][] = [
                        'capacity_t' => (int)$tr['capacity_t'],
                        'min_weight_t' => (int)$tr['min_weight_t'],
                        'max_weight_t' => (int)$tr['max_weight_t'],
                        'adr_permit' => !empty($tr['adr_permit'])
                    ];
                    $strandedPerCity[$endCityId]['stranded_total_tonnage'] += (int)$tr['capacity_t'];
                }
            }

            // Standard-Sortierung: Absteigend nach gestrandeter Gesamttonnage (höchste Tonnage zuerst)
            usort($strandedPerCity, function($a, $b) {
                if ($a['stranded_total_tonnage'] !== $b['stranded_total_tonnage']) {
                    return $b['stranded_total_tonnage'] <=> $a['stranded_total_tonnage'];
                }
                return strcmp($a['city_name'], $b['city_name']);
            });

            return $strandedPerCity;
        }
    }