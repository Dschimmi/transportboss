<?php
declare(strict_types=1);

/**
 * personnel_manager.php
 *
 * Das zentrale Personal-Zentrum von TransportBoss.
 * Bietet eine aufgeräumte Sicht auf die aktive Belegschaft und kapselt
 * den Bewerberpool sowie die manuelle Erfassung in separaten Unteransichten.
 *
 * @author TransportBoss Development
 * @version 1.2.0
 */

require_once 'db_connect.php';
require_once 'classes/Driver.php';
require_once 'classes/DriverRepository.php';
require_once 'classes/PersonnelParser.php';

use classes\PersonnelParser;

// Session-basiertes Feedback zur Vermeidung von F5-Doppelposts
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$message = $_SESSION['pb_message'] ?? '';
$messageClass = $_SESSION['pb_message_class'] ?? '';
unset($_SESSION['pb_message'], $_SESSION['pb_message_class']);

$driverRepo = new DriverRepository($pdo);

// Aktive Unteransicht bestimmen (employed, applicants, add_dispatcher, edit_dispatcher)
$view = $_GET['view'] ?? 'employed';
if (isset($_GET['edit_dispatcher_id'])) {
    $view = 'edit_dispatcher';
}

// -------------------------------------------------------------
// POST-AKTIONEN VERARBEITEN
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];
        $redirectUrl = 'personnel_manager.php';

        // 1. HTML Stellenmarkt Import (Direkt-Parser)
        if ($action === 'import_personnel' && !empty($_POST['import_data'])) {
            $htmlData = $_POST['import_data'];
            $parser = new PersonnelParser();
            $parsedPersonnel = $parser->parse($htmlData);

            $importedDrivers = 0;
            $importedDispatchers = 0;

            foreach ($parsedPersonnel as $person) {
                $jobTitle = strtolower($person['job_title']);

                if ($jobTitle === 'fahrer') {
                    $driver = new Driver(
                        $person['ingame_id'],
                        $person['first_name'],
                        $person['last_name'],
                        $person['age'],
                        $person['skill_val'],
                        $person['reliability_val'],
                        (bool)$person['adr_permit'],
                        $person['penalty_points'],
                        $person['salary'],
                        false // is_employed = false bei Import (Bewerbung)
                    );
                    $driverRepo->save($driver);
                    $importedDrivers++;
                } elseif ($jobTitle === 'disponent') {
                    $stmtDisp = $pdo->prepare("
                        INSERT INTO dispatchers (ingame_dispatcher_id, first_name, last_name, age, skill_val, reliability_val, salary, is_employed)
                        VALUES (:ingame_id, :first, :last, :age, :skill, :reliability, :salary, 0)
                        ON DUPLICATE KEY UPDATE 
                            first_name = :first,
                            last_name = :last,
                            age = :age,
                            skill_val = :skill,
                            reliability_val = :reliability,
                            salary = :salary
                    ");
                    $stmtDisp->execute([
                        'ingame_id' => (int)$person['ingame_id'],
                        'first' => $person['first_name'],
                        'last' => $person['last_name'],
                        'age' => (int)$person['age'],
                        'skill' => (int)$person['skill_val'],
                        'reliability' => (int)$person['reliability_val'],
                        'salary' => (float)$person['salary']
                    ]);
                    $importedDispatchers++;
                }
            }

            $_SESSION['pb_message'] = "Import beendet! " . $importedDrivers . " Fahrer- und " . $importedDispatchers . " Disponenten-Bewerber erfolgreich eingelesen.";
            $_SESSION['pb_message_class'] = "status-success";
            $redirectUrl = 'personnel_manager.php?view=applicants';
        }

        // 2. Personal einstellen (Fahrer)
        elseif ($action === 'hire_driver') {
            $driverId = (int)$_POST['driver_id'];
            $stmt = $pdo->prepare("UPDATE drivers SET is_employed = 1 WHERE id = ?");
            $stmt->execute([$driverId]);
            $_SESSION['pb_message'] = "Fahrer erfolgreich eingestellt und unter Vertrag genommen.";
            $_SESSION['pb_message_class'] = "status-success";
            $redirectUrl = 'personnel_manager.php';
        }

        // 3. Personal einstellen (Disponent)
        elseif ($action === 'hire_dispatcher') {
            $dispatcherId = (int)$_POST['dispatcher_id'];
            $stmt = $pdo->prepare("UPDATE dispatchers SET is_employed = 1 WHERE id = ?");
            $stmt->execute([$dispatcherId]);
            $_SESSION['pb_message'] = "Disponent erfolgreich eingestellt.";
            $_SESSION['pb_message_class'] = "status-success";
            $redirectUrl = 'personnel_manager.php';
        }

        // 4. Manuelle Erfassung / Bearbeitung eines Disponenten
        elseif ($action === 'save_dispatcher') {
            $ingameId = (int)$_POST['ingame_dispatcher_id'];
            $first = $_POST['first_name'];
            $last = $_POST['last_name'];
            $age = (int)$_POST['age'];
            $skill = (int)$_POST['skill_val'];
            $reliability = (int)$_POST['reliability_val'];
            $salary = (float)$_POST['salary'];

            if (isset($_POST['id']) && !empty($_POST['id'])) {
                $id = (int)$_POST['id'];
                $stmt = $pdo->prepare("
                    UPDATE dispatchers 
                    SET ingame_dispatcher_id = ?, first_name = ?, last_name = ?, age = ?, skill_val = ?, reliability_val = ?, salary = ?
                    WHERE id = ?
                ");
                $stmt->execute([$ingameId, $first, $last, $age, $skill, $reliability, $salary, $id]);
                $_SESSION['pb_message'] = "Disponenten-Daten erfolgreich aktualisiert.";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO dispatchers (ingame_dispatcher_id, first_name, last_name, age, skill_val, reliability_val, salary, is_employed)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([$ingameId, $first, $last, $age, $skill, $reliability, $salary]);
                $_SESSION['pb_message'] = "Disponent erfolgreich manuell erfasst und eingestellt.";
            }
            $_SESSION['pb_message_class'] = "status-success";
            $redirectUrl = 'personnel_manager.php';
        }

        // 5. Personal entlassen (Fahrer)
        elseif ($action === 'dismiss_driver') {
            $driverId = (int)$_POST['driver_id'];
            
            $stmtDriver = $pdo->prepare("SELECT ingame_driver_id, assigned_truck_id FROM drivers WHERE id = ?");
            $stmtDriver->execute([$driverId]);
            $dInfo = $stmtDriver->fetch(PDO::FETCH_ASSOC);

            if ($dInfo) {
                $driverIngameId = $dInfo['ingame_driver_id'];
                $truckId = $dInfo['assigned_truck_id'] ? (int)$dInfo['assigned_truck_id'] : null;

                $pdo->beginTransaction();
                
                $stmtTruck = $pdo->prepare("UPDATE trucks SET assigned_driver_id = NULL WHERE assigned_driver_id = ?");
                $stmtTruck->execute([$driverIngameId]);

                $stmtDismiss = $pdo->prepare("UPDATE drivers SET is_employed = 0, assigned_truck_id = NULL WHERE id = ?");
                $stmtDismiss->execute([$driverId]);

                if ($truckId) {
                    $stmtOrders = $pdo->prepare("
                        SELECT id, is_adr, assigned_at 
                        FROM orders 
                        WHERE assigned_truck_id = ? 
                          AND is_archived = 0 
                        ORDER BY assigned_at ASC
                    ");
                    $stmtOrders->execute([$truckId]);
                    $assignedOrders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

                    $breakActive = false;
                    foreach ($assignedOrders as $ord) {
                        if ($breakActive || (int)$ord['is_adr'] === 1) {
                            $breakActive = true;
                            $stmtUnassign = $pdo->prepare("
                                UPDATE orders 
                                SET assigned_truck_id = NULL, 
                                    assigned_at = NULL 
                                WHERE id = ?
                            ");
                            $stmtUnassign->execute([$ord['id']]);
                        }
                    }
                }

                $pdo->commit();
                $_SESSION['pb_message'] = "Fahrer entlassen und zurück in den Bewerberpool verschoben. Eventuell geplante Gefahrgut-Touren wurden automatisch storniert.";
                $_SESSION['pb_message_class'] = "status-success";
            }
            $redirectUrl = 'personnel_manager.php';
        }

        // 6. Personal entlassen (Disponent)
        elseif ($action === 'dismiss_dispatcher') {
            $dispatcherId = (int)$_POST['dispatcher_id'];
            $stmt = $pdo->prepare("UPDATE dispatchers SET is_employed = 0 WHERE id = ?");
            $stmt->execute([$dispatcherId]);
            $_SESSION['pb_message'] = "Disponent entlassen und zurück in den Bewerberpool verschoben.";
            $_SESSION['pb_message_class'] = "status-success";
            $redirectUrl = 'personnel_manager.php';
        }

        // 7. Bewerbung löschen (Fahrer)
        elseif ($action === 'delete_driver_app') {
            $driverId = (int)$_POST['driver_id'];
            $stmt = $pdo->prepare("DELETE FROM drivers WHERE id = ? AND is_employed = 0");
            $stmt->execute([$driverId]);
            $_SESSION['pb_message'] = "Fahrer-Bewerbung erfolgreich gelöscht.";
            $_SESSION['pb_message_class'] = "status-success";
            $redirectUrl = 'personnel_manager.php?view=applicants';
        }

        // 8. Bewerbung löschen (Disponent)
        elseif ($action === 'delete_dispatcher_app') {
            $dispatcherId = (int)$_POST['dispatcher_id'];
            $stmt = $pdo->prepare("DELETE FROM dispatchers WHERE id = ? AND is_employed = 0");
            $stmt->execute([$dispatcherId]);
            $_SESSION['pb_message'] = "Disponenten-Bewerbung erfolgreich gelöscht.";
            $_SESSION['pb_message_class'] = "status-success";
            $redirectUrl = 'personnel_manager.php?view=applicants';
        }

        header("Location: " . $redirectUrl);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['pb_message'] = "Fehler bei der Verarbeitung: " . $e->getMessage();
        $_SESSION['pb_message_class'] = "status-error";
        header("Location: personnel_manager.php");
        exit;
    }
}

// -------------------------------------------------------------
// DYNAMISCHE SLOT-BERECHNUNG AKTUALISIEREN (PH 4.2)
// -------------------------------------------------------------
$employedDispatchersCount = $pdo->query("SELECT skill_val FROM dispatchers WHERE is_employed = 1")->fetchAll(PDO::FETCH_ASSOC);
$maxDispoSlots = 4;
foreach ($employedDispatchersCount as $disp) {
    $maxDispoSlots += (int)floor((int)$disp['skill_val'] / 10);
}
$stmtUpdateCfg = $pdo->prepare("INSERT INTO config (cfg_key, cfg_value) VALUES ('max_dispo_slots', :val) ON DUPLICATE KEY UPDATE cfg_value = :val");
$stmtUpdateCfg->execute(['val' => (string)$maxDispoSlots]);

// -------------------------------------------------------------
// DATEN LADEN
// -------------------------------------------------------------
$employedDrivers = $pdo->query("
    SELECT d.*, t.ingame_vehicle_id AS truck_label 
    FROM drivers d
    LEFT JOIN trucks t ON d.id = t.assigned_driver_id OR d.ingame_driver_id = t.assigned_driver_id
    WHERE d.is_employed = 1 
    ORDER BY d.last_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$employedDispatchers = $pdo->query("SELECT * FROM dispatchers WHERE is_employed = 1 ORDER BY last_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$applicantDrivers = $pdo->query("SELECT * FROM drivers WHERE is_employed = 0 ORDER BY last_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$applicantDispatchers = $pdo->query("SELECT * FROM dispatchers WHERE is_employed = 0 ORDER BY last_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$editDispatcher = null;
if ($view === 'edit_dispatcher') {
    $stmtEdit = $pdo->prepare("SELECT * FROM dispatchers WHERE id = ?");
    $stmtEdit->execute([(int)$_GET['edit_dispatcher_id']]);
    $editDispatcher = $stmtEdit->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Personal-Zentrum - TransportBoss</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <?php require_once 'nav.php'; ?>
    <div class="fluid-container">
        
        <!-- KPI Slot- und Kapazitätsübersicht -->
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3 class="accent-text">Fahrer</h3>
                <div class="kpi-value"><?= count($employedDrivers) ?></div>
                <div class="kpi-desc">Eingestellte Fahrer</div>
            </div>
            <div class="dashboard-card">
                <h3 class="accent-text">Disponenten</h3>
                <div class="kpi-value"><?= count($employedDispatchers) ?></div>
                <div class="kpi-desc">Eingestellte Disponenten</div>
            </div>
            <div class="dashboard-card">
                <h3 class="accent-text">Dispositions-Slots</h3>
                <div class="kpi-value"><?= $maxDispoSlots ?></div>
                <div class="kpi-desc">Aktuelles Ingame-Limit</div>
            </div>
        </div>

        <hr class="section-divider">

        <?php if ($message): ?>
            <div class="feedback-msg <?= $messageClass ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- ==========================================================================
             ANSICHT 1: ACTIVE BELEGANSICHT (employed)
             ========================================================================== -->
        <?php if ($view === 'employed'): ?>
            <div class="workspace-header-row">
                <h1 class="accent-text">Aktive Belegschaft</h1>
                <div class="action-form">
                    <a href="?view=applicants" class="btn-primary">📥 Stellenmarkt & Bewerber</a>
                    <a href="?view=add_dispatcher" class="btn-primary">➕ Disponent manuell erfassen</a>
                </div>
            </div>

            <!-- Multisearch Eingabefeld -->
            <input type="text" id="employedSearch" class="filter-input" placeholder="Mitarbeiter durchsuchen (Multisearch)..." onkeyup="applyEmployedFilter()">

            <!-- Fahrer-Tabelle -->
            <h3 class="accent-text text-blue">🚚 Angestellte Fahrer</h3>
            <table class="data-table" id="employedDriversTable">
                <thead>
                    <tr>
                        <th>Ingame ID</th>
                        <th>Name</th>
                        <th>Alter</th>
                        <th>Fahrkönnen</th>
                        <th>Zuverlässigkeit</th>
                        <th>ADR?</th>
                        <th>Strafpunkte</th>
                        <th>Gehalt</th>
                        <th>Zugeordneter LKW</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employedDrivers)): ?>
                        <tr><td colspan="10" class="text-center text-muted-italic">Keine Fahrer angestellt.</td></tr>
                    <?php else: ?>
                        <?php foreach ($employedDrivers as $d): ?>
                        <tr class="filterable-employed-row">
                            <td><?= htmlspecialchars($d['ingame_driver_id']) ?></td>
                            <td><strong><?= htmlspecialchars($d['first_name'] . ' ' . $d['last_name']) ?></strong></td>
                            <td><?= $d['age'] ?></td>
                            <td class="text-orange"><strong><?= $d['skill_val'] ?></strong></td>
                            <td><?= $d['reliability_val'] ?>%</td>
                            <td><?= $d['adr_permit'] ? '<span class="adr-badge">[ADR]</span>' : 'Nein' ?></td>
                            <td><?= $d['penalty_points'] ?> Punkte</td>
                            <td><?= number_format((float)$d['salary'], 2, ',', '.') ?> €</td>
                            <td>
                                <?php if ($d['truck_label']): ?>
                                    <span class="text-orange">ID: <?= htmlspecialchars($d['truck_label']) ?></span>
                                <?php else: ?>
                                    <span class="text-gray">- Ungebunden -</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-form">
                                    <a href="edit_entity.php?type=driver&id=<?= $d['id'] ?>" class="btn-primary btn-small">Bearbeiten</a>
                                    <form method="post" onsubmit="return confirm('Möchten Sie diesen Fahrer wirklich kündigen?');">
                                        <input type="hidden" name="action" value="dismiss_driver">
                                        <input type="hidden" name="driver_id" value="<?= $d['id'] ?>">
                                        <button type="submit" class="btn-primary btn-danger btn-small">Kündigen</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Disponenten-Tabelle -->
            <h3 class="accent-text text-blue" style="margin-top: 40px;">🧑‍💼 Angestellte Disponenten</h3>
            <table class="data-table" id="employedDispatchersTable">
                <thead>
                    <tr>
                        <th>Ingame ID</th>
                        <th>Name</th>
                        <th>Alter</th>
                        <th>Verwaltungsskill</th>
                        <th>Slot-Beitrag</th>
                        <th>Zuverlässigkeit</th>
                        <th>Gehalt</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employedDispatchers)): ?>
                        <tr><td colspan="8" class="text-center text-muted-italic">Keine Disponenten angestellt (4 Basis-Slots aktiv).</td></tr>
                    <?php else: ?>
                        <?php foreach ($employedDispatchers as $disp): ?>
                        <?php $bonus = (int)floor((int)$disp['skill_val'] / 10); ?>
                        <tr class="filterable-employed-row">
                            <td><?= htmlspecialchars((string)$disp['ingame_dispatcher_id']) ?></td>
                            <td><strong><?= htmlspecialchars($disp['first_name'] . ' ' . $disp['last_name']) ?></strong></td>
                            <td><?= $disp['age'] ?></td>
                            <td class="text-orange"><strong><?= $disp['skill_val'] ?></strong></td>
                            <td class="text-white"><strong>+<?= $bonus ?> Slots</strong></td>
                            <td><?= $disp['reliability_val'] ?>%</td>
                            <td><?= number_format((float)$disp['salary'], 2, ',', '.') ?> €</td>
                            <td>
                                <div class="action-form">
                                    <a href="?edit_dispatcher_id=<?= $disp['id'] ?>" class="btn-primary btn-small">Bearbeiten</a>
                                    <form method="post" onsubmit="return confirm('Möchten Sie diesen Disponenten wirklich kündigen?');">
                                        <input type="hidden" name="action" value="dismiss_dispatcher">
                                        <input type="hidden" name="dispatcher_id" value="<?= $disp['id'] ?>">
                                        <button type="submit" class="btn-primary btn-danger btn-small">Kündigen</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

        <!-- ==========================================================================
             ANSICHT 2: STELLENMARKT & BEWERBUNGEN (applicants)
             ========================================================================== -->
        <?php elseif ($view === 'applicants'): ?>
            <div class="workspace-header-row">
                <h1 class="accent-text">Bewerber & Stellenmarkt</h1>
                <a href="personnel_manager.php" class="btn-primary">⬅️ Zur Belegschaft</a>
            </div>

            <!-- Stellenmarkt HTML-Importer -->
            <div class="form-box">
                <h3 class="accent-text text-blue">📥 Quelltext der Stellenanzeigen einlesen</h3>
                <form method="post" action="personnel_manager.php">
                    <input type="hidden" name="action" value="import_personnel">
                    <label for="import_data">Kopieren Sie den HTML-Quelltext des Ingame-Stellenmarkts und fügen Sie ihn hier ein:</label>
                    <textarea id="import_data" name="import_data" class="import-textarea" placeholder="HTML hier einfügen..." required></textarea>
                    <button type="submit" class="btn-primary">Einlesen und Bewerberliste aktualisieren</button>
                </form>
            </div>

            <div class="workspace-header-row" style="margin-top: 40px;">
                <h2 class="accent-text">Bewerberpool</h2>
                <input type="text" id="applicantsSearch" class="filter-input" placeholder="Bewerber durchsuchen (Multisearch)..." onkeyup="applyApplicantsFilter()">
            </div>

            <!-- Fahrer-Bewerbungen -->
            <h3 class="accent-text text-blue">🚚 Fahrer-Bewerber</h3>
            <table class="data-table" id="applicantDriversTable">
                <thead>
                    <tr>
                        <th>Ingame ID</th>
                        <th>Name</th>
                        <th>Alter</th>
                        <th>Fahrkönnen</th>
                        <th>Zuverlässigkeit</th>
                        <th>ADR?</th>
                        <th>Strafpunkte</th>
                        <th>Gehaltswunsch</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($applicantDrivers)): ?>
                        <tr><td colspan="9" class="text-center text-muted-italic">Keine Fahrer-Bewerbungen vorhanden. Nutzen Sie den Importer oben.</td></tr>
                    <?php else: ?>
                        <?php foreach ($applicantDrivers as $d): ?>
                        <tr class="filterable-applicant-row">
                            <td><?= htmlspecialchars($d['ingame_driver_id']) ?></td>
                            <td><strong><?= htmlspecialchars($d['first_name'] . ' ' . $d['last_name']) ?></strong></td>
                            <td><?= $d['age'] ?></td>
                            <td class="text-orange"><strong><?= $d['skill_val'] ?></strong></td>
                            <td><?= $d['reliability_val'] ?>%</td>
                            <td><?= $d['adr_permit'] ? '<span class="adr-badge">[ADR]</span>' : 'Nein' ?></td>
                            <td><?= $d['penalty_points'] ?> Punkte</td>
                            <td><?= number_format((float)$d['salary'], 2, ',', '.') ?> €</td>
                            <td>
                                <div class="action-form">
                                    <form method="post">
                                        <input type="hidden" name="action" value="hire_driver">
                                        <input type="hidden" name="driver_id" value="<?= $d['id'] ?>">
                                        <button type="submit" class="btn-primary btn-small">Einstellen</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Möchten Sie diese Bewerbung dauerhaft löschen?');">
                                        <input type="hidden" name="action" value="delete_driver_app">
                                        <input type="hidden" name="driver_id" value="<?= $d['id'] ?>">
                                        <button type="submit" class="btn-primary btn-danger btn-small">Löschen</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Disponenten-Bewerbungen -->
            <h3 class="accent-text text-blue" style="margin-top: 40px;">🧑‍💼 Disponenten-Bewerber</h3>
            <table class="data-table" id="applicantDispatchersTable">
                <thead>
                    <tr>
                        <th>Ingame ID</th>
                        <th>Name</th>
                        <th>Alter</th>
                        <th>Verwaltungsskill</th>
                        <th>Potenzieller Bonus</th>
                        <th>Zuverlässigkeit</th>
                        <th>Gehaltswunsch</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($applicantDispatchers)): ?>
                        <tr><td colspan="8" class="text-center text-muted-italic">Keine Disponenten-Bewerbungen vorhanden. Nutzen Sie den Importer oben.</td></tr>
                    <?php else: ?>
                        <?php foreach ($applicantDispatchers as $disp): ?>
                        <?php $bonus = (int)floor((int)$disp['skill_val'] / 10); ?>
                        <tr class="filterable-applicant-row">
                            <td><?= htmlspecialchars((string)$disp['ingame_dispatcher_id']) ?></td>
                            <td><strong><?= htmlspecialchars($disp['first_name'] . ' ' . $disp['last_name']) ?></strong></td>
                            <td><?= $disp['age'] ?></td>
                            <td class="text-orange"><strong><?= $disp['skill_val'] ?></strong></td>
                            <td>+<?= $bonus ?> Slots</td>
                            <td><?= $disp['reliability_val'] ?>%</td>
                            <td><?= number_format((float)$disp['salary'], 2, ',', '.') ?> €</td>
                            <td>
                                <div class="action-form">
                                    <form method="post">
                                        <input type="hidden" name="action" value="hire_dispatcher">
                                        <input type="hidden" name="dispatcher_id" value="<?= $disp['id'] ?>">
                                        <button type="submit" class="btn-primary btn-small">Einstellen</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Möchten Sie diese Bewerbung dauerhaft löschen?');">
                                        <input type="hidden" name="action" value="delete_dispatcher_app">
                                        <input type="hidden" name="dispatcher_id" value="<?= $disp['id'] ?>">
                                        <button type="submit" class="btn-primary btn-danger btn-small">Löschen</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

        <!-- ==========================================================================
             ANSICHT 3: MANUELLE ERFASSUNG / BEARBEITUNG DISPONENT (add_dispatcher / edit_dispatcher)
             ========================================================================== -->
        <?php elseif ($view === 'add_dispatcher' || $view === 'edit_dispatcher'): ?>
            <div class="workspace-header-row">
                <h1 class="accent-text"><?= $editDispatcher ? 'Mitarbeiterdaten bearbeiten' : 'Neuen Disponenten manuell erfassen' ?></h1>
                <a href="personnel_manager.php" class="btn-primary">⬅️ Zurück</a>
            </div>

            <div class="form-box">
                <form method="post" action="personnel_manager.php">
                    <input type="hidden" name="action" value="save_dispatcher">
                    <?php if ($editDispatcher): ?>
                        <input type="hidden" name="id" value="<?= $editDispatcher['id'] ?>">
                    <?php endif; ?>
                    
                    <div class="dashboard-grid">
                        <div class="input-group">
                            <label>Ingame-ID (Personalnummer)</label>
                            <input type="text" name="ingame_dispatcher_id" value="<?= htmlspecialchars((string)($editDispatcher['ingame_dispatcher_id'] ?? '')) ?>" required>
                        </div>
                        <div class="input-group">
                            <label>Vorname</label>
                            <input type="text" name="first_name" value="<?= htmlspecialchars($editDispatcher['first_name'] ?? '') ?>" required>
                        </div>
                        <div class="input-group">
                            <label>Nachname</label>
                            <input type="text" name="last_name" value="<?= htmlspecialchars($editDispatcher['last_name'] ?? '') ?>" required>
                        </div>
                        <div class="input-group">
                            <label>Alter</label>
                            <input type="number" name="age" value="<?= htmlspecialchars((string)($editDispatcher['age'] ?? '')) ?>" required>
                        </div>
                        <div class="input-group">
                            <label>Verwaltungsskill (0-100+)</label>
                            <input type="number" name="skill_val" value="<?= htmlspecialchars((string)($editDispatcher['skill_val'] ?? '')) ?>" required>
                        </div>
                        <div class="input-group">
                            <label>Zuverlässigkeit (0-100%)</label>
                            <input type="number" name="reliability_val" value="<?= htmlspecialchars((string)($editDispatcher['reliability_val'] ?? '')) ?>" required>
                        </div>
                        <div class="input-group">
                            <label>Gehaltsvereinbarung (€ / Monat)</label>
                            <input type="number" name="salary" step="0.01" value="<?= htmlspecialchars((string)($editDispatcher['salary'] ?? '')) ?>" required>
                        </div>
                        <div class="input-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn-primary w-100"><?= $editDispatcher ? 'Änderungen speichern' : 'Einstellen & Erfassen' ?></button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>

    </div>

    <!-- Client-Side Filter & Multi-Search -->
    <script>
        // Multi-Search Filter für Angestellte (UND-Verknüpfung)
        function applyEmployedFilter() {
            const query = document.getElementById('employedSearch').value.toLowerCase();
            const rows = document.querySelectorAll('.filterable-employed-row');
            const keywords = query.split(/\s+/).filter(k => k.trim() !== '');

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                let match = true;
                for (let kw of keywords) {
                    if (!text.includes(kw)) {
                        match = false;
                        break;
                    }
                }
                row.style.display = match ? '' : 'none';
            });
        }

        // Multi-Search Filter für Bewerber (UND-Verknüpfung)
        function applyApplicantsFilter() {
            const query = document.getElementById('applicantsSearch').value.toLowerCase();
            const rows = document.querySelectorAll('.filterable-applicant-row');
            const keywords = query.split(/\s+/).filter(k => k.trim() !== '');

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                let match = true;
                for (let kw of keywords) {
                    if (!text.includes(kw)) {
                        match = false;
                        break;
                    }
                }
                row.style.display = match ? '' : 'none';
            });
        }
    </script>
</body>
</html>