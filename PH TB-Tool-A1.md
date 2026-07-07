---

# Pflichtenheft-Anhang: Vorschlags-Radar & Kaskadierende Geister-Tour-Bereinigung
**Version 1.1.0 (Ergänzung zu Kapitel 4 und 6 des Haupt-Pflichtenhefts)**

---

## § 7. Das Vorschlags-Radar (3-Tier Chain Look-Ahead)

### § 7.1. Funktionale Zielsetzung und Abgrenzung
* **7.1.1.** Das Vorschlags-Radar dient der proaktiven Entscheidungshilfe für den Disponenten am physischen oder virtuellen Standort eines Fahrzeugs.
* **7.1.2.** Es trennt die maschinelle Rechenleistung (Suchen von Anschlussketten) von der menschlichen Dispositions-Entscheidung (Abwägen von Optionen am aktuellen Knotenpunkt).
* **7.1.3.** Das Radar simuliert im Arbeitsspeicher (ohne Datenbank-Schreibrechte) mögliche Tourenverläufe ab dem Zielort eines angebotenen Vorschlags, um dem Disponenten die langfristige Marge des jeweiligen Pfads anzuzeigen.

### § 7.2. Berechnungs-Parameter und Restriktionen
* **7.2.1.** Die maximale Vorschautiefe der Simulations-Schleife ist hart auf **5 Schritte** begrenzt (konfigurierbar).
* **7.2.2.** Um Endlosschleifen und mathematische Zirkelbezüge zu verhindern, führt die Simulation eine flüchtige Liste bereits „virtuell verplanter“ Auftrags-IDs (`used_ids`). Ein Auftrag darf innerhalb einer simulierten Kette nur einmal gezählt werden.
* **7.2.3.** Für jeden virtuellen Schritt gelten dieselben Kompatibilitätsregeln bezüglich Fahrzeugtyp (PH 3.3), Kapazitätsauslastung und ADR-Berechtigung des zugewiesenen Fahrers (PH 3.3.1).

### § 7.3. Die 3 Such-Stufen (Tiers) der Simulation
* **7.3.1. Stufe 1 (Direkt-Anschluss, 0-km-Marge):**
  * Das System sucht ausgehend vom aktuellen virtuellen Standort nach unverplanten Aufträgen, deren Startort **exakt** dem virtuellen Standort entspricht (0 km Leerfahrt).
  * Werden bis zum Erreichen der Tiefe von 5 Schritten ausschließlich Direkt-Anschlüsse gefunden, wird die Kette als **Stufe 1** gewertet.
  * *Indikator:* `[Tiefe]+ Aufträge` (z. B. `3+ Aufträge`) in **Erfolgsgrün** (#2ecc71).
* **7.3.2. Stufe 2 (Nachbarschafts-Anschluss, 3SR-Überbrückung):**
  * Findet das System an einem simulierten Punkt keinen Direkt-Anschluss (0 km), greift die 3-Städte-Regel (Nachbarschafts-Scan). Es sucht nach Anschlüssen in den 2 nächsten Nachbarstädten.
  * Gelingt das Überbrücken der lokalen Sackgasse über eine Nachbarstadt, wird die Kette dort (wieder bevorzugt nach Stufe 1) weitergeführt.
  * Die Kette wird als **Stufe 2** deklariert.
  * *Indikator:* `[Tiefe]+ Aufträge (inkl. 3SR)` (z. B. `5+ Aufträge (inkl. 3SR)`) in **Warnorange** (#f39c12).
* **7.3.3. Stufe 3 (Globaler Fallback-Transfer):**
  * Findet die Simulation an einem Punkt weder über Stufe 1 noch über Stufe 2 einen Anschluss, bricht die Kette ab, da ein globaler Scan (Rettungs-Transfer nach PH 6.5) nötig wäre.
  * Dies wird als **Stufe 3** gewertet.
  * *Indikator:* `Achtung: Transferfahrt nötig` in **Warnrot** (#e74c3c).

### § 7.4. UI-Integration im Dispatcher-Board
* **7.4.1.** Die Tabelle der Vorschlagskette in `dispatcher_board.php` erhält eine neue, dedizierte Spalte namens **Ketten-Radar**.
* **7.4.2.** Diese Spalte zeigt ausschließlich die in § 7.3 definierten, farbcodierten Text-Indikatoren an, um die Informationsdichte hoch und übersichtlich zu halten.

---

## § 8. Kaskadierendes Storno voreilig beendeter Lageraufträge (Ghost-Tour-Bereinigung)

### § 8.1. Funktionale Zielsetzung
* **8.1.1.** Das System muss verhindern, dass unvollendete Teilstücke (Klone) von Lageraufträgen, die im Spiel bereits vollständig erledigt wurden, als verwaiste „Geisteraufträge“ auf den LKWs verbleiben und deren Planbarkeit blockieren.
* **8.1.2.** Die Bereinigung und Erkennung beendeter Lageraufträge erfolgt ausschließlich im Moment des TSV-Lagerimports (`market_warehouse.php`).

### § 8.2. Detektion und Statusübergang
* **8.2.1.** Das System vergleicht alle in der Datenbank aktiven Lageraufträge (`is_accepted = 1 AND is_archived = 0`) mit der aktuell einkopierten TSV-Liste.
* **8.2.2.** Erscheint eine IDN nicht mehr im aktuellen Import, wird der Datensatz permanent auf `is_archived = 1` und `assigned_truck_id = NULL` gesetzt.

### § 8.3. Die zwei Kaskaden-Szenarien bei Geister-Löschung
* **8.3.1. Szenario 1: Der Geisterauftrag ist die ERSTE/NÄCHSTE geplante Fahrt des LKW:**
  * Da der LKW diese Fahrt ingame nie angetreten hat, steht er physisch weiterhin an seinem realen Standort vor diesem Job.
  * *Ablauf:* Das System führt eine **vollständige Stornierung (Entkoppelung)** des gesamten Fahrplans dieses LKW durch (`SET assigned_truck_id = NULL` für alle anstehenden Touren des Fahrzeugs).
  * *Ergebnis:* Der Fahrplan ist leer. Der LKW verbleibt physisch an seinem korrekten Ausgangsort und ist bereit für neue Dispositionen.
* **8.3.2. Szenario 2: Der Geisterauftrag ist ein Folge-Auftrag (2., 3. Schritt etc.):**
  * Die vor dem Geisterauftrag liegenden Fahrten wurden im Spiel real durchgeführt, der LKW kommt also am Startort des Geisterauftrags an. Ab dort ist der Weg jedoch versperrt.
  * *Ablauf:* Das System zündet die Kaskaden-Stornierung **ab dem Geisterauftrag** (dieser Job sowie alle zeitlich danach geplanten Jobs dieses LKW werden entkoppelt).
  * *Ergebnis:* Die davor liegenden, real fahrbaren Etappen bleiben aktiv verplant. Das virtuelle Tourende des LKW wird automatisch auf den Zielort des letzten verbliebenen, fahrbaren Jobs zurückgesetzt.

---

## § 9. Das Hybride Dispositions-Modell (Autopilot vs. Taktisches Radar)

### § 9.1. Funktionale Definition des Weichen-Systems
* **9.1.1.** Das System stellt dem Disponenten auf dem Dispatcher Board zwei grundverschiedene, komplementäre Planungs-Methoden zur Verfügung.
* **9.1.2.** Die Umschaltung erfolgt benutzergesteuert über ein interaktives Steuerungselement im UI, welches den Zustand über Seiten-Aktualisierungen hinweg persistiert.

### § 9.2. Planungsmodus „Autopilot“ (legacy suggestion-Modul)
* **9.2.1. Zielsetzung:** Massen- und Vorabdisposition bei ausreichenden Kapazitäts- und Disponenten-Slots.
* **9.2.2. Verhalten:** Das System berechnet im Hintergrund über die globale `TopologyEngine` lineare, zusammenhängende Fahrten-Ketten von bis zu 6 Stopps im Voraus pro Fahrzeug.
* **9.2.3. Datenquelle:** Das Tableau liest die im Speicher berechneten Ketten aus der Variable `$suggestedChains` aus.

### § 9.3. Planungsmodus „Taktisches Radar“ (radar-Modul)
* **9.3.1. Zielsetzung:** Taktische Feinplanung in Engpass-Situationen (wenig freie Slots, Sackgassen-Standorte, komplexe Teilladungsszenarien).
* **9.3.2. Verhalten:** Das System ermittelt für das fokussierte Fahrzeug alle physikalisch und administrativ zulässigen Sofort-Optionen im 3-Städte-Radius ab dem aktuellen virtuellen Tourende.
* **9.3.3. Datenquelle:** Das Tableau führt über die Methode `getRadarScanForTruck()` einen gezielten Scan aus, welcher jede Option mit dem vorausschauenden Tiefen-Indikator (`simulateRadarChain()`) anreichert.
* **9.3.4. Die Auswahl-Garantie:**
  * Das System listet im Radar-Modus **ausnahmslos alle** im 3-Städte-Radius kompatiblen, unverplanten Aufträge als eigenständige Optionen auf (bis zu einem vernünftigen UI-Limit von 15 Einträgen).
  * Ein vorzeitiges Abbrechen der Suche nach dem ersten gefundenen (z. B. 0-km) Treffer ist technisch unzulässig. Der Disponent muss stets die vollständige Auswahl aller regionalen Alternativen sichten können.

### § 9.4. Technische Realisierung der Weiche und Persistenz
* **9.4.1. Zustands-Persistenz:** Der gewählte Planungsmodus wird persistent in der Tabelle `config` unter dem Schlüssel `planning_mode` gespeichert (Werte: `'autopilot'` oder `'radar'`). Dies sichert die Konsistenz über alle Disponenten-Arbeitsplätze.
* **9.4.2. Umschalt-Trigger:** Im Header der Vorschlagssektion von `dispatcher_board.php` wird ein umschaltbares Bedienelement (z. B. eine Radio-Button-Gruppe oder ein interaktives Toggle-Element) gerendert.
* **9.4.3. Formular-Anbindung:** Die Betätigung des Toggles sendet einen asynchronen POST-Request an einen Controller, welcher den Wert in der Tabelle `config` aktualisiert und die Ansicht ohne Verlust des LKW-Fokus neu lädt.

### § 9.5. Strikte Namenskonvention für neuen Source-Code
* **9.5.1.** Sämtliche neu zu erstellenden Datenbank-Abfragen, Klassen-Methoden und Variablen-Bezeichnungen, die den Modus „Taktisches Radar“ betreffen, müssen zwingend die Begriffe `radar` oder `radarScan` enthalten. 
* **9.5.2.** Die Verwendung des Begriffs `suggestion` innerhalb von neuen Radar-Modulen ist zur Vermeidung von kognitiven Konflikten im Code-Review strikt verboten. Der bestehende, funktionierende Code des Autopilot-Moduls bleibt davon unberührt.