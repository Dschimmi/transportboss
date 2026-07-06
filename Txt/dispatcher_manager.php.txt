<?php
declare(strict_types=1);

require_once 'db_connect.php';

/**
 * Controller-Klasse zur Kapselung aller Datenbank- und Berechnungsoperationen
 * für die Disponentenverwaltung (Tagesplanungs-Schnittstelle).
 */
class DispatcherManagerController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Verarbeitet eingehende Requests (POST/GET) und bereitet das Daten-Array für die View vor.
     */
    public function handleRequest(array $post, array $get): array
    {
        $message = '';
        $messageClass = '';

        // POST-Aktionen (Datenmanipulationen) verarbeiten
        if (isset($post['action'])) {
            try {
                switch ($post['action']) {
                    case 'add_dispatcher':
                        $this->addDispatcher($post);
                        $message = "Disponent erfolgreich manuell angelegt und eingestellt.";
                        $messageClass = "status-success";
                        break;

                    case 'update_dispatcher':
                        $this->updateDispatcher($post);
                        $message = "Änderungen erfolgreich gespeichert.";
                        $messageClass = "status-success";
                        break;

                    case 'hire_dispatcher':
                        $this->setEmployedStatus((int)$post['dispatcher_id'], 1);
                        $message = "Bewerber erfolgreich eingestellt.";
                        $messageClass = "status-success";
                        break;

                    case 'dismiss_dispatcher':
                        $this->setEmployedStatus((int)$post['dispatcher_id'], 0);
                        $message = "Disponent entlassen und zurück in den Bewerberpool verschoben.";
                        $messageClass = "status-success";
                        break;

                    case 'delete_dispatcher':
                        $this->deleteDispatcher((int)$post['dispatcher_id']);
                        $message = "Bewerbung erfolgreich gelöscht.";
                        $messageClass = "status-success";
                        break;
                }
            } catch (Exception $e) {
                $message = "Fehler bei der Verarbeitung: " . $e->getMessage();
                $messageClass = "status-error";
            }
        }

        // Dynamische Slot-Berechnung durchführen und synchronisieren
        $computedSlots = $this->calculateAndSyncSlots();

        // Daten für die Tabellen und Formulare laden
        $employed = $this->getDispatchersByStatus(1);
        $applicants = $this->getDispatchersByStatus(0);

        $editItem = null;
        if (isset($get['edit_id'])) {
            $editItem = $this->getDispatcherById((int)$get['edit_id']);
        }

        return [
            'message' => $message,
            'messageClass' => $messageClass,
            'employed' => $employed,
            'applicants' => $applicants,
            'editItem' => $editItem,
            'computedSlots' => $computedSlots
        ];
    }

    private function addDispatcher(array $data): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO dispatchers (ingame_dispatcher_id, first_name, last_name, age, skill_val, reliability_val, salary, is_employed)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $data['ingame_dispatcher_id'],
            $data['first_name'],
            $data['last_name'],
            (int)$data['age'],
            (int)$data['skill_val'],
            (int)$data['reliability_val'],
            (float)$data['salary']
        ]);
    }

    private function updateDispatcher(array $data): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE dispatchers 
            SET ingame_dispatcher_id = ?, first_name = ?, last_name = ?, age = ?, skill_val = ?, reliability_val = ?, salary = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $data['ingame_dispatcher_id'],
            $data['first_name'],
            $data['last_name'],
            (int)$data['age'],
            (int)$data['skill_val'],
            (int)$data['reliability_val'],
            (float)$data['salary'],
            (int)$data['id']
        ]);
    }

    private function setEmployedStatus(int $id, int $status): void
    {
        $stmt = $this->pdo->prepare("UPDATE dispatchers SET is_employed = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
    }

    private function deleteDispatcher(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM dispatchers WHERE id = ?");
        $stmt->execute([$id]);
    }

    /**
     * Berechnet die Dispo-Slots: 4 (Basis) + floor(Verwaltungsskill / 10) aller Angestellten.
     * Synchronisiert den berechneten Wert mit der Tabelle `config`.
     */
    private function calculateAndSyncSlots(): int
    {
        $stmt = $this->pdo->query("SELECT skill_val FROM dispatchers WHERE is_employed = 1");
        $employed = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $slots = 4; // Grundwert ohne Disponenten
        foreach ($employed as $disp) {
            $slots += (int)floor((int)$disp['skill_val'] / 10);
        }

        // Persistent in der Config-Tabelle aktualisieren
        $stmtSync = $this->pdo->prepare("
            INSERT INTO config (cfg_key, cfg_value) 
            VALUES ('max_dispo_slots', :val) 
            ON DUPLICATE KEY UPDATE cfg_value = :val
        ");
        $stmtSync->execute(['val' => (string)$slots]);

        return $slots;
    }

    private function getDispatchersByStatus(int $status): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM dispatchers WHERE is_employed = ? ORDER BY last_name ASC");
        $stmt->execute([$status]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getDispatcherById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM dispatchers WHERE id = ?");
        $stmt->execute([$id]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ? $res : null;
    }
}

// Controller instanziieren und verarbeiten (Trennungsprinzip)
$controller = new DispatcherManagerController($pdo);
$viewData = $controller->handleRequest($_POST, $_GET);
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Disponenten Manager - TransportBoss</title>
    <link rel="stylesheet" href="main.css">
    <style>
        .kpi-container { display: flex; gap: 20px; margin-bottom: 25px; }
        .kpi-box { background: #252525; border: 1px solid #444; border-radius: 5px; padding: 15px; flex: 1; text-align: center; }
        .kpi-box h2 { margin: 5px 0; color: #f39c12; }
    </style>
</head>
<body>
    <?php require_once 'nav.php'; ?>
    <div class="fluid-container">
        <h1 class="accent-text">Disponenten Manager</h1>
        
        <?php if ($viewData['message']): ?>
            <div class="feedback-msg <?= $viewData['messageClass'] ?>"><?= htmlspecialchars($viewData['message']) ?></div>
        <?php endif; ?>

        <!-- KPI Slot-Übersicht -->
        <div class="kpi-container">
            <div class="kpi-box">
                <span>Angestellte Disponenten</span>
                <h2><?= count($viewData['employed']) ?></h2>
            </div>
            <div class="kpi-box">
                <span>Verfügbare Slots gesamt (Tagesplanung)</span>
                <h2 style="color: #2ecc71;"><?= $viewData['computedSlots'] ?></h2>
            </div>
        </div>

        <!-- Erfassungs- & Bearbeitungsformular -->
        <div class="form-box">
            <h3 class="accent-text"><?= $viewData['editItem'] ? 'Mitarbeiterdaten bearbeiten' : 'Neuen Disponenten manuell erfassen' ?></h3>
            <form method="post" action="dispatcher_manager.php">
                <input type="hidden" name="action" value="<?= $viewData['editItem'] ? 'update_dispatcher' : 'add_dispatcher' ?>">
                <?php if ($viewData['editItem']): ?>
                    <input type="hidden" name="id" value="<?= $viewData['editItem']['id'] ?>">
                <?php endif; ?>
                
                <div class="form-grid">
                    <div>
                        <div class="input-group">
                            <label>Ingame-ID (Personalnummer)</label>
                            <input type="text" name="ingame_dispatcher_id" value="<?= htmlspecialchars($viewData['editItem']['ingame_dispatcher_id'] ?? '') ?>" required>
                        </div>
                        <div class="input-group">
                            <label>Vorname</label>
                            <input type="text" name="first_name" value="<?= htmlspecialchars($viewData['editItem']['first_name'] ?? '') ?>" required>
                        </div>
                        <div class="input-group">
                            <label>Nachname</label>
                            <input type="text" name="last_name" value="<?= htmlspecialchars($viewData['editItem']['last_name'] ?? '') ?>" required>
                        </div>
                        <div class="input-group">
                            <label>Alter</label>
                            <input type="number" name="age" value="<?= htmlspecialchars((string)($viewData['editItem']['age'] ?? '')) ?>" required>
                        </div>
                    </div>
                    <div>
                        <div class="input-group">
                            <label>Verwaltungsskill (Skillwert)</label>
                            <input type="number" name="skill_val" value="<?= htmlspecialchars((string)($viewData['editItem']['skill_val'] ?? '')) ?>" required>
                        </div>
                        <div class="input-group">
                            <label>Zuverlässigkeit (0-100%)</label>
                            <input type="number" name="reliability_val" value="<?= htmlspecialchars((string)($viewData['editItem']['reliability_val'] ?? '')) ?>" required>
                        </div>
                        <div class="input-group">
                            <label>Gehaltsvereinbarung (€ / Monat)</label>
                            <input type="number" name="salary" step="0.01" value="<?= htmlspecialchars((string)($viewData['editItem']['salary'] ?? '')) ?>" required>
                        </div>
                        
                        <div style="margin-top: 25px;">
                            <button type="submit" class="btn-primary w-100"><?= $viewData['editItem'] ? 'Änderungen speichern' : 'Mitarbeiter einstellen' ?></button>
                            <?php if ($viewData['editItem']): ?>
                                <a href="dispatcher_manager.php" class="btn-primary" style="background-color:#7f8c8d; display:block; text-align:center; text-decoration:none; margin-top:5px; padding: 10px;">Abbrechen</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <hr class="section-divider">

        <!-- TABELLE: Angestellte Disponenten -->
        <h2 class="accent-text">Angestelltes Dispositions-Personal</h2>
        <table class="data-table">
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
                <?php if (empty($viewData['employed'])): ?>
                    <tr><td colspan="8" class="text-center text-muted-italic">Keine Disponenten angestellt (4 Basis-Slots aktiv)</td></tr>
                <?php else: ?>
                    <?php foreach ($viewData['employed'] as $disp): ?>
                    <?php $bonus = (int)floor((int)$disp['skill_val'] / 10); ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$disp['ingame_dispatcher_id']) ?></td>
                        <td><strong><?= htmlspecialchars($disp['first_name'] . ' ' . $disp['last_name']) ?></strong></td>
                        <td><?= $disp['age'] ?></td>
                        <td class="text-orange"><strong><?= $disp['skill_val'] ?></strong></td>
                        <td style="color:#2ecc71;"><strong>+<?= $bonus ?> Slots</strong></td>
                        <td><?= $disp['reliability_val'] ?>%</td>
                        <td><?= number_format((float)$disp['salary'], 2, ',', '.') ?> €</td>
                        <td>
                            <div style="display:flex; gap:5px;">
                                <a href="?edit_id=<?= $disp['id'] ?>" class="btn-primary btn-small" style="background-color:#3498db; text-decoration:none;">Bearbeiten</a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Möchten Sie diesen Mitarbeiter wirklich kündigen?');">
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

        <!-- Bereich Bewerbungen mit Link zum Importieren -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 45px; margin-bottom: 15px;">
            <h2 class="accent-text" style="color: #3498db; margin: 0;">Eingegangene Bewerbungen (Stellenmarkt)</h2>
            <a href="personnel.php" class="btn-primary" style="text-decoration: none; background-color: #3498db; padding: 6px 12px; font-size: 0.9em; border-radius: 3px;">+ Neue Bewerber importieren</a>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Ingame ID</th>
                    <th>Name</th>
                    <th>Alter</th>
                    <th>Verwaltungsskill</th>
                    <th>Potenzieller Slot-Bonus</th>
                    <th>Zuverlässigkeit</th>
                    <th>Gehaltswunsch</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($viewData['applicants'])): ?>
                    <tr><td colspan="8" class="text-center text-muted-italic">Aktuell keine Bewerbungen vorhanden. Bitte importieren Sie neue Stellenmarkt-Daten.</td></tr>
                <?php else: ?>
                    <?php foreach ($viewData['applicants'] as $app): ?>
                    <?php $bonus = (int)floor((int)$app['skill_val'] / 10); ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$app['ingame_dispatcher_id']) ?></td>
                        <td><?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?></td>
                        <td><?= $app['age'] ?></td>
                        <td class="text-orange"><?= $app['skill_val'] ?></td>
                        <td>+<?= $bonus ?> Slots</td>
                        <td><?= $app['reliability_val'] ?>%</td>
                        <td><?= number_format((float)$app['salary'], 2, ',', '.') ?> €</td>
                        <td>
                            <div style="display:flex; gap:5px;">
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="hire_dispatcher">
                                    <input type="hidden" name="dispatcher_id" value="<?= $app['id'] ?>">
                                    <button type="submit" class="btn-primary btn-small" style="background-color:#2ecc71;">Einstellen</button>
                                </form>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Bewerbung dauerhaft löschen?');">
                                    <input type="hidden" name="action" value="delete_dispatcher">
                                    <input type="hidden" name="dispatcher_id" value="<?= $app['id'] ?>">
                                    <button type="submit" class="btn-primary btn-danger btn-small">Löschen</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>