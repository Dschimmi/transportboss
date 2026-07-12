<?php
declare(strict_types=1);
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
            if (!in_array($_POST['vehicle_type'], $allowedTruckTypes)) throw new Exception("Ungültiger Fahrzeugtyp!");
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
                'city' => (int)$_POST['current_city_id'], 
                'id' => $id
            ]);
        } else {
            $stmt = $pdo->prepare("UPDATE drivers SET first_name = :fn, last_name = :ln, age = :age, skill_val = :skill, reliability_val = :rel, adr_permit = :adr, salary = :sal WHERE id = :id");
            $stmt->execute(['fn' => $_POST['first_name'], 'ln' => $_POST['last_name'], 'age' => (int)$_POST['age'], 'skill' => (int)$_POST['skill_val'], 'rel' => (int)$_POST['reliability_val'], 'adr' => isset($_POST['adr_permit']) ? 1 : 0, 'sal' => (float)$_POST['salary'], 'id' => $id]);
        }
        $message = "Daten erfolgreich aktualisiert.";
        $messageClass = "status-success";
    } catch (Exception $e) {
        $message = "Fehler: " . $e->getMessage();
        $messageClass = "status-error";
    }
}

$data = $pdo->prepare("SELECT * FROM " . ($type === 'truck' ? 'trucks' : 'drivers') . " WHERE id = ?");
$data->execute([$id]);
$item = $data->fetch(PDO::FETCH_ASSOC);
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
            <h2 class="accent-text">Bearbeite <?= ucfirst($type) ?></h2>
            <?php if($message): ?><div class="feedback-msg <?= $messageClass ?>"><?= $message ?></div><?php endif; ?>
            <form method="post">
                <?php if($type === 'truck'): ?>
                    <div class="input-group"><label>Label:</label><input type="text" name="user_label" value="<?= htmlspecialchars($item['user_label'] ?? '') ?>"></div>
                    <div class="input-group"><label>Typ:</label>
                        <select name="vehicle_type">
                            <?php foreach($allowedTruckTypes as $t): ?><option value="<?= $t ?>" <?= $item['vehicle_type']===$t?'selected':'' ?>><?= $t ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group"><label>Kapazität (t):</label><input type="number" name="capacity_t" value="<?= $item['capacity_t'] ?>"></div>
                    <div class="input-group"><label>Baujahr:</label><input type="number" name="year_built" value="<?= $item['year_built'] ?>"></div>
                    <div class="input-group"><label>KM-Stand:</label><input type="number" name="km_stand" value="<?= $item['km_stand'] ?>"></div>
                    <div class="input-group"><label>Stadt:</label><select name="current_city_id">
                        <?php foreach($cities as $c): ?><option value="<?= $c['id'] ?>" <?= $c['id']==$item['current_city_id']?'selected':'' ?>><?= $c['name'] ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="input-group"><label>Tonnage-Sperre MIN (t) [0 = Deaktiviert]:</label><input type="number" name="min_weight_t" value="<?= (int)($item['min_weight_t'] ?? 0) ?>"></div>
            <div class="input-group"><label>Tonnage-Sperre MAX (t) [0 = Unbegrenzt]:</label><input type="number" name="max_weight_t" value="<?= (int)($item['max_weight_t'] ?? 0) ?>"></div>
                <?php else: ?>
                    <div class="input-group"><label>Vorname:</label><input type="text" name="first_name" value="<?= htmlspecialchars($item['first_name']) ?>"></div>
                    <div class="input-group"><label>Nachname:</label><input type="text" name="last_name" value="<?= htmlspecialchars($item['last_name']) ?>"></div>
                    <div class="input-group"><label>Alter:</label><input type="number" name="age" value="<?= $item['age'] ?>"></div>
                    <div class="input-group"><label>Skill:</label><input type="number" name="skill_val" value="<?= $item['skill_val'] ?>"></div>
                    <div class="input-group"><label>Zuverlässigkeit:</label><input type="number" name="reliability_val" value="<?= $item['reliability_val'] ?>"></div>
                    <div class="input-group"><label>Gehalt:</label><input type="number" step="0.01" name="salary" value="<?= $item['salary'] ?>"></div>
                    <div class="checkbox-group"><input type="checkbox" name="adr_permit" <?= $item['adr_permit']?'checked':'' ?>><label> ADR vorhanden</label></div>
                <?php endif; ?>
                <button type="submit" class="btn-primary">Speichern</button>
                <a href="fleet_manager.php" class="btn-secondary">Abbrechen</a>
            </form>
        </div>
    </div>
</body>
</html>