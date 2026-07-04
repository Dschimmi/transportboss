<?php
declare(strict_types=1);

require_once 'db_connect.php';
require_once 'classes/Dispatcher.php';
require_once 'classes/DispatcherRepository.php';

$message = '';
$messageClass = '';

$dispatcherRepo = new DispatcherRepository($pdo);

// POST-Requests verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'add_dispatcher') {
            // Disponenten manuell anlegen
            $dispatcher = new Dispatcher(
                $_POST['ingame_id'],
                $_POST['first_name'],
                $_POST['last_name'],
                (int)$_POST['age'],
                (int)$_POST['skill_val'],
                (int)$_POST['reliability_val'],
                (float)$_POST['salary'],
                true // is_employed = true (direkt eingestellt)
            );
            $dispatcherRepo->save($dispatcher);
            $message = "Disponent {$_POST['first_name']} {$_POST['last_name']} erfolgreich eingestellt.";
            $messageClass = "status-success";

        } elseif ($_POST['action'] === 'dismiss_dispatcher') {
            // Disponenten entlassen
            $dispatcherId = (int)$_POST['dispatcher_id'];
            $dispatcherRepo->dismiss($dispatcherId);
            $message = "Disponent erfolgreich entlassen.";
            $messageClass = "status-success";
        }
    } catch (Exception $e) {
        $message = "Fehler: " . htmlspecialchars($e->getMessage());
        $messageClass = "status-error";
    }
}

// Alle Disponenten laden (eingestellt und Bewerber)
$allDispatchers = $pdo->query("SELECT * FROM dispatchers ORDER BY is_employed DESC, last_name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Disponenten Manager - TransportBoss</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <div class="main-container" style="max-width: 1000px;">
        <h1 class="accent-text">Disponenten Manager</h1>

        <?php if ($message): ?>
            <div class="feedback-msg <?= $messageClass ?>"><?= $message ?></div>
        <?php endif; ?>

        <!-- Formular: Disponenten manuell anlegen -->
        <div class="form-box">
            <h3 class="accent-text">Disponenten manuell erfassen</h3>
            <form method="post">
                <input type="hidden" name="action" value="add_dispatcher">

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
                    <label>Verwaltungs-Skill (0-100)</label>
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

                <button type="submit" class="btn-primary" style="width:100%">Disponent einstellen</button>
            </form>
        </div>

        <hr style="border-color: #444; margin: 30px 0;">

        <!-- Filter-Eingabefeld -->
        <input type="text" id="dispatcherFilter" class="filter-input" placeholder="Disponenten durchsuchen (Name, ID, Skill)...">

        <!-- Tabelle: Alle Disponenten -->
        <table class="data-table" id="dispatcherTable">
            <thead>
                <tr>
                    <th onclick="sortTable(0)">ID ⇅</th>
                    <th onclick="sortTable(1)">Name ⇅</th>
                    <th onclick="sortTable(2)">Alter ⇅</th>
                    <th onclick="sortTable(3)">Skill ⇅</th>
                    <th onclick="sortTable(4)">Zuverlässigkeit ⇅</th>
                    <th onclick="sortTable(5)">Gehalt ⇅</th>
                    <th>Status</th>
                    <th>Aktion</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allDispatchers as $dispatcher): ?>
                <tr>
                    <td><?= htmlspecialchars($dispatcher['ingame_dispatcher_id']) ?></td>
                    <td><?= htmlspecialchars($dispatcher['first_name'] . ' ' . $dispatcher['last_name']) ?></td>
                    <td><?= $dispatcher['age'] ?></td>
                    <td><?= $dispatcher['skill_val'] ?></td>
                    <td><?= $dispatcher['reliability_val'] ?></td>
                    <td><?= number_format($dispatcher['salary'], 2, ',', '.') ?> €</td>
                    <td class="<?= $dispatcher['is_employed'] ? 'status-employed' : 'status-unemployed' ?>">
                        <?= $dispatcher['is_employed'] ? 'Eingestellt' : 'Bewerbung' ?>
                    </td>
                    <td>
                        <?php if ($dispatcher['is_employed']): ?>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="action" value="dismiss_dispatcher">
                                <input type="hidden" name="dispatcher_id" value="<?= $dispatcher['id'] ?>">
                                <button type="submit" class="btn-primary" style="background-color: #e74c3c; padding: 6px 12px;">Entlassen</button>
                            </form>
                        <?php else: ?>
                            <span style="color: #888; font-size: 0.8em;">(Bewerbung)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Client-Side Filter & Sort Logik -->
    <script>
        // Filter-Logik
        document.getElementById('dispatcherFilter').addEventListener('keyup', function() {
            let filter = this.value.toLowerCase();
            let rows = document.querySelectorAll('#dispatcherTable tbody tr');

            rows.forEach(row => {
                let text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });

        // Sortier-Logik
        let sortDirections = [false, false, false, false, false, false, false, false];

        function sortTable(columnIndex) {
            let table = document.getElementById("dispatcherTable");
            let tbody = table.querySelector("tbody");
            let rows = Array.from(tbody.querySelectorAll("tr"));

            let dir = !sortDirections[columnIndex];
            sortDirections[columnIndex] = dir;

            rows.sort((a, b) => {
                let valA = a.children[columnIndex].innerText.trim();
                let valB = b.children[columnIndex].innerText.trim();

                // Numerische Sortierung für Alter, Skill, Zuverlässigkeit, Gehalt
                if ([2, 3, 4, 5].includes(columnIndex)) {
                    valA = parseFloat(valA.replace(/[^0-9.]/g, '')) || 0;
                    valB = parseFloat(valB.replace(/[^0-9.]/g, '')) || 0;
                    return dir ? valA - valB : valB - valA;
                }

                // String-Sortierung für andere Spalten
                if (valA < valB) return dir ? -1 : 1;
                if (valA > valB) return dir ? 1 : -1;
                return 0;
            });

            rows.forEach(row => tbody.appendChild(row));
        }
    </script>
</body>
</html>