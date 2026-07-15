<?php
declare(strict_types=1);

/**
 * personnel_manager.php
 *
 * Das zentrale HR- und Personal-Cockpit von TransportBoss.
 * Bietet eine universelle Datentabelle mit reaktiver Filterung nach Angestellten,
 * Bewerbern, Archiv und der kompletten HR-Historie. Ermöglicht die manuelle
 * Bearbeitung von Mitarbeiterwerten (Schulungs-Schnittstelle) sowie Fahrer-Zuweisungen.
 *
 * @author TransportBoss Development
 * @version 3.0.0
 */

require_once 'db_connect.php';
require_once 'classes/Driver.php';
require_once 'classes/DriverRepository.php';
require_once 'classes/PersonnelParser.php';

use classes\PersonnelParser;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Session-basiertes Feedback laden
$message = $_SESSION['pb_hr_message'] ?? '';
$messageClass = $_SESSION['pb_hr_message_class'] ?? '';
unset($_SESSION['pb_hr_message'], $_SESSION['pb_hr_message_class']);

/**
 * PersonnelController
 *
 * Kapselt alle Geschäfts- und Datenoperationen der Personalverwaltung (PH § 1.3.1).
 */
class PersonnelController
{
    private PDO $pdo;
    private int $gameYear;

    /**
     * @param PDO $pdo Die aktive Datenbankverbindung
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureDatabaseSchema();
        $this->gameYear = (int)($this->pdo->query("SELECT cfg_value FROM config WHERE cfg_key = 'game_year'")->fetchColumn() ?: date('Y'));
    }

    /**
     * Führt bei Bedarf die Schema-Migration auf Tabellenebene durch.
     */
    private function ensureDatabaseSchema(): void
    {
        $colsDrivers = $this->pdo->query("DESCRIBE drivers")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('last_seen_at', $colsDrivers, true)) {
            $this->pdo->exec("ALTER TABLE drivers ADD COLUMN last_seen_at TIMESTAMP NULL DEFAULT NULL");
        }

        $colsDispatchers = $this->pdo->query("DESCRIBE dispatchers")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('last_seen_at', $colsDispatchers, true)) {
            $this->pdo->exec("ALTER TABLE dispatchers ADD COLUMN last_seen_at TIMESTAMP NULL DEFAULT NULL");
        }
        if (!in_array('role', $colsDispatchers, true)) {
            $this->pdo->exec("ALTER TABLE dispatchers ADD COLUMN role VARCHAR(50) DEFAULT 'disponent'");
        }
    }

    /**
     * Verarbeitet den POST-Request für den Stellenmarkt-Import (PH § 5.3).
     *
     * @param string $htmlData Der einkopierte HTML-Quelltext
     * @return array Statusdaten für das UI-Feedback
     */
    public function handleImport(string $htmlData): array
    {
        $htmlData = trim($htmlData);
        if ($htmlData === '') {
            return [
                'message' => 'Bitte fügen Sie Daten für den Import ein.',
                'messageClass' => 'status-error'
            ];
        }

        try {
            $parser = new PersonnelParser();
            $parsedPersonnel = $parser->parse($htmlData);

            if (empty($parsedPersonnel)) {
                return [
                    'message' => 'Keine Bewerbungen im Quelltext gefunden. Bitte überprüfen Sie das Format.',
                    'messageClass' => 'status-error'
                ];
            }

            $this->pdo->beginTransaction();
            $inserted = 0;

            $stmtDriverUpsert = $this->pdo->prepare("
                INSERT INTO drivers (
                    ingame_driver_id, first_name, last_name, age, skill_val, reliability_val, adr_permit, penalty_points, salary, is_employed, last_seen_at
                ) VALUES (
                    :id, :first, :last, :age, :skill, :reliability, :adr, :penalty, :salary, 0, NOW()
                ) ON DUPLICATE KEY UPDATE 
                    skill_val = VALUES(skill_val),
                    reliability_val = VALUES(reliability_val),
                    salary = VALUES(salary),
                    penalty_points = VALUES(penalty_points),
                    adr_permit = VALUES(adr_permit),
                    last_seen_at = NOW()
            ");

            $stmtDispatcherUpsert = $this->pdo->prepare("
                INSERT INTO dispatchers (
                    ingame_dispatcher_id, first_name, last_name, age, skill_val, reliability_val, salary, is_employed, role, last_seen_at
                ) VALUES (
                    :id, :first, :last, :age, :skill, :reliability, :salary, 0, :role, NOW()
                ) ON DUPLICATE KEY UPDATE 
                    skill_val = VALUES(skill_val),
                    reliability_val = VALUES(reliability_val),
                    salary = VALUES(salary),
                    last_seen_at = NOW()
            ");

            foreach ($parsedPersonnel as $p) {
                $role = strtolower($p['job_title']);

                if ($role === 'fahrer') {
                    $stmtDriverUpsert->execute([
                        'id' => $p['ingame_id'],
                        'first' => $p['first_name'],
                        'last' => $p['last_name'],
                        'age' => (int)$p['age'],
                        'skill' => (int)$p['skill_val'],
                        'reliability' => (int)$p['reliability_val'],
                        'adr' => (int)$p['adr_permit'],
                        'penalty' => (int)$p['penalty_points'],
                        'salary' => (float)$p['salary']
                    ]);
                } else {
                    $stmtDispatcherUpsert->execute([
                        'id' => $p['ingame_id'],
                        'first' => $p['first_name'],
                        'last' => $p['last_name'],
                        'age' => (int)$p['age'],
                        'skill' => (int)$p['skill_val'],
                        'reliability' => (int)$p['reliability_val'],
                        'salary' => (float)$p['salary'],
                        'role' => $role
                    ]);
                }
                $inserted++;
            }

            $this->pdo->commit();

            return [
                'message' => "Import erfolgreich! {$inserted} Profile verarbeitet.",
                'messageClass' => 'status-success'
            ];

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return [
                'message' => "Fehler beim Personal-Import: " . $e->getMessage(),
                'messageClass' => 'status-error'
            ];
        }
    }

    /**
     * Stellt einen Bewerber ein.
     */
    public function hirePersonnel(int $id, string $role): void
    {
        if ($role === 'fahrer') {
            $stmt = $this->pdo->prepare("UPDATE drivers SET is_employed = 1 WHERE id = ?");
        } else {
            $stmt = $this->pdo->prepare("UPDATE dispatchers SET is_employed = 1 WHERE id = ?");
        }
        $stmt->execute([$id]);
    }

    /**
     * Entlässt einen Angestellten und verschiebt ihn direkt ins inaktive HR-Archiv.
     */
    public function dismissPersonnel(int $id, string $role): void
    {
        $this->pdo->beginTransaction();
        try {
            if ($role === 'fahrer') {
                $stmtF = $this->pdo->prepare("SELECT ingame_driver_id, assigned_truck_id FROM drivers WHERE id = ?");
                $stmtF->execute([$id]);
                $driver = $stmtF->fetch(PDO::FETCH_ASSOC);

                if ($driver) {
                    $driverIngameId = $driver['ingame_driver_id'];
                    $truckId = $driver['assigned_truck_id'] ? (int)$driver['assigned_truck_id'] : null;

                    $this->pdo->prepare("UPDATE trucks SET assigned_driver_id = NULL WHERE assigned_driver_id = ?")->execute([$driverIngameId]);
                    $this->pdo->prepare("UPDATE drivers SET is_employed = 0, assigned_truck_id = NULL, last_seen_at = NULL WHERE id = ?")->execute([$id]);

                    if ($truckId) {
                        $this->runAdrSafetyInterlock($truckId);
                    }
                }
            } else {
                $this->pdo->prepare("UPDATE dispatchers SET is_employed = 0, last_seen_at = NULL WHERE id = ?")->execute([$id]);
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Verknüpft einen Fahrer mit einem LKW.
     */
    public function linkDriverToTruck(string $driverIngameId, int $truckId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE drivers SET assigned_truck_id = NULL WHERE ingame_driver_id = ?")->execute([$driverIngameId]);
            $this->pdo->prepare("UPDATE trucks SET assigned_driver_id = NULL WHERE id = ?")->execute([$truckId]);
            $this->pdo->prepare("UPDATE trucks SET assigned_driver_id = ? WHERE id = ?")->execute([$driverIngameId, $truckId]);
            $this->pdo->prepare("UPDATE drivers SET assigned_truck_id = ? WHERE ingame_driver_id = ?")->execute([$truckId, $driverIngameId]);

            $this->runAdrSafetyInterlock($truckId);
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Löst den Fahrer vom LKW (Abkoppeln).
     */
    public function unlinkDriverFromTruck(int $driverId): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmtF = $this->pdo->prepare("SELECT ingame_driver_id, assigned_truck_id FROM drivers WHERE id = ?");
            $stmtF->execute([$driverId]);
            $driver = $stmtF->fetch(PDO::FETCH_ASSOC);

            if ($driver && $driver['assigned_truck_id']) {
                $truckId = (int)$driver['assigned_truck_id'];
                $this->pdo->prepare("UPDATE trucks SET assigned_driver_id = NULL WHERE id = ?")->execute([$truckId]);
                $this->pdo->prepare("UPDATE drivers SET assigned_truck_id = NULL WHERE id = ?")->execute([$driverId]);

                $this->runAdrSafetyInterlock($truckId);
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Strikter ADR-Sicherheits-Interlock (PH § 5.4.2.3)
     */
    public function runAdrSafetyInterlock(int $truckId): void
    {
        $stmtDriver = $this->pdo->prepare("
            SELECT d.adr_permit 
            FROM drivers d
            JOIN trucks t ON t.assigned_driver_id = d.ingame_driver_id
            WHERE t.id = ? AND d.is_employed = 1
        ");
        $stmtDriver->execute([$truckId]);
        $hasAdr = $stmtDriver->fetchColumn();

        if ($hasAdr === false || (int)$hasAdr === 0) {
            $stmtOrders = $this->pdo->prepare("
                SELECT id, is_adr 
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
                    $this->pdo->prepare("UPDATE orders SET assigned_truck_id = NULL, assigned_at = NULL WHERE id = ?")->execute([$ord['id']]);
                }
            }
        }
    }

    /**
     * Lädt das Profil eines einzelnen Mitarbeiters zur Bearbeitung.
     */
    public function getPersonById(int $id, string $role): ?array
    {
        if ($role === 'fahrer') {
            $stmt = $this->pdo->prepare("SELECT * FROM drivers WHERE id = ?");
        } else {
            $stmt = $this->pdo->prepare("SELECT * FROM dispatchers WHERE id = ?");
        }
        $stmt->execute([$id]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            $res['role'] = $role;
        }
        return $res ?: null;
    }

    /**
     * Lädt alle Profile und mappt sie in ein einheitliches Tabellenmodell.
     */
    public function getPersonnelModel(): array
    {
        $drivers = $this->pdo->query("
            SELECT d.*, t.ingame_vehicle_id AS truck_label 
            FROM drivers d
            LEFT JOIN trucks t ON d.assigned_truck_id = t.id OR d.ingame_driver_id = t.assigned_driver_id
        ")->fetchAll(PDO::FETCH_ASSOC);

        $dispatchers = $this->pdo->query("SELECT * FROM dispatchers")->fetchAll(PDO::FETCH_ASSOC);

        $maxDriversSeen = $this->pdo->query("SELECT MAX(last_seen_at) FROM drivers WHERE is_employed = 0")->fetchColumn();
        $maxDispatchersSeen = $this->pdo->query("SELECT MAX(last_seen_at) FROM dispatchers WHERE is_employed = 0")->fetchColumn();
        $lastImportTime = max($maxDriversSeen ?: '1970-01-01 00:00:00', $maxDispatchersSeen ?: '1970-01-01 00:00:00');

        $allPersonnel = [];
        $roleValues = [];

        foreach ($drivers as $d) {
            $item = [
                'id' => (int)$d['id'],
                'ingame_id' => $d['ingame_driver_id'],
                'name' => $d['first_name'] . ' ' . $d['last_name'],
                'role' => 'fahrer',
                'role_label' => '🚚 Fahrer',
                'age' => (int)$d['age'],
                'skill' => (int)$d['skill_val'],
                'reliability' => (int)$d['reliability_val'],
                'adr_permit' => (int)$d['adr_permit'],
                'penalty_points' => (int)$d['penalty_points'],
                'salary' => (float)$d['salary'],
                'truck_label' => $d['truck_label'],
                'is_employed' => (int)$d['is_employed'],
                'is_active_applicant' => ($d['last_seen_at'] !== null && $d['last_seen_at'] >= $lastImportTime) ? 1 : 0
            ];
            $allPersonnel[] = $item;

            if ($item['is_employed'] === 0 && $item['skill'] > 0) {
                $roleValues['fahrer'][] = $item['salary'] / $item['skill'];
            }
        }

        $roleLabelMap = [
            'disponent' => '🧑‍💼 Disponent',
            'bürokraft' => '📁 Bürokraft',
            'kfz-techniker' => '🔧 Kfz-Techniker',
            'tankwart' => '⛽ Tankwart'
        ];

        foreach ($dispatchers as $disp) {
            $roleKey = strtolower($disp['role']);
            $item = [
                'id' => (int)$disp['id'],
                'ingame_id' => $disp['ingame_dispatcher_id'],
                'name' => $disp['first_name'] . ' ' . $disp['last_name'],
                'role' => $roleKey,
                'role_label' => $roleLabelMap[$roleKey] ?? ucfirst($roleKey),
                'age' => (int)$disp['age'],
                'skill' => in_array($roleKey, ['disponent', 'kfz-techniker'], true) ? (int)$disp['skill_val'] : null,
                'reliability' => (int)$disp['reliability_val'],
                'adr_permit' => 0,
                'penalty_points' => 0,
                'salary' => (float)$disp['salary'],
                'truck_label' => null,
                'is_employed' => (int)$disp['is_employed'],
                'is_active_applicant' => ($disp['last_seen_at'] !== null && $disp['last_seen_at'] >= $lastImportTime) ? 1 : 0
            ];
            $allPersonnel[] = $item;

            if ($item['is_employed'] === 0) {
                if ($item['skill'] !== null && $item['skill'] > 0) {
                    $roleValues[$roleKey][] = $item['salary'] / $item['skill'];
                } else {
                    $roleValues[$roleKey][] = $item['salary'];
                }
            }
        }

        $averages = [];
        foreach ($roleValues as $r => $vals) {
            $averages[$r] = count($vals) > 0 ? (array_sum($vals) / count($vals)) : 0.0;
        }

        return [
            'all' => $allPersonnel,
            'averages' => $averages,
            'gameYear' => $this->gameYear
        ];
    }
}

// Controller instanziieren und verarbeiten
$controller = new PersonnelController($pdo);

// -------------------------------------------------------------
// POST-AKTIONEN VERARBEITEN
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];

        // 1. Quelltext-Import
        if ($action === 'import_personnel' && !empty($_POST['import_data'])) {
            $result = $controller->handleImport($_POST['import_data']);
            $_SESSION['pb_hr_message'] = $result['message'];
            $_SESSION['pb_hr_message_class'] = $result['messageClass'];
        }
        // 2. Personal einstellen (Hire)
        elseif ($action === 'hire_personnel') {
            $id = (int)$_POST['id'];
            $role = $_POST['role'];
            $controller->hirePersonnel($id, $role);
            $_SESSION['pb_hr_message'] = "Mitarbeiter erfolgreich eingestellt.";
            $_SESSION['pb_hr_message_class'] = "status-success";
        }
        // 3. Kündigung (Dismiss)
        elseif ($action === 'dismiss_personnel') {
            $id = (int)$_POST['id'];
            $role = $_POST['role'];
            $controller->dismissPersonnel($id, $role);
            $_SESSION['pb_hr_message'] = "Mitarbeiter erfolgreich entlassen und direkt ins HR-Archiv verschoben.";
            $_SESSION['pb_hr_message_class'] = "status-success";
        }
        // 4. Fahrer-Zuweisung
        elseif ($action === 'assign_driver') {
            $driverIngameId = $_POST['driver_ingame_id'];
            $truckId = (int)$_POST['truck_id'];
            $controller->linkDriverToTruck($driverIngameId, $truckId);
            $_SESSION['pb_hr_message'] = "Fahrzuweisung erfolgreich eingetragen. ADR-Sicherheits-Interlock wurde ausgeführt.";
            $_SESSION['pb_hr_message_class'] = "status-success";
        }
        // 5. Fahrer abkoppeln
        elseif ($action === 'unassign_driver') {
            $driverId = (int)$_POST['driver_id'];
            $controller->unlinkDriverFromTruck($driverId);
            $_SESSION['pb_hr_message'] = "Fahrer erfolgreich vom LKW entkoppelt. Gefahrgut-Schnittstellen wurden geprüft.";
            $_SESSION['pb_hr_message_class'] = "status-success";
        }
        // 6. Personal aktualisieren (Bearbeiten/Fortbildung)
        elseif ($action === 'update_personnel') {
            $id = (int)$_POST['id'];
            $role = $_POST['role'];
            $first = $_POST['first_name'];
            $last = $_POST['last_name'];
            $age = (int)$_POST['age'];
            $skill = isset($_POST['skill_val']) ? (int)$_POST['skill_val'] : 0;
            $reliability = (int)$_POST['reliability_val'];
            $salary = (float)$_POST['salary'];

            if ($role === 'fahrer') {
                $adr = isset($_POST['adr_permit']) ? 1 : 0;
                $penalty = (int)$_POST['penalty_points'];
                $stmt = $pdo->prepare("
                    UPDATE drivers 
                    SET first_name = ?, last_name = ?, age = ?, skill_val = ?, reliability_val = ?, salary = ?, adr_permit = ?, penalty_points = ?
                    WHERE id = ?
                ");
                $stmt->execute([$first, $last, $age, $skill, $reliability, $salary, $adr, $penalty, $id]);
                
                // ADR-Statusänderungen prüfen
                $stmtTruck = $pdo->prepare("SELECT assigned_truck_id FROM drivers WHERE id = ?");
                $stmtTruck->execute([$id]);
                $truckId = $stmtTruck->fetchColumn();
                if ($truckId) {
                    $controller->runAdrSafetyInterlock((int)$truckId);
                }
            } else {
                $stmt = $pdo->prepare("
                    UPDATE dispatchers 
                    SET first_name = ?, last_name = ?, age = ?, skill_val = ?, reliability_val = ?, salary = ?
                    WHERE id = ?
                ");
                $stmt->execute([$first, $last, $age, $skill, $reliability, $salary, $id]);
            }
            $_SESSION['pb_hr_message'] = "Personalwerte nach Schulung/Fortbildung erfolgreich aktualisiert.";
            $_SESSION['pb_hr_message_class'] = "status-success";
        }

    } catch (Exception $e) {
        $_SESSION['pb_hr_message'] = "Fehler bei der Transaktion: " . $e->getMessage();
        $_SESSION['pb_hr_message_class'] = "status-error";
    }

    header("Location: personnel_manager.php");
    exit;
}

// -------------------------------------------------------------
// GET-DATEN LADEN (RENDERING & BEARBEITUNGSMASKE)
// -------------------------------------------------------------
$model = $controller->getPersonnelModel();
$allPersonnel = $model['all'];
$averages = $model['averages'];
$gameYear = $model['gameYear'];

$editPerson = null;
if (isset($_GET['edit_id']) && isset($_GET['role'])) {
    $editPerson = $controller->getPersonById((int)$_GET['edit_id'], $_GET['role']);
}

// Freie LKW für das Zuweisungs-Dropdown laden
$freeTrucks = $pdo->query("SELECT id, ingame_vehicle_id, vehicle_type FROM trucks WHERE assigned_driver_id IS NULL")->fetchAll(PDO::FETCH_ASSOC);

// Statistiken für die KPI-Karten zählen
$employedCount = 0;
$activeApplicantsCount = 0;
$archivedApplicantsCount = 0;
foreach ($allPersonnel as $p) {
    if ($p['is_employed'] === 1) {
        $employedCount++;
    } else {
        if ($p['is_active_applicant'] === 1) {
            $activeApplicantsCount++;
        } else {
            $archivedApplicantsCount++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>HR Personal-Zentrum - TransportBoss</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <?php require_once 'nav.php'; ?>
    <div class="fluid-container">
        
        <!-- KPI Personal-Dashboard -->
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3 class="accent-text">Angestellte</h3>
                <div class="kpi-value" style="color: #2ecc71;"><?= $employedCount ?></div>
                <div class="kpi-desc">Mitarbeiter unter Vertrag</div>
            </div>
            <div class="dashboard-card">
                <h3 class="accent-text">Bewerberpool</h3>
                <div class="kpi-value" style="color: #3498db;"><?= $activeApplicantsCount ?></div>
                <div class="kpi-desc">Aktive Bewerbungen im Stellenmarkt</div>
            </div>
            <div class="dashboard-card">
                <h3 class="accent-text">HR-Archiv</h3>
                <div class="kpi-value" style="color: #7f8c8d;"><?= $archivedApplicantsCount ?></div>
                <div class="kpi-desc">Kündigungen &amp; historische Profile</div>
            </div>
        </div>

        <hr class="section-divider">

        <?php if ($message): ?>
            <div class="feedback-msg <?= $messageClass ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- DYNAMISCHES BEARBEITUNGS- & SCHULUNGSFORMULAR (Am Kopf gerendert bei Klick auf Edit) -->
        <?php if ($editPerson): ?>
            <div class="form-box">
                <h3 class="accent-text">Personalwerte anpassen (Schulung &amp; Gehalt): <?= htmlspecialchars($editPerson['first_name'] . ' ' . $editPerson['last_name']) ?></h3>
                <form method="post" action="personnel_manager.php">
                    <input type="hidden" name="action" value="update_personnel">
                    <input type="hidden" name="id" value="<?= $editPerson['id'] ?>">
                    <input type="hidden" name="role" value="<?= $editPerson['role'] ?>">
                    
                    <div class="dashboard-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
                        <div class="input-group">
                            <label>Vorname</label>
                            <input type="text" name="first_name" value="<?= htmlspecialchars($editPerson['first_name']) ?>" required>
                        </div>
                        <div class="input-group">
                            <label>Nachname</label>
                            <input type="text" name="last_name" value="<?= htmlspecialchars($editPerson['last_name']) ?>" required>
                        </div>
                        <div class="input-group">
                            <label>Alter</label>
                            <input type="number" name="age" value="<?= $editPerson['age'] ?>" required>
                        </div>
                        
                        <?php if (in_array($editPerson['role'], ['fahrer', 'disponent', 'kfz-techniker'], true)): ?>
                            <div class="input-group">
                                <label>Berufsspezifischer Skill</label>
                                <input type="number" name="skill_val" value="<?= $editPerson['skill_val'] ?>" required>
                            </div>
                        <?php endif; ?>

                        <div class="input-group">
                            <label>Zuverlässigkeit (0-100%)</label>
                            <input type="number" name="reliability_val" value="<?= $editPerson['reliability_val'] ?>" required>
                        </div>
                        <div class="input-group">
                            <label>Gehalt (€ / Monat)</label>
                            <input type="number" name="salary" step="0.01" value="<?= $editPerson['salary'] ?>" required>
                        </div>

                        <?php if ($editPerson['role'] === 'fahrer'): ?>
                            <div class="input-group">
                                <label>Strafpunkte in Flensburg</label>
                                <input type="number" name="penalty_points" value="<?= $editPerson['penalty_points'] ?>" required>
                            </div>
                            <div class="input-group" style="display: flex; align-items: center; justify-content: center; height: 100%;">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0;">
                                    <input type="checkbox" name="adr_permit" <?= $editPerson['adr_permit'] ? 'checked' : '' ?> style="width: auto;">
                                    ADR vorhanden
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div style="margin-top: 15px; display: flex; gap: 10px;">
                        <button type="submit" class="btn-primary">Änderungen speichern</button>
                        <a href="personnel_manager.php" class="btn-primary" style="background-color: #7f8c8d; text-decoration: none; display: inline-block; padding: 10px 20px; border-radius: 3px;">Abbrechen</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- NATIVES EINKLAPPBARES IMPORT-PANEL -->
        <details class="form-box">
            <summary class="accent-text" style="cursor: pointer; font-weight: 600;">📥 Stellenmarkt-Quelltext einlesen (HTML Import)</summary>
            <form method="post" action="personnel_manager.php" style="margin-top: 15px;">
                <input type="hidden" name="action" value="import_personnel">
                <label for="import_data">Fügen Sie hier den kompletten HTML-Quelltext der Ingame-Stellengesuche ein (Fahrer &amp; Disponenten):</label>
                <textarea id="import_data" name="import_data" class="import-textarea" placeholder="HTML-Quellcode kopieren und hier einfügen..." required></textarea>
                <button type="submit" class="btn-primary">Personal-Daten importieren</button>
            </form>
        </details>

        <!-- COCKPIT FILTERPANEL (EINZELNE TABELLE, ZENTRALE WEICHE) -->
        <div class="filter-panel" style="margin-top: 25px;">
            <div class="filter-group">
                <label for="filterStatus">HR-Status / Weiche:</label>
                <select id="filterStatus" class="inline-select" onchange="applyPersonnelFilter()">
                    <option value="employed">Eingestellte Angestellte (aktiv unter Vertrag)</option>
                    <option value="applicants">Bewerberpool (aktiver Stellenmarkt)</option>
                    <option value="archived">HR-Archiv (Kündigungen &amp; historische Profile)</option>
                    <option value="all">Komplette HR-Historie (Alle anzeigen)</option>
                </select>
            </div>

            <div class="filter-group filter-search-group">
                <label for="personnelSearch">Multisearch (Personal-Suche):</label>
                <input type="text" id="personnelSearch" class="filter-input" placeholder="Nach ID, Name, Rolle, LKW oder ADR filtern..." onkeyup="applyPersonnelFilter()">
            </div>
        </div>

        <!-- UNIFIZIERTE TABELLE FÜR ALLES -->
        <table class="data-table" id="personnelTable">
            <thead>
                <tr>
                    <th onclick="sortTable('personnelTable', 0, 'string')">Berufsrolle ⇕</th>
                    <th onclick="sortTable('personnelTable', 1, 'string')">Name (ID) ⇕</th>
                    <th onclick="sortTable('personnelTable', 2, 'number')">Alter ⇕</th>
                    <th onclick="sortTable('personnelTable', 3, 'number')">Skill ⇕</th>
                    <th onclick="sortTable('personnelTable', 4, 'number')">Zuverlässigkeit ⇕</th>
                    <th onclick="sortTable('personnelTable', 5, 'string')">ADR? ⇕</th>
                    <th onclick="sortTable('personnelTable', 6, 'number')">Strafpunkte ⇕</th>
                    <th onclick="sortTable('personnelTable', 7, 'number')">Gehalt ⇕</th>
                    <th onclick="sortTable('personnelTable', 8, 'string')">Rating ⇕</th>
                    <th>Fahrzeugzuweisung / Status</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($allPersonnel)): ?>
                    <tr><td colspan="11" class="text-center text-muted-italic">Keine Personalprofile im ERP registriert. Nutzen Sie das Import-Panel oben!</td></tr>
                <?php else: ?>
                    <?php foreach ($allPersonnel as $p): ?>
                    <?php 
                    // HOT PICK ANALYTIK (PH-konform)
                    $isHotPick = false;
                    if ($p['is_employed'] === 0) {
                        $avgRatio = $averages[$p['role']] ?? null;
                        if ($avgRatio !== null && $avgRatio > 0) {
                            if ($p['skill'] !== null && $p['skill'] > 0) {
                                $ratio = $p['salary'] / $p['skill'];
                                if ($ratio <= (0.85 * $avgRatio)) $isHotPick = true;
                            } else {
                                if ($p['salary'] <= (0.85 * $avgRatio)) $isHotPick = true;
                            }
                        }
                    }
                    ?>
                    <tr class="filterable-row" 
                        data-employed="<?= $p['is_employed'] ?>" 
                        data-active-applicant="<?= $p['is_active_applicant'] ?>">
                        <td><strong><?= $p['role_label'] ?></strong></td>
                        <td>
                            <strong><?= htmlspecialchars($p['name']) ?></strong><br>
                            <small class="text-gray">Personal-Nr: <?= $p['ingame_id'] ?></small>
                        </td>
                        <td><?= $p['age'] ?></td>
                        <td class="text-orange">
                            <strong><?= $p['skill'] !== null ? $p['skill'] : '-' ?></strong>
                        </td>
                        <td><?= $p['reliability'] ?>%</td>
                        <td>
                            <?php if ($p['role'] === 'fahrer'): ?>
                                <?= $p['adr_permit'] ? '<span class="adr-badge">[ADR]</span>' : 'Nein' ?>
                            <?php else: ?>
                                <span class="text-gray">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['role'] === 'fahrer'): ?>
                                <?= $p['penalty_points'] ?> Pkt.
                            <?php else: ?>
                                <span class="text-gray">-</span>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= number_format($p['salary'], 2, ',', '.') ?> €</strong></td>
                        <td>
                            <?php if ($p['is_employed'] === 1): ?>
                                <span class="text-orange">Eingestellt</span>
                            <?php else: ?>
                                <?php if ($isHotPick): ?>
                                    <span class="adr-badge">🔥 HOT PICK</span>
                                <?php else: ?>
                                    <span class="text-muted-italic">Standard</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['is_employed'] === 1): ?>
                                <?php if ($p['role'] === 'fahrer'): ?>
                                    <?php if ($p['truck_label']): ?>
                                        <span class="text-orange">ID: <?= htmlspecialchars($p['truck_label']) ?></span>
                                        <form method="post" style="display:inline; margin-left: 5px;">
                                            <input type="hidden" name="action" value="unassign_driver">
                                            <input type="hidden" name="driver_id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="btn-primary btn-danger btn-small">Abkoppeln</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" class="action-form">
                                            <input type="hidden" name="action" value="assign_driver">
                                            <input type="hidden" name="driver_id" value="<?= $p['id'] ?>">
                                            <input type="hidden" name="driver_ingame_id" value="<?= $p['ingame_id'] ?>">
                                            <select name="truck_id" class="inline-select" required>
                                                <option value="">-- LKW zuweisen --</option>
                                                <?php foreach ($freeTrucks as $ft): ?>
                                                    <option value="<?= $ft['id'] ?>">ID: <?= htmlspecialchars($ft['ingame_vehicle_id']) ?> (<?= htmlspecialchars($ft['vehicle_type']) ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn-primary btn-small">Binden</button>
                                        </form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-blue">Aktiv im Dienst</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if ($p['is_active_applicant'] === 1): ?>
                                    <span style="color:#2ecc71; font-weight:bold;">🟢 Offenes Gesuch</span>
                                <?php else: ?>
                                    <span class="text-gray">⚪ HR-Archiviert</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display: flex; gap: 5px;">
                                <a href="?edit_id=<?= $p['id'] ?>&role=<?= $p['role'] ?>" class="btn-primary btn-small" style="background-color: #3498db; text-decoration: none; display: inline-block;">Bearbeiten</a>
                                <?php if ($p['is_employed'] === 1): ?>
                                    <form method="post" onsubmit="return confirm('Mitarbeiter wirklich entlassen? Er wechselt direkt ins inaktive HR-Archiv.');">
                                        <input type="hidden" name="action" value="dismiss_personnel">
                                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="role" value="<?= $p['role'] ?>">
                                        <button type="submit" class="btn-primary btn-danger btn-small">Entlassen</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="hire_personnel">
                                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="role" value="<?= $p['role'] ?>">
                                        <button type="submit" class="btn-primary btn-small" style="background-color: #2ecc71;">Einstellen</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Client-Side Filter, Suche, Sortierung und State-Persistenz -->
    <script>
        // -------------------------------------------------------------
        // STATE-MANAGEMENT (LocalStorage-Persistenz)
        // -------------------------------------------------------------
        function saveHRState() {
            const state = {
                search: document.getElementById('personnelSearch').value,
                status: document.getElementById('filterStatus').value
            };
            localStorage.setItem('tb_hr_unified_filters', JSON.stringify(state));
        }

        function restoreHRState() {
            const raw = localStorage.getItem('tb_hr_unified_filters');
            if (!raw) return;
            try {
                const state = JSON.parse(raw);
                document.getElementById('personnelSearch').value = state.search || '';
                document.getElementById('filterStatus').value = state.status || 'employed';
            } catch (e) {
                // Fehler geräuschlos abfangen
            }
        }

        // -------------------------------------------------------------
        // CLIENT-SIDE MULTI-FILTER (Reaktiv)
        // -------------------------------------------------------------
        function applyPersonnelFilter() {
            const filterValue = document.getElementById('filterStatus').value;
            const searchQuery = document.getElementById('personnelSearch').value.toLowerCase();
            const keywords = searchQuery.split(/\s+/).filter(k => k.trim() !== '');

            const rows = document.querySelectorAll('#personnelTable tbody tr');

            rows.forEach(row => {
                if (row.cells.length < 2) return; // Tabellenplatzhalter überspringen
                
                const isEmployed = parseInt(row.getAttribute('data-employed'));
                const isActiveApplicant = parseInt(row.getAttribute('data-active-applicant'));
                const textContent = row.textContent.toLowerCase();

                // 1. Status Weiche auswerten
                let matchStatus = false;
                if (filterValue === 'all') {
                    matchStatus = true;
                } else if (filterValue === 'employed') {
                    matchStatus = (isEmployed === 1);
                } else if (filterValue === 'applicants') {
                    matchStatus = (isEmployed === 0 && isActiveApplicant === 1);
                } else if (filterValue === 'archived') {
                    matchStatus = (isEmployed === 0 && isActiveApplicant === 0);
                }

                // 2. Multisearch Suche auswerten (UND-Verknüpfung)
                let matchSearch = true;
                for (let kw of keywords) {
                    if (!textContent.includes(kw)) {
                        matchSearch = false;
                        break;
                    }
                }

                if (matchStatus && matchSearch) {
                    row.classList.remove('hidden-row');
                } else {
                    row.classList.add('hidden-row');
                }
            });

            saveHRState();
        }

        // Wiederherstellen beim Laden
        window.addEventListener('DOMContentLoaded', () => {
            restoreHRState();
            applyPersonnelFilter();
        });

        // -------------------------------------------------------------
        // SORTIER-ALGORITHMUS (ZENTRAL)
        // -------------------------------------------------------------
        function sortTable(tableId, columnIndex, type) {
            const table = document.getElementById(tableId);
            if (!table) return;
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            const headers = table.querySelectorAll('th');
            const th = headers[columnIndex];
            const currentDir = th.getAttribute('data-sort-dir') === 'asc';
            const dir = !currentDir;
            
            headers.forEach(h => h.removeAttribute('data-sort-dir'));
            th.setAttribute('data-sort-dir', dir ? 'asc' : 'desc');

            rows.sort((a, b) => {
                let valA = a.cells[columnIndex]?.innerText.trim() || '';
                let valB = b.cells[columnIndex]?.innerText.trim() || '';

                if (type === 'number') {
                    valA = parseFloat(valA.replace(/[^0-9,-]+/g, '').replace(',', '.'));
                    valB = parseFloat(valB.replace(/[^0-9,-]+/g, '').replace(',', '.'));
                    valA = isNaN(valA) ? 0 : valA;
                    valB = isNaN(valB) ? 0 : valB;
                } else {
                    valA = valA.toLowerCase();
                    valB = valB.toLowerCase();
                }

                if (valA < valB) return dir ? -1 : 1;
                if (valA > valB) return dir ? 1 : -1;
                return 0;
            });

            rows.forEach(row => tbody.appendChild(row));
        }
    </script>
</body>
</html>