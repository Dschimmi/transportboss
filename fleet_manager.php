<?php
declare(strict_types=1);

require_once 'db_connect.php';
require_once 'classes/Driver.php';
require_once 'classes/DriverRepository.php';
require_once 'classes/Truck.php';
require_once 'classes/TruckRepository.php';

$message = '';
$messageClass = '';

$driverRepo = new DriverRepository($pdo);
$truckRepo = new TruckRepository($pdo);

// POST-Requests verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'add_driver') {
            $driver = new Driver(
                $_POST['ingame_id'],
                $_POST['first_name'],
                $_POST['last_name'],
                (int)$_POST['age'],
                (int)$_POST['skill_val'],
                (int)$_POST['reliability_val'],
                isset($_POST['adr_permit']),
                (int)$_POST['penalty_points'],
                (float)$_POST['salary'],
                true // is_employed
            );
            $driverRepo->save($driver);
            $message = "Fahrer {$_POST['first_name']} {$_POST['last_name']} erfolgreich angelegt.";
            $messageClass = "status-success";

        } elseif ($_POST['action'] === 'add_truck') {
            $truck = new Truck(
                $_POST['ingame_vehicle_id'],
                $_POST['vehicle_type'],
                (int)$_POST['capacity_t'],
                (int)$_POST['year_built'],
                (int)$_POST['km_stand'],
                (int)$_POST['current_city_id'] 
            );
            $truckRepo->save($truck);
            $message = "LKW {$_POST['vehicle_type']} erfolgreich angelegt.";
            $messageClass = "status-success";

        } elseif ($_POST['action'] === 'assign_pair') {
            // LKW und Fahrer miteinander verknüpfen
            $truckId = $_POST['truck_id'] ?? null;
            $driverId = $_POST['driver_id'] ?? null;
            
            if ($truckId && $driverId) {
                $truckRepo->assignDriver($truckId, $driverId);
                $message = "Fahrer und LKW erfolgreich zugewiesen.";
                $messageClass = "status-success";
            }
        } elseif ($_POST['action'] === 'unassign_pair') {
            // Zuweisung aufheben
            $truckId = $_POST['truck_id'] ?? null;
            if ($truckId) {
                $truckRepo->assignDriver($truckId, null);
                $message = "Zuweisung erfolgreich aufgehoben.";
                $messageClass = "status-success";
            }
        }
    } catch (Exception $e) {
        $message = "Fehler: " . htmlspecialchars($e->getMessage());
        $messageClass = "status-error";
    }
}

// Basis-Daten laden
$allDrivers = $driverRepo->getAllEmployed();
$allTrucks = $truckRepo->getAllOwned();
$allCities = $pdo->query("SELECT id, name FROM cities ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Daten für die UI sortieren (Unvollständige oben)
$unassignedTrucks = [];
$assignedPairs = [];
$assignedDriverIds = [];

// 1. LKW aufteilen
foreach ($allTrucks as $truck) {
    if (!empty($truck['assigned_driver_id'])) {
        $assignedPairs[] = $truck;
        $assignedDriverIds[] = $truck['assigned_driver_id'];
    } else {
        $unassignedTrucks[] = $truck;
    }
}

// 2. Fahrer aufteilen und Map für schnellen Zugriff erstellen
$unassignedDrivers = [];
$driverMap = [];
foreach ($allDrivers as $driver) {
    // Korrigierter Spaltenname: ingame_driver_id
    $driverMap[$driver['ingame_driver_id']] = $driver;
    if (!in_array($driver['ingame_driver_id'], $assignedDriverIds)) {
        $unassignedDrivers[] = $driver;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Fuhrpark Manager - TransportBoss</title>
    <link rel="stylesheet" href="main.css">
    <style>
        /* Spezifisches UI-Styling für diese Ansicht */
        .form-grid { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 20px; 
            margin-bottom: 30px; 
        }
        .form-box { 
            background-color: #252525; 
            padding: 20px; 
            border-radius: 5px; 
            border: 1px solid #444; 
            box-sizing: border-box; 
        }
        .input-group { 
            margin-bottom: 12px; 
        }
        .input-group label { 
            display: block; 
            margin-bottom: 5px; 
            font-size: 0.85em; 
            color: #aaa; 
            font-weight: 600;
        }
        .input-group input, .input-group select { 
            width: 100%; 
            padding: 10px; 
            background: #1e1e1e; 
            color: #fff; 
            border: 1px solid #444; 
            box-sizing: border-box; 
            border-radius: 3px; 
        }
        .checkbox-group { 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            margin: 20px 0; 
        }
        .checkbox-group input { 
            margin: 0; 
            width: 18px; 
            height: 18px; 
        }
        .checkbox-group label { 
            margin: 0; 
            font-size: 0.9em; 
            color: #fff; 
        }
        .inline-select { 
            padding: 8px; 
            background: #1e1e1e; 
            color: white; 
            border: 1px solid #444; 
            border-radius: 3px;
        }
        .text-warning { color: #e74c3c; font-weight: bold; }
    </style>
</head>
<body>
    <div class="main-container" style="max-width: 1200px;">
        <h1 class="accent-text">Fuhrpark & Personal Manager</h1>
        
        <?php if ($message): ?>
            <div class="feedback-msg <?= $messageClass ?>"><?= $message ?></div>
        <?php endif; ?>

        <div class="form-grid">
            <!-- Formular: Fahrer manuell anlegen -->
            <div class="form-box">
                <h3 class="accent-text">Fahrer manuell erfassen</h3>
                <form method="post">
                    <input type="hidden" name="action" value="add_driver">
                    
                    <div class="input-group">
                        <label>Ingame ID (z.B. 10658917)</label>
                        <input type="text" name="ingame_id" required>
                    </div>
                    <div class="input-group">
                        <label>Vorname</label>
                        <input type="text" name="first_name" required>
                    </div>
                    <div class="input-group">
                        <label>Nachname</label>
                        <input type="text" name="last_name" required>
                    </div>
                    <div class="input-group">
                        <label>Alter</label>
                        <input type="number" name="age" required>
                    </div>
                    <div class="input-group">
                        <label>Fahrkönnen (0-100)</label>
                        <input type="number" name="skill_val" required>
                    </div>
                    <div class="input-group">
                        <label>Zuverlässigkeit (0-100)</label>
                        <input type="number" name="reliability_val" required>
                    </div>
                    <div class="input-group">
                        <label>Gehalt (€)</label>
                        <input type="number" name="salary" step="0.01" required>
                    </div>
                    <div class="input-group">
                        <label>Punkte in Flensburg (Strafpunkte)</label>
                        <input type="number" name="penalty_points" value="0" required>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" name="adr_permit" id="adr_permit">
                        <label for="adr_permit">Gefahrgutschein (ADR) vorhanden</label>
                    </div>
                    
                    <button type="submit" class="btn-primary" style="width:100%">Fahrer speichern</button>
                </form>
            </div>

            <!-- Formular: LKW manuell anlegen -->
            <div class="form-box">
                <h3 class="accent-text">LKW manuell erfassen</h3>
                <form method="post">
                    <input type="hidden" name="action" value="add_truck">
                    
                    <div class="input-group">
                        <label>Ingame LKW ID (z.B. 4151822)</label>
                        <input type="text" name="ingame_vehicle_id" required>
                    </div>
                    <div class="input-group">
                        <label>Fahrzeugtyp</label>
                        <select name="vehicle_type" required>
                            <option value="">Bitte wählen...</option>
                            <option value="Kurier">Kurier</option>
                            <option value="Stückgut">Stückgut</option>
                            <option value="Plane">Plane</option>
                            <option value="Koffer">Koffer</option>
                            <option value="Kühlwagen">Kühlwagen</option>
                            <option value="Tankwagen">Tankwagen</option>
                            <option value="Silo">Silo</option>
                            <option value="Schüttgut">Schüttgut</option>
                            <option value="Pritsche">Pritsche</option>
                            <option value="ISO-Container">ISO-Container</option>
                            <option value="Schwertransport">Schwertransport</option>
                            <option value="Super-Liner">Super-Liner</option>
                        </select>
                    </div>
                    <div class="input-group">
                        <label>Aktueller Standort (Stadt)</label>
                        <select name="current_city_id" required>
                            <option value="">Bitte wählen...</option>
                            <?php foreach ($allCities as $city): ?>
                                <option value="<?= $city['id'] ?>"><?= htmlspecialchars($city['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group">
                        <label>Kapazität (in Tonnen)</label>
                        <input type="number" name="capacity_t" required>
                    </div>
                    <div class="input-group">
                        <label>Baujahr (z.B. 2015)</label>
                        <input type="number" name="year_built" required>
                    </div>
                    <div class="input-group">
                        <label>Aktueller Kilometerstand</label>
                        <input type="number" name="km_stand" required>
                    </div>
                    
                    <button type="submit" class="btn-primary" style="width:100%">LKW speichern</button>
                </form>
            </div>
        </div>

        <hr style="border-color: #444; margin: 30px 0;">

        <!-- Disposition (Zuweisungen) -->
        <h2 class="accent-text">Fahrer-Zuweisung (Disposition)</h2>
        
        <!-- Filter-Eingabefeld zur Tabellensuche -->
        <input type="text" id="tableFilter" class="filter-input" placeholder="Tabelle durchsuchen (z.B. Name, LKW-Typ, ID)...">

        <table class="data-table" id="sortableTable">
            <thead>
                <tr>
                    <!-- Klick-Events für die Sortierung hinzugefügt -->
                    <th onclick="sortTable(0, 'string')">LKW Daten ↕</th>
                    <th onclick="sortTable(1, 'string')">Fahrer Daten ↕</th>
                    <th>Aktion</th>
                </tr>
            </thead>
            <tbody>
                
                <!-- 1. Unzugewiesene LKWs (Fehlende Fahrer) -->
                <?php foreach ($unassignedTrucks as $truck): ?>
                <tr>
                    <td>
                        <strong>ID: <?= htmlspecialchars($truck['ingame_vehicle_id']) ?></strong><br>
                        <?= htmlspecialchars($truck['vehicle_type']) ?> | <?= $truck['capacity_t'] ?> t
                    </td>
                    <td class="text-warning">- Kein Fahrer zugewiesen -</td>
                    <td>
                        <form method="post" style="display:flex; gap:10px; align-items:center;">
                            <input type="hidden" name="action" value="assign_pair">
                            <input type="hidden" name="truck_id" value="<?= htmlspecialchars($truck['ingame_vehicle_id']) ?>">
                            <select name="driver_id" class="inline-select" required>
                                <option value="">-- Freien Fahrer wählen --</option>
                                <?php foreach ($unassignedDrivers as $ud): ?>
                                    <option value="<?= htmlspecialchars((string)$ud['ingame_driver_id']) ?>">
                                        <?= htmlspecialchars($ud['first_name'] . ' ' . $ud['last_name']) ?> (Können: <?= $ud['skill_val'] ?>, ADR: <?= $ud['adr_permit'] ? 'Ja' : 'Nein' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn-primary" style="padding: 8px 12px;">Zuweisen</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>

                <!-- 2. Unzugewiesene Fahrer (Fehlende LKWs) -->
                <?php foreach ($unassignedDrivers as $driver): ?>
                <tr>
                    <td class="text-warning">- Kein LKW zugewiesen -</td>
                    <td>
                        <strong><?= htmlspecialchars($driver['first_name'] . ' ' . $driver['last_name']) ?></strong><br>
                        Fahrkönnen: <?= $driver['skill_val'] ?> | ADR: <?= $driver['adr_permit'] ? 'Ja' : 'Nein' ?>
                    </td>
                    <td>
                        <form method="post" style="display:flex; gap:10px; align-items:center;">
                            <input type="hidden" name="action" value="assign_pair">
                            <input type="hidden" name="driver_id" value="<?= htmlspecialchars((string)$driver['ingame_driver_id']) ?>">
                            <select name="truck_id" class="inline-select" required>
                                <option value="">-- Freien LKW wählen --</option>
                                <?php foreach ($unassignedTrucks as $ut): ?>
                                    <option value="<?= htmlspecialchars($ut['ingame_vehicle_id']) ?>">
                                        ID: <?= htmlspecialchars($ut['ingame_vehicle_id']) ?> (<?= htmlspecialchars($ut['vehicle_type']) ?>, <?= $ut['capacity_t'] ?>t)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn-primary" style="padding: 8px 12px;">Zuweisen</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>

                <!-- 3. Komplett zugewiesene Gespanne -->
                <?php foreach ($assignedPairs as $truck): 
                    $d = $driverMap[$truck['assigned_driver_id']] ?? null;
                ?>
                <tr>
                    <td style="color: #ccc;">
                        <strong>ID: <?= htmlspecialchars($truck['ingame_vehicle_id']) ?></strong><br>
                        <?= htmlspecialchars($truck['vehicle_type']) ?> | <?= $truck['capacity_t'] ?> t
                    </td>
                    <td style="color: #ccc;">
                        <?php if ($d): ?>
                            <strong><?= htmlspecialchars($d['first_name'] . ' ' . $d['last_name']) ?></strong><br>
                            Fahrkönnen: <?= $d['skill_val'] ?> | ADR: <?= $d['adr_permit'] ? 'Ja' : 'Nein' ?>
                        <?php else: ?>
                            Fahrer-Daten fehlen
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post">
                            <input type="hidden" name="action" value="unassign_pair">
                            <input type="hidden" name="truck_id" value="<?= htmlspecialchars($truck['ingame_vehicle_id']) ?>">
                            <button type="submit" class="btn-primary" style="padding: 8px 12px; background-color: #e74c3c; color: white; border: none;">Entkoppeln</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>

            </tbody>
        </table>
    </div>

    <!-- Client-Side Filter & Sort Logik -->
    <script>
        // --- Filter-Logik ---
        document.getElementById('tableFilter').addEventListener('keyup', function() {
            let filter = this.value.toLowerCase();
            let rows = document.querySelectorAll('#sortableTable tbody tr');
            
            rows.forEach(row => {
                let text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });

        // --- Sortier-Logik ---
        let sortDirections = [false, false, false]; 

        function sortTable(columnIndex, type) {
            let table = document.getElementById("sortableTable");
            let tbody = table.querySelector("tbody");
            let rows = Array.from(tbody.querySelectorAll("tr"));
            
            let dir = !sortDirections[columnIndex];
            sortDirections[columnIndex] = dir;

            rows.sort((a, b) => {
                // Holt den reinen Text aus der jeweiligen Spalte zum Vergleichen
                let valA = a.children[columnIndex].innerText.trim().toLowerCase();
                let valB = b.children[columnIndex].innerText.trim().toLowerCase();

                if (valA < valB) return dir ? -1 : 1;
                if (valA > valB) return dir ? 1 : -1;
                return 0;
            });

            // Tabelle mit sortierten Zeilen neu aufbauen
            rows.forEach(row => tbody.appendChild(row));
        }
    </script>
</body>
</html>