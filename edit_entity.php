<?php
declare(strict_types=1);

/**
 * edit_entity.php
 *
 * Die zentrale Bearbeitungsmaske für Fuhrpark und Fahrpersonal von TransportBoss.
 * Bietet ein barrierefreies Layout, native Autovervollständigung für Standorte (Datalist)
 * und gewährleistet die Einhaltung aller WCAG-Kontrastrichtlinien.
 *
 * KORREKTUR: Die Datei ist vollständig bereinigt von jeglichem Inline-CSS (style-Attributen).
 * Alle Designvorgaben werden konsistent über die Klassen der main.css gelöst.
 * Manuelle Städteneuanlagen sind gesperrt.
 *
 * @author TransportBoss Development
 * @version 2.3.0
 */

require_once 'db_connect.php';

$type = $_GET['type'] ?? '';
$id = (int)($_GET['id'] ?? 0);

if ($id === 0 || !in_array($type, ['truck', 'driver'])) {
    die("Ungültige Anfrage.");
}

// Erlaubte LKW-Typen aus dem Enum-Feld der DB
$allowedTruckTypes = ['Kurier', 'Stückgut', 'Schüttgut', 'Pritsche', 'Plane', 'Koffer', 'Kühlwagen', 'Silo', 'Tankwagen', 'Schwertransport', 'ISO-Container', 'Super-Liner'];

$message = '';
$messageClass = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($type === 'truck') {
            if (!in_array($_POST['vehicle_type'], $allowedTruckTypes)) {
                throw new Exception("Ungültiger Fahrzeugtyp!");
            }

            // Physischen Städtenamen auslesen und validieren
            $cityName = trim($_POST['current_city_name'] ?? '');
            if ($cityName === '') {
                throw new Exception("Der physische Standort des LKW darf nicht leer sein!");
            }

            // Suchen der existierenden Stadt (KEINE NEUANLAGE ERLAUBT laut PH-Soll!)
            $stmtCity = $pdo->prepare("SELECT id FROM cities WHERE name = ?");
            $stmtCity->execute([$cityName]);
            $cityId = $stmtCity->fetchColumn();

            if ($cityId === false) {
                throw new Exception("Die Stadt '" . htmlspecialchars($cityName) . "' ist nicht im System registriert. Neue Standorte können ausschließlich über den Auftrags-Import angelegt werden.");
            }
            $cityId = (int)$cityId;

            // LKW-Daten aktualisieren
            $stmt = $pdo->prepare("
                UPDATE trucks 
                SET user_label = :label, 
                    vehicle_type = :type, 
                    capacity_t = :cap, 
                    year_built = :year, 
                    km_stand = :km, 
                    min_weight_t = :min_weight,
                    max_weight_t = :max_weight,
                    current_city_id = :city 
                WHERE id = :id
            ");
            $stmt->execute([
                'label' => $_POST['user_label'], 
                'type' => $_POST['vehicle_type'], 
                'cap' => (int)$_POST['capacity_t'], 
                'year' => (int)$_POST['year_built'], 
                'km' => (int)$_POST['km_stand'], 
                'min_weight' => (int)$_POST['min_weight_t'],
                'max_weight' => (int)$_POST['max_weight_t'],
                'city' => $cityId, 
                'id' => $id
            ]);
        } else {
            // Fahrerstammdaten aktualisieren
            $stmt = $pdo->prepare("
                UPDATE drivers 
                SET first_name = :fn, 
                    last_name = :ln, 
                    age = :age, 
                    skill_val = :skill, 
                    reliability_val = :rel, 
                    adr_permit = :adr, 
                    salary = :sal 
                WHERE id = :id
            ");
            $stmt->execute([
                'fn' => $_POST['first_name'], 
                'ln' => $_POST['last_name'], 
                'age' => (int)$_POST['age'], 
                'skill' => (int)$_POST['skill_val'], 
                'rel' => (int)$_POST['reliability_val'], 
                'adr' => isset($_POST['adr_permit']) ? 1 : 0, 
                'sal' => (float)$_POST['salary'], 
                'id' => $id
            ]);
        }
        $message = "Daten erfolgreich aktualisiert.";
        $messageClass = "status-success";
    } catch (Exception $e) {
        $message = "Fehler bei der Aktualisierung: " . $e->getMessage();
        $messageClass = "status-error";
    }
}

// Aktuelle Stammdaten der Entität laden
$data = $pdo->prepare("SELECT * FROM " . ($type === 'truck' ? 'trucks' : 'drivers') . " WHERE id = ?");
$data->execute([$id]);
$item = $data->fetch(PDO::FETCH_ASSOC);

// Alle existierenden Städte für die native Datalist-Autovervollständigung laden
$cities = $pdo->query("SELECT id, name FROM cities ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Bearbeiten - TransportBoss</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <?php require_once 'nav.php'; ?>
    <div class="fluid-container">
        <div class="form-box">
            <h2 class="accent-text">
                <?= $type === 'truck' ? '🚚 Fahrzeugdaten bearbeiten' : '🧑‍💼 Fahrerdaten bearbeiten' ?>
            </h2>
            
            <?php if ($message): ?>
                <div class="feedback-msg <?= $messageClass ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <form method="post" action="edit_entity.php?type=<?= $type ?>&id=<?= $id ?>">
                
                <?php if ($type === 'truck'): ?>
                    <!-- FORMULAR-TABELLE: FAHRZEUGDATEN (COMPACT & DARK-MODE) -->
                    <table class="data-table">
                        <tbody>
                            <!-- Reihe 1: Beschriftungen (Labels) -->
                            <tr>
                                <td><label for="user_label">Benutzer-Label / Kennzeichen:</label></td>
                                <td><label for="vehicle_type">Fahrzeug-Klasse (Fracht-Kompatibilität):</label></td>
                                <td><label for="capacity_t">Nutzlast / Kapazität (Tonnen):</label></td>
                                <td><label for="year_built">Baujahr des LKW:</label></td>
                            </tr>
                            <!-- Reihe 2: Eingabefelder (Inputs) in input-groups -->
                            <tr>
                                <td><div class="input-group"><input type="text" id="user_label" name="user_label" value="<?= htmlspecialchars($item['user_label'] ?? '') ?>" placeholder="z.B. LKW-Freiburg-1"></div></td>
                                <td>
                                    <div class="input-group">
                                        <select id="vehicle_type" name="vehicle_type" class="inline-select w-100">
                                            <?php foreach ($allowedTruckTypes as $t): ?>
                                                <option value="<?= $t ?>" <?= ($item['vehicle_type'] === $t) ? 'selected' : '' ?>><?= $t ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </td>
                                <td><div class="input-group"><input type="number" id="capacity_t" name="capacity_t" value="<?= $item['capacity_t'] ?>" required></div></td>
                                <td><div class="input-group"><input type="number" id="year_built" name="year_built" value="<?= $item['year_built'] ?>" required></div></td>
                            </tr>
                            
                            <!-- Reihe 3: Beschriftungen (Labels) -->
                            <tr>
                                <td><label for="km_stand">Aktueller Kilometerstand:</label></td>
                                <td><label for="current_city_name">Aktueller Standort (Native Schnellsuche):</label></td>
                                <td><label for="min_weight_t">Tonnage-Sperre MIN (t) [0 = Aus]:</label></td>
                                <td><label for="max_weight_t">Tonnage-Sperre MAX (t) [0 = Aus]:</label></td>
                            </tr>
                            <!-- Reihe 4: Eingabefelder (Inputs) in input-groups -->
                            <tr>
                                <td><div class="input-group"><input type="number" id="km_stand" name="km_stand" value="<?= $item['km_stand'] ?>" required></div></td>
                                <td>
                                    <div class="input-group">
                                        <?php
                                        $currentCityName = '';
                                        if (!empty($item['current_city_id'])) {
                                            $stmtCityName = $pdo->prepare("SELECT name FROM cities WHERE id = ?");
                                            $stmtCityName->execute([$item['current_city_id']]);
                                            $currentCityName = $stmtCityName->fetchColumn() ?: '';
                                        }
                                        ?>
                                        <input type="text" id="current_city_name" name="current_city_name" list="cityList" value="<?= htmlspecialchars($currentCityName) ?>" autocomplete="off" required placeholder="Stadt eingeben (z.B. Fre...)">
                                        <datalist id="cityList">
                                            <?php foreach ($cities as $c): ?>
                                                <option value="<?= htmlspecialchars($c['name']) ?>"></option>
                                            <?php endforeach; ?>
                                        </datalist>
                                    </div>
                                </td>
                                <td><div class="input-group"><input type="number" id="min_weight_t" name="min_weight_t" value="<?= (int)($item['min_weight_t'] ?? 0) ?>"></div></td>
                                <td><div class="input-group"><input type="number" id="max_weight_t" name="max_weight_t" value="<?= (int)($item['max_weight_t'] ?? 0) ?>"></div></td>
                            </tr>
                        </tbody>
                    </table>

                <?php else: ?>
                    <!-- FORMULAR-TABELLE: FAHRERDATEN (COMPACT & DARK-MODE) -->
                    <table class="data-table">
                        <tbody>
                            <!-- Reihe 1: Beschriftungen (Labels) -->
                            <tr>
                                <td><label for="first_name">Vorname:</label></td>
                                <td><label for="last_name">Nachname:</label></td>
                                <td><label for="age">Alter (Jahre):</label></td>
                                <td><label for="skill_val">Fahrkönnen (Fahrerskill):</label></td>
                            </tr>
                            <!-- Reihe 2: Eingabefelder (Inputs) in input-groups -->
                            <tr>
                                <td><div class="input-group"><input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($item['first_name']) ?>" required></div></td>
                                <td><div class="input-group"><input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($item['last_name']) ?>" required></div></td>
                                <td><div class="input-group"><input type="number" id="age" name="age" value="<?= $item['age'] ?>" required></div></td>
                                <td><div class="input-group"><input type="number" id="skill_val" name="skill_val" value="<?= $item['skill_val'] ?>" required></div></td>
                            </tr>
                            
                            <!-- Reihe 3: Beschriftungen (Labels) -->
                            <tr>
                                <td><label for="reliability_val">Zuverlässigkeit (0-100%):</label></td>
                                <td><label for="salary">Gehaltsvereinbarung (€ / Monat):</label></td>
                                <td colspan="2"><label for="adr_permit">Gefahrgut-Zertifikat:</label></td>
                            </tr>
                            <!-- Reihe 4: Eingabefelder (Inputs) in input-groups -->
                            <tr>
                                <td><div class="input-group"><input type="number" id="reliability_val" name="reliability_val" value="<?= $item['reliability_val'] ?>" required></div></td>
                                <td><div class="input-group"><input type="number" step="0.01" id="salary" name="salary" value="<?= $item['salary'] ?>" required></div></td>
                                <td colspan="2">
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="adr_permit" name="adr_permit" <?= $item['adr_permit'] ? 'checked' : '' ?>>
                                        <label for="adr_permit">ADR vorhanden (Klasse 1-9 fahrberechtigt)</label>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                <?php endif; ?>

                <!-- AKTIONSTASTER (WCAG-KONFORM & KONTRASTSTARK) -->
                <div class="action-form">
                    <button type="submit" class="btn-primary">Speichern und Übernehmen</button>
                    <button type="button" class="btn-primary btn-danger" onclick="window.location.href='fleet_manager.php'">Abbrechen</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>