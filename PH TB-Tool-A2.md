Pflichtenheft zur Fehlerbehebung – Abschnitt 1: Die Kernursache (Datenbank-Semantik-Konflikt)
§ 1.1. Systemweiter Rahmen und Risiko-Prävention
•	1.1.1. Die Tonnage-Angaben speisen mindestens 5 gekoppelte Subsysteme (Lager-Import, Börsen-Import, Dispatcher-Board / TopologyEngine, Lade-/Entlade-Prozesse, Fuhrpark-/Fahrer-Zuweisung).
•	1.1.2. Jede Modifikation am Datenbank-Schema oder den Logik-Klassen muss so konzipiert sein, dass alle 5 Subsysteme ohne Seiteneffekte oder Regressionen auf die korrigierten Felder zugreifen.
§ 1.2. Der historische Fehlerzustand (Root Cause)
•	1.2.1. In der Datenbank-Tabelle orders existierte bisher kein separates Feld für $weight_loaded.
•	1.2.2. Aus Bequemlichkeit bei der Erstentwicklung wurde das Feld weight_remaining missbraucht, um bei Teilladungen/Splits zwei logisch völlig gegensätzliche Zustände abzubilden:
o	Zustand A (Unverplante Pool-Fracht): Das reale $weight_remaining (die im Lager oder am Markt noch offene, unverplante Restmenge, z. B. 37 t).
o	Zustand B (Verplante LKW-Fracht): Das eigentliche $weight_loaded (die auf einen konkreten LKW tatsächlich geladene/verplante Tonnage, z. B. 26 t).
§ 1.3. Die unumstößliche Begriffs- und Feld-Definition
Zur Beseitigung des Semantik-Konflikts werden die drei Gewichtsvariablen im gesamten System (Datenbank, Klassen, Views) strikt wie folgt definiert und getrennt:
1.	$weight_total: Das unveränderliche Ingame-Gesamtgewicht des ursprünglichen Auftrags bei Ersterfassung (z. B. 63 t).
2.	$weight_remaining: Die reine, unverplante Restmenge im eigenen Lagerbestand (z. B. 37 t). Bei Börsenaufträgen im Marktpool sowie bei unberührten Lageraufträgen ist $weight_remaining stets identisch mit $weight_total.
3.	$weight_loaded: Die auf einen konkreten LKW tatsächlich geladene/verplante Tonnage (z. B. 26 t).

2.1. Fehlerstelle A – Marktparser & Lagerparser (Surgischer Eingriff)
§ 2.1.1. Grundsatz der minimal-invasiven Code-Chirurgie
•	Anpassungen am Marktparser (OrderParser.php) und am Lagerparser (WarehouseParser.php) müssen mit chirurgischer Präzision erfolgen. Es dürfen ausschließlich die exakt betroffenen Zuweisungszeilen modifiziert werden, um Funktionsverluste oder Regressionen auszuschließen.
§ 2.1.2. Reglementierung des Marktparsers (OrderParser.php)
•	Beim Verarbeiten von Frachtbörsen-Angebotsdaten durch den Marktparser gilt ausnahmslos: $weight_remaining === $weight_total.
•	Der Marktparser darf unter keinen Umständen abweichende Werte in $weight_remaining schreiben oder Abzüge vornehmen.
§ 2.1.3. Reglementierung des Lagerparsers (WarehouseParser.php)
•	Ausschließlich der Lagerparser ist berechtigt, $weight_remaining aus dem einkopierten Lagertext zu extrahieren und im eigenen Lagerbestand bei Abweichungen zu aktualisieren (z. B. bei der Ingame-Zeile 17 / 23 t: $weight_total = 23 t, $weight_remaining = 17 t).
•	Restmengen-Regel: Die durch den Lagerparser ermittelten verbliebenen 17 t ($weight_remaining) stehen im Lagerpool für neue Tourenvorschläge (Autopilot / Radar) zur Verfügung, sofern sie nicht bereits auf anderen LKW verplant sind.
•	Nullmengen-Regel: Extrahiert der Lagerparser für einen Auftrag 0 / X t ($weight_remaining = 0 t), existiert keine unverplante Restmenge mehr im Lager.
•	Schutz-Garantie: Die Datenverarbeitung des Lagerparsers darf niemals das $weight_loaded von bereits auf LKW verplanten Segmenten überschreiben oder manipulieren.

2.2. Fehlerstelle B – Die Anzeige im Dispatcher-Board (dispatcher_board.php)
2.2.1. Grundsatz der Soll-Anzeige im Dispatcher-Board:
Das Dispatcher-Board (dispatcher_board.php) liest zur Anzeige von Frachtgewichten ausschließlich die drei klar getrennten Variablen $weight_loaded, $weight_remaining und $weight_total aus.
2.2.2. Anzeige-Spezifikation für geplante Touren (LKW-Fahrplan):
2.2.2.1. In der Tabelle der geplanten Touren muss für jeden zugewiesenen Auftrag das Dreiergespann $weight_loaded / $weight_remaining / $weight_total t dargestellt werden.
2.2.2.2. An erster Stelle steht zwingend $weight_loaded (die auf diesen LKW tatsächlich verplante Tonnage).
2.2.2.3. Verbot künstlicher Deckelungen: Der Wert $weight_loaded entstammt der mathematisch exakten Verplanungslogik. Eine künstliche Deckelung oder Verfälschung der Anzeige zur Kaschierung von Berechnungsfehlern ist strikt untersagt, damit logische Fehler im System sofort offenliegen.
2.2.2.4. An zweiter Stelle steht $weight_remaining (die im Lager vor diesem spezifischen Planungsschritt noch unverplante, frei verfügbare Restmenge).
2.2.2.5. An dritter Stelle steht $weight_total (das unveränderliche Ingame-Gesamtgewicht des Auftrags).
2.2.3. Anzeige-Spezifikation für die Vorschlagskette (Autopilot / Radar):
2.2.3.1. In den Vorschlagslisten wird ebenfalls das Format $weight_loaded / $weight_remaining / $weight_total t für den Disponenten gerendert.
2.2.3.2. Der Vorschlag zeigt für $weight_loaded exakt die Tonnage an, die das Fahrzeug bei Annahme dieses Schritts verplanen würde.
2.2.3.3. Live-Aktualisierung innerhalb einer Planungs-Session: Bei jedem Einplanen eines Tour-Schritts muss die noch verfügbare Restmenge ($weight_remaining) sowohl im Backend als auch im Frontend sofort und live für nachfolgende Vorschläge angepasst werden.
2.2.3.4. Stufenweise Vorschau-Sequenz: Bei schrittweiser Verplanung desselben Auftrags (z. B. 24 t Gesamtgewicht mit einem 8t-LKW) zeigt der erste verplante Schritt 8 / 24 / 24 t, der zweite verplante Schritt innerhalb der Session 8 / 16 / 24 t und der dritte Schritt 8 / 8 / 24 t.
2.3. Fehlerstelle C – Tonnage-Sperren & Typen-Sicherheits-Weiche (TopologyEngine.php)
2.3.1. Zweck der Tonnage-Sperren:
Die am LKW definierten Tonnage-Sperren (min_weight_t und max_weight_t) dienen dazu, unökonomische Kleinstfrachten zu filtern sowie das ungewollte "Anfressen" von Großaufträgen durch kleine LKW zu verhindern, wenn im Fuhrpark größere LKW für diese Fracht bereitstehen.
2.3.2. Reguläre Tonnage-Prüfung:
Ein Auftrag wird in der TopologyEngine.php primär gegen die Tonnage-Sperren des zu beplanenden LKWs geprüft. Er ist regulär zulässig, wenn sein $weight_remaining größer oder gleich min_weight_t ist und (falls max_weight_t > 0) kleiner oder gleich max_weight_t ist.
2.3.3. Auslöser der Typen-Sicherheits-Weiche:
Verletzt ein Auftrag die Tonnage-Sperre des aktuellen LKWs, durchsucht das System die gesamte Flotte der Spedition nach alternativen Fahrzeugen. Der Auftrag wird für den aktuellen LKW nur dann abgelehnt, wenn im Fuhrpark mindestens ein anderer LKW existiert, der diese Fracht sowohl bezüglich der Fahrzeugtyp-Kompatibilität als auch bezüglich seiner Tonnage-Sperren regelkonform transportieren kann.
2.3.4. Notfall-Freigabe bei verwaisten Frachten:
Existiert im gesamten Fuhrpark kein einziger anderer LKW, der die Fracht aufgrund von Typen-Inkompatibilitäten oder eigenen Sperren übernehmen kann, hebt die Typen-Sicherheits-Weiche die Tonnage-Sperre für den aktuellen LKW auf, um ein dauerhaftes Verbleiben der Fracht im Lager zu verhindern.
2.3.5. Strikte Kapazitäts-Garantie bei Notfall-Freigabe:
Auch wenn die Typen-Sicherheits-Weiche eine Tonnage-Sperre außer Kraft setzt, verplant der LKW niemals mehr Fracht als seine physische Kapazität zulässt. Die verplante Tonnage berechnet sich ausnahmslos als $weight_loaded = min($weight_remaining, capacity_t).


3. Die saubere architektonische Lösung (Soll-Zustand)
3.1. Datenbank-Schema & Daten-Modell:
3.1.1. Erweiterung der Datenbank-Tabelle orders um die neue Spalte weight_loaded (Datentyp INT UNSIGNED, Standardwert NULL).
3.1.2. Das Feld weight_loaded speichert ausschließlich die auf einen konkreten LKW tatsächlich verplante/geladene Tonnage für die jeweilige Etappe.
3.1.3. Das Feld weight_remaining speichert exakt und ausschließlich die unverplante, frei verfügbare Restmenge im eigenen Lagerbestand (is_accepted = 1). Bei Börsenaufträgen im Marktpool sowie bei unberührten Lageraufträgen ist $weight_remaining stets identisch mit $weight_total.
3.2. Import- & Parsing-Verhalten:
3.2.1. Der Marktparser (OrderParser.php) setzt $weight_remaining ausnahmslos gleich $weight_total.
3.2.2. Der Lagerparser (WarehouseParser.php) extrahiert das im einkopierten Text angegebene Restgewicht aus dem Spiel.
3.2.3. Vorab-Subtraktions-Prüfung beim Lager-Import: Das einzutragende $weight_remaining im Lagerbestand berechnet sich aus dem einkopierten Restgewicht abzüglich aller bereits auf LKW verplanten Teilmengen ($weight_loaded).
3.2.4. Der Lagerparser und die Import-Schnittstelle dürfen das Feld $weight_loaded auf verplanten LKW-Zeilen unter keinen Umständen überschreiben oder zurücksetzen.
3.3. Zuweisungs- & Verplanungs-Logik:
3.3.1. Beim Zuweisen eines Auftrags auf einen LKW (load_job.php / TopologyEngine.php) wird $weight_loaded mathematisch exakt als $weight_loaded = min($weight_remaining, capacity_t) berechnet und im zugewiesenen Datensatz hinterlegt.
3.3.2. Bei Auftrags-Splits verbleibt die unverplante Restmenge im Lagerbestand mit dem aktualisierten Wert $weight_remaining = $weight_remaining - $weight_loaded.
3.3.3. Durchgehende Klon-Garantie:
Jeder auf einem LKW verplante Teilabschnitt wird ausnahmslos als eigenständiger Klon mit fortlaufendem Suffix (-1, -2, -3 etc.) in der Datenbank angelegt. Auch der letzte verplante Restabschnitt eines Auftrags erzeugt einen sauberen Klon mit exakt berechnetem Teil-Erlös und $weight_loaded.
3.3.4. Mutter-Auftrags-Lebenszyklus:
Der Mutter-Auftrag (assigned_truck_id = NULL) verbleibt als Anker im Lagerbestand und wird niemals selbst direkt einem LKW zugewiesen. Erreicht sein $weight_remaining den Wert 0 t, blendet er sich aus den aktiven Lagerbeständen aus. Er nimmt bei Stornierungen geladene Tonnagen wieder auf und wird beim Verschwinden aus dem Lager-Import final archiviert (is_archived = 1).
3.3.5. Unterscheidung Komplettladung vs. Teilladung (Split-Garantie):
3.3.5.1. Komplettladung ($weight_remaining <= capacity_t): Passt die Restfracht vollständig auf den LKW, erfolgt die Zuweisung direkt auf dem bestehenden Auftragsdatensatz ohne Erzeugung eines Klons oder Suffixes (-1). Der Datensatz erhält $weight_loaded = $weight_remaining und im Lagerbestand verbleibt $weight_remaining = 0 t.
3.3.5.2. Teilladung / Split ($weight_remaining > capacity_t): Übersteigt die Frachtmenge die LKW-Kapazität, greift zwingend die Klon-Erzeugung. Der abgetrennte Teilabschnitt wird als eigenständiger Klon mit fortlaufendem Suffix (-1, -2 etc.) und berechnetem $weight_loaded auf dem LKW verplant, während der Mutter-Auftrag mit reduziertem $weight_remaining im Lagerbestand verbleibt.
3.4. Board-Anzeige & Live-Session-Synchronisation:
3.4.1. Das Dispatcher-Board (dispatcher_board.php) liest für geplante LKW-Touren strikt die Spalte $weight_loaded als ersten Anzeigewert aus.
3.4.2. Die Darstellung erfolgt ungebündelt und ohne künstliche Deckelung exakt im Format $weight_loaded / $weight_remaining / $weight_total t.
3.4.3. Innerhalb einer Planungs-Session wird $weight_remaining im Backend und in den Vorschlagslisten des Frontends live und stufenweise für nachfolgende Planungsschritte angepasst.
4. Schlachtplan / Roadmap zur schrittweisen Behebung
4.1. Schritt 1 – Datenbank-Schema & Model-Anpassung (Order.php):
4.1.1. Erstellung und Ausführung der Schema-Migration zur Hinzufügung der Spalte weight_loaded (INT UNSIGNED DEFAULT NULL) in der Datenbank-Tabelle orders.
4.1.2. Minimal-invasive Erweiterung der Modell-Klasse Order.php um das Attribut $weight_loaded und die Getter-Methode getWeightLoaded().
4.2. Schritt 2 – Entkopplung des Marktparsers (OrderParser.php):
4.2.1. Chirurgische Anpassung im Marktparser (OrderParser.php), um sicherzustellen, dass $weight_remaining bei allen Börsenangeboten exakt gleich $weight_total gesetzt wird und $weight_loaded standardmäßig NULL bleibt.
4.3. Schritt 3 – Entkopplung & Vorab-Subtraktion im Lagerparser / Importer (market_warehouse.php / WarehouseParser.php):
4.3.1. Anpassung der Import-Logik in market_warehouse.php und WarehouseParser.php, damit vor dem Aktualisieren von $weight_remaining im Lagerbestand die Summe aller bereits verplanten Teilmengen ($weight_loaded) von der einkopierten Restmenge abgezogen wird.
4.3.2. Strikter Schutz verplanter LKW-Zeilen: Der Lager-Import darf $weight_loaded auf zugewiesenen Aufträgen niemals überschreiben.
4.4. Schritt 4 – Zuweisungs- & Verplanungs-Logik (load_job.php & OrderRepository.php):
4.4.1. Anpassung der Lade-Transaktion in load_job.php und OrderRepository.php, um für jeden Verplanungsschritt (einschließlich des letzten Restabschnitts) einen sauberen Klon mit fortlaufendem Suffix, berechnetem Teil-Erlös und $weight_loaded = min($weight_remaining, capacity_t) zu erzeugen.
4.4.2. Anpassung der Split-Logik: Absicherung des unverplanten Restes im Lagerbestand mit $weight_remaining = $weight_remaining - $weight_loaded.
4.5. Schritt 5 – Anpassung der TopologyEngine (TopologyEngine.php):
4.5.1. Aktualisierung der Autopilot- und Radar-Algorithmen in TopologyEngine.php, um bei der Kettenbildung $weight_loaded korrekt zu setzen und $weight_remaining während einer Planungs-Session live und schrittweise zu reduzieren.
4.6. Schritt 6 – Anpassung der Board-Anzeige (dispatcher_board.php):
4.6.1. Aktualisierung der Auslese- und Rendering-Logik in dispatcher_board.php, um in der Fahrplan-Tabelle das Feld $weight_loaded an erster Stelle im Format $weight_loaded / $weight_remaining / $weight_total t ohne künstliche Deckelung auszugeben.

5. Subsystem-Abhängigkeiten & Risiko-Matrix (Tonnage-System)

5.1. Subsysteme, die DIREKT betroffen sein werden (Direkte Code- & DB-Anpassungen):

5.1.1. Datenbank-Schema & ORM-Modell (orders Tabelle, Order.php, OrderRepository.php):
5.1.1.1. Die Tabellen-Struktur von orders wird um die physische Spalte weight_loaded erweitert.
5.1.1.2. Das PHP-Modell Order.php nimmt das Attribut $weight_loaded sowie die Getter-Methode getWeightLoaded() auf.
5.1.1.3. Das OrderRepository.php muss in seinen Methoden save(), assignToTruck(), unassignFromTruck() sowie in allen SELECT-Abfragen die Spalte weight_loaded explizit verwalten und mappen.

5.1.2. Marktparser (classes/OrderParser.php & market_pool.php):
5.1.2.1. Der Marktparser wird so angepasst, dass er beim Erfassen NEUER Börsenangebote starr $weight_remaining = $weight_total und $weight_loaded = NULL setzt.
5.1.2.2. Beim Re-Import von Marktangeboten darf keine Verrechnung mit etwaigen Teilmengen stattfinden.

5.1.3. Lagerparser & Lager-Importer (classes/WarehouseParser.php & market_warehouse.php):
5.1.3.1. Der Lagerparser liest weiterhin die im einkopierten Text angegebenen Rest- und Gesamtmengen ein.
5.1.3.2. Die Import-Schnittstelle market_warehouse.php muss vor dem Überschreiben von $weight_remaining im Lagerpool eine Vorab-Subtraktion durchführen: Das neue $weight_remaining berechnet sich aus dem einkopierten Restgewicht abzüglich aller in der Datenbank bereits auf LKW verplanten Teilmengen ($weight_loaded).
5.1.3.3. Der Lager-Import darf die Spalte $weight_loaded von bestehenden, verplanten LKW-Zeilen unter keinen Umständen überschreiben oder zurücksetzen.

5.1.4. Lade- & Zuweisungs-Transaktions-Controller (load_job.php):
5.1.4.1. Bei der Ausführung einer LKW-Zuweisung berechnet load_job.php die tatsächlich geladene/verplante Tonnage mathematisch als $weight_loaded = min($weight_remaining, capacity_t).
5.1.4.2. Beim Erstellen des verplanten LKW-Datensatzes (oder Klon-Splits) wird $weight_loaded explizit in die neue Tabellenspalte geschrieben.
5.1.4.3. Das verbleibende Restgewicht des Mutter-Auftrags im Lagerbestand wird um exakt diesen Betrag reduziert ($weight_remaining = $weight_remaining - $weight_loaded).

5.1.5. Logistik- & Berechnungs-Engine (classes/TopologyEngine.php):
5.1.5.1. Einführung einer zentralen Helper-Methode (z. B. calculateLoadedWeight()), die das zugelassene Ladevolumen einheitlich als $weight_loaded = min($weight_remaining, capacity_t) ermittelt.
5.1.5.2. Der Autopilot-Algorithmus (calculateAutopilotChains()) greift auf diese Helper-Methode zu, um $weight_loaded für Vorschläge zu bestimmen und in die virtuelle Kette einzutragen.
5.1.5.3. Der Taktische Radar-Scan (getRadarScanForTruck()) greift ebenfalls auf dieselbe Helper-Methode zu, um $weight_loaded konsistent zu berechnen.
5.1.5.4. Die Tonnage-Sperren-Prüfung (isOrderAllowedByWeight()) bewertet das Anforderungsprofil eines LKWs gegen das ungestückelte $weight_remaining des Lagerbestands, ohne das geladene $weight_loaded zu verfälschen.
5.1.5.5. Während einer Planungs-Session wird $weight_remaining für nachfolgende Vorschlagsschritte desselben Auftrags im Arbeitsspeicher live und stufenweise um das jeweilige $weight_loaded reduziert.

5.1.6. Dispositions-Leitstand (dispatcher_board.php):
5.1.6.1. Die Fahrplan-Tabelle der geplanten LKW-Touren greift für die erste Zahlenkomponente der Gewichtsspalte direkt auf die DB-Spalte $weight_loaded zu.
5.1.6.2. Die Anzeige erfolgt ohne künstliche Kaschierung im Format $weight_loaded / $weight_remaining / $weight_total t.
5.1.6.3. Die Entlade-Aktion (unload_job) löscht verplante Klon-Segmente, addiert deren $weight_loaded wieder lückenlos auf das $weight_remaining des Lager-Mutterauftrags auf und setzt bei Hauptaufträgen $weight_loaded auf NULL.
5.1.6.4. Die Strategie-Sidebar (Lagerbestands-Monitor) aggregiert für die Spalte "Bestand (t)" ausnahmslos unzugeordnete Lager-Aufträge (is_accepted = 1 AND assigned_truck_id IS NULL AND weight_remaining > 0) und summiert deren $weight_remaining pro Stadt.

5.2. Subsysteme, die POTENZIELL betroffen sein könnten (Kaskadeneffekte & gekoppelte Logiken):

5.2.1. Frachtbörsen-Übersicht & Rentabilitäts-Ranking (orders_view.php & classes/OrdersViewController.php):
5.2.1.1. Prädiktive Fleet-Max-Bremse: Das Ranking berechnet benötigte Fahrten basierend auf der Gesamttonnage. Es muss verifiziert werden, dass die Fleet-Max-Bremse weiterhin auf $weight_total bzw. $weight_remaining rechnet und nicht durch das Feld $weight_loaded irritiert wird.
5.2.1.2. Frontend-Filter: Die Filtermengen filterMinWeight und filterMaxWeight müssen sich auf das Tonnage-Profil des Auftrags beziehen.

5.2.2. Lager-Übersicht (warehouse_view.php & classes/WarehouseViewController.php):
5.2.2.1. Die Bestands-Tabelle liest akzeptierte Lageraufträge aus. Die Spalte "Gewicht (Rest/Gesamt)" muss weiterhin exakt $weight_remaining / $weight_total t für unzugeordnete Lagerbestände anzeigen.

5.2.3. Personal-Manager & ADR-Sicherheits-Interlock (personnel_manager.php & classes/DriverRepository.php):
5.2.3.1. Wenn ein Fahrer ohne ADR-Schein einem LKW zugewiesen wird, feuert der ADR-Sicherheits-Interlock (runAdrSafetyInterlock).
5.2.3.2. Dieser entkoppelt Gefahrgut-Aufträge vom LKW. Dabei muss sichergestellt sein, dass verplante Tonnagen ($weight_loaded) sauber storniert und als $weight_remaining zurück in das Lager überführt werden.

5.2.4. Fuhrpark-Manager (fleet_manager.php & classes/TruckRepository.php):
5.2.4.1. Beim Verkauf eines LKWs (sell_truck) oder beim manuellen Entkoppeln eines Fahrers werden geplante Touren storniert. Auch hier muss die Überführung von $weight_loaded zurück in das $weight_remaining des Lagers ohne Datenverlust greifen.

5.3. Subsysteme, die trotz geringer Side-Effect-Wahrscheinlichkeit ZWINGEND getestet werden müssen (Qualitätssicherungs-Matrix):

5.3.1. Haupt-Dashboard (index.php):
5.3.1.1. Die Slot-Belegungs-Anzeige (occupiedSlots) summiert belegte Disponenten-Slots. Es muss geprüft werden, dass das Entstehen oder Löschen von Klon-Segmenten mit $weight_loaded keine künstliche Mehrfachzählung von Slots verursacht.
5.3.1.2. Die Kennzahl "Offene Aufträge" (getOpenOrdersCount()) darf ausschließlich unverplante Lageraufträge berücksichtigen.
5.3.1.3. Der "Gesamt-Umsatz" (getTotalRevenue()) muss auf Korrektheit geprüft werden, damit Teilladungs-Splits mit proportionalem Erlös nicht zu Doppelzählungen der Geldbeträge führen.

5.3.2. Automatische Geister-Tour-Bereinigung (market_warehouse.php / PH Anhang A1 § 8):
5.3.2.1. Wenn ein Ingame-Auftrag vollständig abgeschlossen wurde und im Lager-Import verschwindet, greift die Kaskaden-Stornierung bzw. Archivierung.
5.3.2.2. Es muss verifiziert werden, dass abgesetzte Geister-Aufträge ihre zugewiesenen $weight_loaded-Werte korrekt auflösen und LKW-Standorte sauber an das Zielort-Ende verschieben.

5.3.3. Entfernungs-Matrix & Schnell-Import (matrix_admin.php & classes/DistanceService.php):
5.3.3.1. Es muss getestet werden, dass die automatische Fütterung von Distanzen aus Aufträgen (setDistance) unbeeinträchtigt von Gewichtsanpassungen funktioniert.


