1. System-Architektur & Umgebung

1.1. Plattform: Lokaler Webserver (Apache 2.4+), PHP 8.2+.
1.1.1. Zielsetzung der Systemumgebung
1.1.1.1. Bereitstellung einer performanten Infrastruktur zur Verarbeitung komplexer logistischer Kettenberechnungen.
1.1.1.2. Nutzung moderner Sprachfeatures von PHP 8.2+ zur Gewährleistung von Typsicherheit und Wartbarkeit.
1.1.1.3. Lokale Datenhoheit und Umgehung von Cross-Origin-Sicherheitsbeschränkungen moderner Browser.
1.1.2. Infrastruktur-Konfiguration
1.1.2.1. Server-Software: Einsatz des Apache HTTP Servers Version 2.4 oder höher (Bestandteil von XAMPP/WAMP).
1.1.2.2. Verzeichnis-Struktur: Das Root-Verzeichnis ist auf C:\xampp\htdocs\sitebase\transportboss\ festzulegen.
1.1.2.3. PHP-Runtime: Mindestversion 8.2.0 zur Nutzung von Readonly-Klassen und erweiterten Type-Hints.
1.1.2.4. PHP-Konfiguration (php.ini): Setzen von error_reporting auf E_ALL zur vollständigen Fehlerkontrolle.
1.1.2.5. PHP-Konfiguration (php.ini): Aktivierung von display_errors für unmittelbares Entwickler-Feedback.
1.1.2.6. PHP-Konfiguration (php.ini): Erhöhung der max_execution_time auf 60 Sekunden für rechenintensive Touren-Suchen.
1.1.3. Programmier-Paradigma und Zugriffsschicht
1.1.3.1. Typsicherheit: Jede PHP-Datei muss mit der Deklaration declare(strict_types=1); eingeleitet werden.
1.1.3.2. Datenbank-Abstraktion: Der Zugriff erfolgt ausschließlich über die PDO-Schnittstelle (PHP Data Objects).
1.1.3.3. Sicherheit: Nutzung von Prepared Statements zur Verhinderung von SQL-Injection bei allen Benutzereingaben.
1.1.3.4. Zentralisierung: Die Instanziierung des PDO-Objekts erfolgt einmalig in der Datei db_connect.php.
1.1.4. Interaktionsmodell
1.1.4.1. Server-Side Rendering (SSR): Hauptkomponenten der UI werden synchron durch PHP generiert.
1.1.4.2. Asynchronität: Smart-Fill-Funktionen und Echtzeit-Validierungen werden über AJAX/Fetch-API gegen PHP-Endpunkte realisiert.
1.1.4.3. Datenaustausch: AJAX-Antworten werden standardmäßig im JSON-Format kodiert.
1.1.5. Berechtigungen und Zugriffsschutz
1.1.5.1. Dateisystem: Der Apache-Dienst benötigt Schreibrechte für den Projektordner zur Erstellung von Fehler-Logs.
1.1.5.2. Netzwerk-Bindung: Der Zugriff wird im Auslieferungszustand auf den lokalen Host (127.0.0.1) beschränkt.

1.2. Datenbank: MariaDB / MySQL 8.0.
1.2.1. Zielsetzung und Technologieauswahl
1.2.1.1. Bereitstellung einer permanenten, relationalen Datenhaltung mittels MariaDB (10.4+) oder MySQL (8.0+).
1.2.1.2. Einsatz der Speicher-Engine InnoDB zur Gewährleistung von Transaktionssicherheit und referenzieller Integrität.
1.2.2. Konfiguration und Standards
1.2.2.1. Zeichencodierung: Einheitliche Verwendung von utf8mb4 zur Unterstützung aller Sonderzeichen.
1.2.2.2. Kollation: utf8mb4_unicode_ci für präzise Sortierergebnisse im Dispatcher-Tableau.
1.2.2.3. Namenskonvention: Alle Tabellen- und Spaltenbezeichnungen folgen dem snake_case-Standard in Kleinbuchstaben.
1.2.3. Identifikations-Logik und Primärschlüssel
1.2.3.1. Technischer Primärschlüssel: Jede Tabelle verfügt ausnahmslos über eine Spalte namens id als ganzzahligen (INT), automatisch hochzählenden (AUTO_INCREMENT) Primärschlüssel.
1.2.3.2. Ingame-Kennungen: Sämtliche Identifikatoren, die direkt aus dem Spiel stammen (Personalnummern, Fahrzeug-IDs, Auftragsnummern), werden ausnahmslos als Zeichenfolgen (VARCHAR(50)) definiert. Dies stellt sicher, dass sowohl rein numerische IDs als auch alphanumerische Kennungen (wie IDN-Präfixe) fehlerfrei gespeichert werden können.
1.2.3.3. Eindeutigkeit: Sofern Ingame-IDs vorhanden sind (z.B. bei Lager-Aufträgen), werden diese über einen UNIQUE-Constraint abgesichert, um Dubletten auf Datenbankebene zu verhindern.
1.2.3.4. Marktdaten-Handling: Da Markt-Aufträge keine Ingame-ID besitzen, bleibt das Feld ingame_id in diesen Fällen NULL. Die interne Referenzierung erfolgt ausschließlich über den technischen Primärschlüssel id.
1.2.4. Datenintegrität und Relationen
1.2.4.1. Fremdschlüssel-Definition: Relationen zwischen Tabellen (z.B. Fahrer zu LKW) werden über die technische id des Ziels definiert.
1.2.4.2. Lösch-Verhalten: Strikte Definition von ON DELETE CASCADE für abhängige Bewegungsdaten (z.B. Entfernungen bei Löschung einer Stadt).
1.2.4.3. Konsistenz: Setzen von ON DELETE SET NULL für Standort-Referenzen, damit ein LKW bei Löschung einer Stadt nicht mitgelöscht wird, sondern den Status "Standort unbekannt" erhält.
1.2.5. Performance-Optimierung
1.2.5.1. Indizierung: Manuelle Erstellung von Indizes für alle Fremdschlüssel-Spalten zur Beschleunigung von JOIN-Operationen.
1.2.5.2. Matrix-Index: Ein Verbund-Index über city_a_id und city_b_id in der Tabelle distances ist zwingend für die Performance der Topology-Engine.

1.3. Design-Prämisse: "One Job, one Tool" und Coding-Standards
1.3.1. Programmier-Standard und Architektur (PHP OOP)
1.3.1.1. Strikte Objektorientierung: Sämtliche Logik (Parser, Datenbank-Operationen, Logistik-Algorithmen) ist zwingend in Klassen zu kapseln. Prozeduraler Code in den Modul-Dateien ist untersagt.
1.3.1.2. Typsicherheit: Methoden müssen konsequent Type-Hints für Parameter und Rückgabewerte nutzen (z.B. public function parse(string $data): int).
1.3.1.3. Dokumentations-Pflicht: Jede Klasse und jede Methode muss über einen PHPDoc-Block verfügen, der Zweck, Parameter und Ausnahmen (Exceptions) beschreibt.
1.3.1.4. Code-Wiederverwendbarkeit: Gemeinsame Logik (z.B. die Geld-Konvertierung aus Punkt 3.1) wird in einer Basis-Klasse oder einem Trait definiert.
1.3.2. Frontend-Standards und Design-Trennung
1.3.2.1. Verbot von Inline-CSS: Die Verwendung des style-Attributs innerhalb von HTML-Tags ist strikt untersagt.
1.3.2.2. Zentrales Styling: Sämtliche Design-Vorgaben werden in einer externen main.css definiert.
1.3.2.3. Trennung von Logik und Anzeige: PHP-Dateien (Tools) dienen lediglich der Instanziierung der Logik-Klassen und der Ausgabe des HTML-Gerüsts. Berechnungen finden nicht im HTML-Teil statt.
1.3.3. Modul-Spezifikation ("One Job, one Tool")
1.3.3.1. Funktionale Autarkie: Jede PHP-Datei (z.B. fleet.php, market.php) ist für genau eine Entität verantwortlich und beherbergt den dafür spezialisierten Klassen-Aufruf.
1.3.3.2. Spezialisierte Parser-Klassen: Für jeden Datentyp (LKW, Fahrer, Auftrag, Gebrauchtwagen) ist eine eigene Parser-Klasse zu erstellen, die auf die spezifischen Merkmale des Quelltextes optimiert ist.
1.3.4. UI-Komponenten und Eingabe-Design
1.3.4.1. Textarea-Konfiguration: Das Eingabefeld für den Datenimport ist auf eine moderate Höhe (ca. 8-10 Zeilen) zu begrenzen. Es muss ausreichend Platz bieten, um einen vollständigen Datensatz (z.B. einen LKW-Block oder 2-3 Aufträge) zur Sichtkontrolle lesbar darzustellen.
1.3.4.2. Font-Vorgabe: Innerhalb der Textarea ist eine Monospace-Schriftart (z.B. Consolas oder Courier) zu verwenden, um die Tabellarisierung der einkopierten Originaldaten zu erhalten.
1.3.5. Fehlerbehandlung und Validierung
1.3.5.1. Exception Handling: Fehler beim Parsen oder Datenbankzugriff müssen über try-catch-Blöcke abgefangen werden.
1.3.5.2. Benutzer-Feedback: Im Fehlerfall ist eine präzise Fehlermeldung im UI auszugeben, ohne das Layout der Seite zu zerstören (keine unkontrollierten PHP-Fehlermeldungen im Frontend).

1.4. UI-Design: Dark-Mode-Interface, Lesbarkeit und Performance
1.4.1. Farbpalette und Thematisierung (Dark Mode)
1.4.1.1. Basis-Hintergrund: Verwendung von #121212 für den globalen body-Hintergrund zur Minimierung der Augenbelastung.
1.4.1.2. Oberflächen-Farbe: Container und Karten werden in #1e1e1e (Ebene 1) und #252525 (Ebene 2) definiert, um eine visuelle Tiefe zu erzeugen.
1.4.1.3. Primäre Akzentfarbe: Verwendung von #f39c12 (TransportBoss-Orange) für Überschriften, Primär-Buttons und wichtige Status-Indikatoren.
1.4.1.4. Semantische Farben: Erfolg/Lager-Status in #2ecc71, Warnungen/Leerfahrten in #e74c3c, Information/Börsen-Status in #3498db.
1.4.2. Layout-Architektur und Skalierbarkeit
1.4.2.1. Flexibles Grid-System: Einsatz von CSS Grid (grid-template-columns: repeat(auto-fill, minmax(350px, 1fr))) für das Fahrzeug-Tableau (Punkt 4.2), um bis zu 60 LKW-Karten ohne manuellen Umbruch darzustellen.
1.4.2.2. Viewport-Optimierung: Das Hauptlayout nutzt ein feststehendes Sidebar-System (Links) und einen scrollbaren Main-Content-Bereich (Rechts), um die Orientierung bei großen Datenmengen zu erhalten.
1.4.2.3. Responsive Einheiten: Verwendung von rem und em für Abstände und Schriftgrößen, um die Skalierbarkeit des Interfaces bei verschiedenen Bildschirmauflösungen zu gewährleisten.
1.4.3. Komponentendesign der Fahrzeug-Karten
1.4.3.1. Struktur: Jede Karte ist als eigenständiges Modul gekapselt. Sie besteht aus Header (Status), Body (Tour-Tabelle) und Footer (Aktionen).
1.4.3.2. Interaktive Zustände: Visuelle Hervorhebung der "fokussierten" Karte (Punkt 4.2.6) durch eine Randbetonung in Primärfarbe (border-left: 5px solid #f39c12).
1.4.3.3. Schlanke DOM-Struktur: Minimierung von verschachtelten Elementen innerhalb der 60 Karten, um die Render-Performance des Browsers bei Massen-Updates (z.B. nach einem Pool-Import) hochzuhalten.
1.4.4. Typografie und Lesbarkeits-Standards
1.4.4.1. Schriftwahl: Einsatz einer serifenlosen Systemschrift (z.B. 'Segoe UI' oder 'Roboto') für maximale Klarheit in dunklen Umgebungen.
1.4.4.2. Kontrast-Vorgabe: Einhaltung des WCAG AA-Standards (Kontrastverhältnis mindestens 4.5:1). Texte in Grauabstufungen unterhalb von #aaaaaa sind für relevante Informationen untersagt.
1.4.4.3. Formatierung: Wichtige Kennzahlen (Tonnage, Erlös, KM) werden in einer Monospace-Schrift für Tabellenzahlen dargestellt.
1.4.5. Performance-Optimierung im Frontend
1.4.5.1. Effizientes CSS: Zentralisierung aller Stile in einer main.css. Absolutes Verbot von Inline-Styles zur Reduzierung der HTML-Dateigröße.
1.4.5.2. Event-Delegation: Nutzung von JavaScript-Event-Listenern auf übergeordneten Containern, um bei 60 LKW-Karten nicht hunderte Einzel-Listener registrieren zu müssen.
1.4.5.3. Daten-Rendering: PHP generiert die Karten-Struktur initial serverseitig; dynamische Inhalts-Updates innerhalb der Karten (z.B. Preis-Updates) erfolgen über gezielte AJAX-Calls zur Minimierung des Traffic-Volumens.

2. Datenbank-Design (Datenmodell)

2.1. Tabelle cities: Interne ID (Primary Key), Name (String)
2.1.1. Funktionale Definition und Zweck
2.1.1.1. Die Tabelle cities dient als zentrales Stammdatenregister für alle geographischen Standorte innerhalb der TransportBoss-Umgebung.
2.1.1.2. Sie stellt die normalisierte Basis für alle ortsbezogenen Daten (LKW-Standorte, Auftrags-Routen, Entfernungs-Matrix) dar.
2.1.2. SQL-Schema-Spezifikation
2.1.2.1. Spalte id: Ganzzahliger Datentyp (INT), vorzeichenlos (UNSIGNED), automatisch inkrementierend (AUTO_INCREMENT), definiert als Primärschlüssel (PRIMARY KEY).
2.1.2.2. Spalte name: Zeichenfolgen-Datentyp (VARCHAR) mit einer festen Maximallänge von 100 Zeichen.
2.1.2.3. Nullwerte: Die Spalte name darf keine Nullwerte enthalten (NOT NULL).
2.1.3. Integrität und Validierung
2.1.3.1. Eindeutigkeits-Constraint: Auf der Spalte name muss ein UNIQUE-Index liegen, um die redundante Anlage von Städten (z.B. durch unterschiedliche Schreibweisen beim Import) zu verhindern.
2.1.3.2. Referenzielle Integrität: Die id dieser Tabelle dient als Ziel für alle Fremdschlüssel-Beziehungen in den Tabellen distances, trucks und orders.
2.1.4. Performance-Spezifikation
2.1.4.1. Indexierung: Der UNIQUE-Index auf der Spalte name wird primär für Suchoperationen während des Parsing-Vorgangs (Punkt 3.2.1) und für die AJAX-basierte Autovervollständigung (Punkt 1.4.5.3) genutzt.
2.1.4.2. Speicher-Engine: Einsatz von InnoDB zur Gewährleistung der Fremdschlüssel-Prüfungen.
2.1.5. Objektorientierte Implementierung (PHP OOP)
2.1.5.1. Modell-Klasse: Erstellung einer Klasse City innerhalb des Modell-Namespaces.
2.1.5.2. Eigenschaften: Die Klasse verfügt über die geschützten Eigenschaften private int $id und private string $name.
2.1.5.3. Datenzugriffsschicht (Data Access): Implementierung einer statischen Methode getOrCreateByName(string $name, PDO $pdo): int, welche die Existenz eines Namens prüft, ggf. die Neuanlage (Punkt 3.2.2) vornimmt und stets die technische id zurückgibt.

2.2. Tabelle distances: city_a_id, city_b_id, distance_km (Redundanzfreies Modell)
2.2.1. Funktionaler Zweck und Datenmodell
2.2.1.1. Die Tabelle bildet eine symmetrische Adjazenzmatrix ab, die Entfernungen zwischen zwei Städten speichert.
2.2.1.2. Zur Vermeidung von Datenredundanz wird pro Städtepaar (A, B) genau ein Datensatz geführt.
2.2.1.3. Die Speicherregel city_a_id < city_b_id wird primär durch die Anwendungslogik erzwungen.
2.2.2. SQL-Schema-Spezifikation
2.2.2.1. Spalte city_a_id: Datentyp INT UNSIGNED, NOT NULL. Fremdschlüssel auf cities.id.
2.2.2.2. Spalte city_b_id: Datentyp INT UNSIGNED, NOT NULL. Fremdschlüssel auf cities.id.
2.2.2.3. Spalte distance_km: Datentyp INT UNSIGNED, NOT NULL.
2.2.2.4. Primärschlüssel: Definition eines zusammengesetzten Primärschlüssels (Composite PK) über (city_a_id, city_b_id).
2.2.2.5. Constraints: Ein zusätzlicher CHECK (city_a_id < city_b_id) wird als nachgelagerte Sicherungsebene definiert, auch wenn die primäre Validierung in der Anwendung erfolgt.
2.2.3. Performance und Indizierung
2.2.3.1. Index-Effizienz: Da der Primärschlüssel zusammengesetzt ist, nutzt das RDBMS bei Abfragen, die beide IDs enthalten (Punkt 2.2.4.2), den vollständigen Index-Pfad. Dies ermöglicht Suchoperationen in O(log n) Zeitkomplexität.
2.2.3.2. Referenzielle Integrität: Beide Fremdschlüssel-Beziehungen sind mit ON DELETE CASCADE zu konfigurieren, um verwaiste Distanz-Einträge bei Löschung einer Stadt zu verhindern.
2.2.4. Objektorientierte Implementierung (PHP OOP Logic)
2.2.4.1. Normalisierung: Die Klasse DistanceService implementiert eine private Methode private function normalize(int $id1, int $id2): array. Diese gibt ein Array [$min, $max] zurück. Sämtliche Schreib-, Lese- und Löschoperationen müssen diese Methode zwingend intern aufrufen.
2.2.4.2. Identitäts-Prüfung: Die Methode getDistance() prüft als erste Logik-Ebene, ob $id1 === $id2. In diesem Fall wird sofort der Integer 0 zurückgegeben, ohne eine Datenbank-Abfrage auszulösen.
2.2.4.3. Persistenz-Logik: Die Methode setDistance() verwendet das SQL-Konstrukt INSERT INTO ... ON DUPLICATE KEY UPDATE, um bestehende Werte bei Matrix-Updates zu überschreiben und Dubletten-Fehler zu vermeiden.
2.2.5. Validierung und Fehlerbehandlung
2.2.5.1. Bereichsprüfung: Die Anwendung stellt sicher, dass distance_km keine negativen Werte annimmt, bevor der SQL-Befehl abgesetzt wird.
2.2.5.2. Existenzprüfung: Bei Abfragen (SELECT) gibt das System den Standardwert 999 zurück, falls kein Datensatz für die normalisierte ID-Kombination existiert, um den Fallback-Algorithmus (Punkt 6.5) zu triggern.

2.3. Tabelle drivers (Personalverwaltung)
2.3.1. Stammdaten-Struktur und Identifikation
2.3.1.1. Spalte id: Technischer Primärschlüssel, Datentyp INT UNSIGNED, automatisch inkrementierend.
2.3.1.2. Spalte ingame_driver_id: Eindeutige technische Kennung aus dem Spiel-Quelltext, Datentyp VARCHAR(50), definiert als UNIQUE Index. (Formatänderung von INT zu VARCHAR zur Einhaltung der globalen Regel 1.2.3.2).
2.3.1.3. Spalte first_name: Zeichenfolge (VARCHAR 50), NOT NULL.
2.3.1.4. Spalte last_name: Zeichenfolge (VARCHAR 50), NOT NULL.
2.3.1.5. Spalte age: Ganzzahliger Datentyp (TINYINT UNSIGNED), NOT NULL.
2.3.1.6. Spalte is_employed: Datentyp BOOLEAN (TINYINT 1), Standardwert TRUE.
2.3.2. Qualifikations- und Gehaltsdaten
2.3.2.1. Spalte skill_val: Ganzzahliger Datentyp (SMALLINT UNSIGNED), NOT NULL. (Unterstützt Werte > 100).
2.3.2.2. Spalte reliability_val: Ganzzahliger Datentyp (SMALLINT UNSIGNED), NOT NULL. (Unterstützt Werte > 100).
2.3.2.3. Spalte adr_permit: Datentyp BOOLEAN (TINYINT 1), Standardwert FALSE.
2.3.2.4. Spalte penalty_points: Ganzzahliger Datentyp (TINYINT UNSIGNED), Standardwert 0.
2.3.2.5. Spalte salary: Dezimaler Datentyp (DECIMAL(10,2)), NOT NULL.
2.3.3. Fahrzeug-Relation und referenzielle Integrität
2.3.3.1. Spalte assigned_truck_id: Datentyp INT UNSIGNED, Standardwert NULL. Fremdschlüssel auf trucks.id.
2.3.3.2. Lösch-Verhalten: Definition von ON DELETE SET NULL.
2.3.3.3. Exklusivität: Ein UNIQUE-Index auf assigned_truck_id stellt sicher, dass ein LKW-Platz nur einmal belegt wird.
2.3.4. Implementierung der HTML-Extraktion (PHP DOM)
2.3.4.1. Parser-Technologie: Einsatz der PHP-Klasse DOMDocument und DOMXPath anstelle von reinen Regulären Ausdrücken zur Verarbeitung des Quellcodes.
2.3.4.2. Selektion: Zielgerichtetes Ansteuern aller Knoten des Typs div mit der Klasse humanresources.
2.3.4.3. ID-Extraktion: Auslesen der ID aus dem entsprechenden HTML-Attribut (z.B. aus einem Link href="...driver=15" oder einem Daten-Attribut).
2.3.5. Dubletten-Management und Update-Logik
2.3.5.1. Eindeutigkeits-Prüfung: Der Primär-Check erfolgt ausschließlich über die ingame_driver_id.
2.3.5.2. Upsert-Verfahren: Beim Einlesen wird das SQL-Kommando INSERT ... ON DUPLICATE KEY UPDATE verwendet. Dies aktualisiert automatisch die Qualifikationswerte (Skill, Reliability) bestehender Fahrer, falls sich diese durch Training im Spiel geändert haben, ohne neue Datensätze anzulegen.
2.3.5.3. Datenkonsistenz: Durch die Verwendung der Ingame-ID wird die Verwechslung von Fahrern mit identischen Namen (z.B. zwei mal "Peter Maier") technisch unmöglich gemacht.


2.4. Tabelle trucks (Fuhrparkverwaltung)
2.4.1. Stammdaten-Struktur und Identifikation
2.4.1.1. Spalte id: Technischer Primärschlüssel, Datentyp INT UNSIGNED, automatisch inkrementierend.
2.4.1.2. Spalte ingame_vehicle_id: Eindeutige technische Kennung aus dem Spiel-Quelltext, Datentyp VARCHAR(50), definiert als UNIQUE Index. (Formatänderung von INT zu VARCHAR zur Einhaltung der globalen Regel 1.2.3.2).
2.4.1.3. Spalte user_label: Die vom Benutzer manuell vergebene Bezeichnung (z.B. "LKW-01"), Datentyp VARCHAR(50).
2.4.1.4. Spalte vehicle_type: Datentyp ENUM. Zulässige Werte: 'Kurier', 'Stückgut', 'Schüttgut', 'Pritsche', 'Plane', 'Koffer', 'Kühlwagen', 'Silo', 'Tankwagen', 'Schwertransport', 'ISO-Container', 'Super-Liner'.
2.4.1.5. Spalte capacity_t: Maximale Tonnage, Datentyp SMALLINT UNSIGNED.
2.4.1.6. Spalte year_built: Baujahr, Datentyp SMALLINT UNSIGNED.
2.4.2. Technischer Status und Standort
2.4.2.1. Spalte km_stand: Aktuelle Laufleistung, Datentyp INT UNSIGNED, Standardwert 0.
2.4.2.2. Spalte current_city_id: Aktueller physischer Standort des Fahrzeugs, Datentyp INT UNSIGNED, Fremdschlüssel auf cities.id.
2.4.2.3. Lösch-Verhalten current_city_id: ON DELETE RESTRICT.
2.4.2.3.1. Begründung: Ein Fahrzeug muss in diesem ERP-System zwingend an einem definierten Ort existieren. Da Städte systemimmanent nicht gelöscht werden, schützt RESTRICT die Standort-Integrität der Flotte. Ein LKW kann nicht "ortlos" werden.
2.4.2.4. Spalte has_tuning_motor: Boolean-Flag für Leistungsoptimierung.
2.4.2.5. Spalte has_tuning_aero: Boolean-Flag für Aerodynamik-Paket.
2.4.2.6. Spalte has_tuning_stau: Boolean-Flag für Stauwarner.
2.4.3. Planungs- und UI-Status
2.4.3.1. Spalte is_active_planning: Datentyp BOOLEAN. Markiert Fahrzeuge, die für die aktuelle Tourenberechnung (Punkt 4.2.5) berücksichtigt werden.
2.4.3.2. Spalte is_focussed: Datentyp BOOLEAN. Speichert die Auswahl für die detaillierte Vorschlagsansicht im Dispatcher-Board.
2.4.4. Implementierung der HTML-Extraktion (PHP DOM)
2.4.4.1. Prozess: Nutzung der Klasse DOMXPath zur Identifikation der Fahrzeug-Container im Quelltext.
2.4.4.2. ID-Gewinnung: Extraktion der ingame_vehicle_id aus den Attributen oder Verlinkungen der Fahrzeugübersicht.
2.4.4.3. Synchronisation: Einsatz von INSERT ... ON DUPLICATE KEY UPDATE zur Aktualisierung von KM-Stand, Standort und Tuning-Status bei jedem neuen Quelltext-Import.
2.4.5. Validierung und Integrität
2.4.5.1. Stammdaten-Schutz: Das Baujahr und der Fahrzeugtyp werden nach der ersten Erfassung nur aktualisiert, wenn die ingame_vehicle_id identisch ist.
2.4.5.2. Relations-Sicherheit: Alle fahrzeugbezogenen Aufträge (Tabelle orders) referenzieren ausschließlich die technische id der Tabelle trucks.

2.5. Tabelle orders (Auftragsverwaltung)

2.5.1. Identifikation und Stammdaten

2.5.1.1. Spalte id: Technischer Primärschlüssel, Datentyp INT UNSIGNED, automatisch inkrementierend.
2.5.1.2. Spalte ingame_order_id: Die offizielle Auftragsnummer aus dem Spiel (z. B. "IDN10620759"), Datentyp VARCHAR(20).
2.5.1.3. Eindeutigkeits-Regel: Diese Spalte ist als UNIQUE Index definiert, erlaubt jedoch NULL-Werte für Aufträge aus der Frachtbörse (Marktpool), die noch keine feste ID besitzen.
2.5.1.4. Spalte fingerprint: Inhaltsbasierter Identifikations-Hash für Marktdaten gemäß Punkt 3.4.2, Datentyp VARCHAR(32), definiert als Index (nicht Unique), um historische Dubletten im Archiv (is_archived=1) zu ermöglichen.
2.5.1.5. Spalte freight_type: Speicherung des benötigten Fahrzeugtyps (z. B. "Kühlwagen"), Datentyp VARCHAR(50).
2.5.1.6. Spalte commodity: Speicherung der Warenbezeichnung (z. B. "Laborzubehör"), Datentyp VARCHAR(100).
2.5.1.7. Spalte is_adr: Datentyp BOOLEAN (TINYINT 1), Standardwert FALSE. Kennzeichnet Aufträge, die zwingend eine Gefahrguterlaubnis (ADR) des Fahrers erfordern.
2.5.2. Mengen- und Werterfassung
2.5.2.1. Spalte weight_total: Gesamtgewicht des Auftrags bei Annahme, Datentyp INT UNSIGNED.
2.5.2.2. Spalte weight_remaining: Aktuell noch zu transportierende Restmenge, Datentyp INT UNSIGNED.
2.5.2.3. Spalte revenue: Gesamterlös des Auftrags, Datentyp DECIMAL(12,2). Die Speicherung erfolgt nach der 3-Stufen-Geldlogik (Punkt 3.1).
2.5.3. Logistik-Relationen und Status
2.5.3.1. Spalte from_city_id: Abholort, Datentyp INT UNSIGNED, Fremdschlüssel auf cities.id.
2.5.3.1.1. Lösch-Verhalten: ON DELETE RESTRICT. Die Datenbank verweigert das Löschen einer Stadt, solange diese als Startpunkt eines Auftrags in der Tabelle orders geführt wird.
2.5.3.2. Spalte to_city_id: Zielort, Datentyp INT UNSIGNED, Fremdschlüssel auf cities.id.
2.5.3.2.1. Lösch-Verhalten: ON DELETE RESTRICT. Die Datenbank verweigert das Löschen einer Stadt, solange diese als Zielpunkt eines Auftrags geführt wird.
2.5.3.3. Spalte is_accepted: Status-Flag, Datentyp BOOLEAN. TRUE markiert Aufträge im eigenen Lager (reserviert), FALSE markiert verfügbare Aufträge in der Frachtbörse.
2.5.3.4. Spalte is_archived: Status-Flag für den Lifecycle-Status, Datentyp BOOLEAN. TRUE markiert abgeschlossene oder veraltete Aufträge, die für die operativen Sichten (Punkt 4.2) unsichtbar sind.
2.5.4. Dispositions- und Chronologie-Daten
2.5.4.1. Spalte assigned_truck_id: Aktuelle Zuweisung zu einem Fahrzeug, Datentyp INT UNSIGNED, Fremdschlüssel auf trucks.id. Standardwert NULL.
2.5.4.2. Spalte assigned_at: Zeitstempel der Zuweisung zum LKW, Datentyp DATETIME, Standardwert NULL.
2.5.4.3. Spalte last_seen_at: Zeitstempel des letzten erfolgreichen Daten-Imports. Dient als Referenz für die automatische Archivierung (Punkt 3.4.3).
2.5.4.4. Spalte completed_at: Zeitstempel der Überführung ins Archiv (Abschluss der Tour oder Verschwinden aus dem Pool), Datentyp DATETIME.
2.5.4.5. Chronologie-Logik: Die Zeitstempel assigned_at und completed_at sind essenziell für die Fahrplan-Logik (Punkt 4.3), um die Reihenfolge der Etappen innerhalb einer Tour zweifelsfrei zu definieren und den Rücksprung-Algorithmus (Punkt 4.3.5) zu steuern.
2.5.5. Objektorientierte Implementierung (PHP Class Order)
2.5.5.1. Eigenschafts-Mapping: Die Klasse Order bildet alle Spalten (einschließlich der Status- und Zeitstempel-Felder) als private Eigenschaften mit entsprechenden Getter- und Setter-Methoden ab.
2.5.5.2. Effizienz-Methode: Implementierung von public function getEarningPerTkm(int $distance): float. Berechnet live den ökonomischen Wert für den Tie-Breaker des Algorithmus (Punkt 6.3).
2.5.5.3. Synchronisations-Logik: Bei erneutem Import via HTML/Text wird für existierende ingame_order_ids lediglich die weight_remaining aktualisiert, um bereits begonnene Teillieferungen korrekt abzubilden. Markt-Aufträge ohne Ingame-ID werden über die Fingerprint-Validierung (Punkt 3.4.2) behandelt.

2.6. Tabelle market_history: Archiv für Gebrauchtwagen-Daten und ROI-Statistiken
2.6.1. Schema-Spezifikation (Struktur)
2.6.1.1. Spalte id: Technischer Primärschlüssel, Datentyp INT UNSIGNED, automatisch inkrementierend.
2.6.1.2. Spalte ingame_vehicle_id: Eindeutige technische Kennung aus dem Spiel-Quelltext, Datentyp VARCHAR(50), definiert als UNIQUE Index.
2.6.1.3. Spalte location_label: Der Verkaufsstandort als reiner Textwert (String), Datentyp VARCHAR(100).
2.6.1.3.1. Wichtige Regel: Es erfolgt keine Verknüpfung zur Tabelle cities und keine automatische Neuanlage in der Transport-Matrix, um Städte ohne Länderlizenz (z.B. Karlsbad) aus der operativen Disposition (Punkt 4.1.1) fernzuhalten.
2.6.1.4. Spalte vehicle_type: Datentyp ENUM ('Kurier', 'Stückgut', ... 'Super-Liner').
2.6.1.5. Spalte capacity_t: Tonnage, Datentyp SMALLINT UNSIGNED.
2.6.1.6. Spalte year_built: Baujahr, Datentyp SMALLINT UNSIGNED.
2.6.1.7. Spalte km_stand: Laufleistung, Datentyp INT UNSIGNED.
2.6.1.8. Spalte condition_pct: Fahrzeugzustand, Datentyp DECIMAL(5,2).
2.6.1.9. Spalte price: Angebotspreis, Datentyp DECIMAL(12,2).
2.6.1.10. Spalte tuning_value_total: Summe des enthaltenen Tuning-Wertes, Datentyp INT UNSIGNED.
2.6.1.11. Spalte roi_score: Berechneter Effizienz-Wert, Datentyp INT UNSIGNED.
2.6.1.12. Spalte recorded_at: Erfassungszeitpunkt, Datentyp TIMESTAMP, Standard CURRENT_TIMESTAMP.
2.6.2. Identifikations- und Update-Logik
2.6.2.1. Primär-Identifikator: Die Erkennung erfolgt ausschließlich über die ingame_vehicle_id.
2.6.2.2. Dubletten-Handhabung: Beim Einlesen via HTML-Import (Punkt 5.1) wird INSERT ... ON DUPLICATE KEY UPDATE verwendet.
2.6.2.3. Preis-Tracking: Ändert sich der Preis einer bestehenden Anzeige im Spiel, wird der historische Datensatz in der Datenbank aktualisiert, um stets den aktuellsten Markt-Score abzubilden.
2.6.3. Berechnungs-Logik (ROI-Score)
2.6.3.1. Effektiver Preis: Basis ist der Brutto-Preis abzüglich des Tuning-Wertes (Motor: 3.000, Aero: 4.000, Stau: 1.000).
2.6.3.2. Qualitäts-Faktor: Der Zustand (condition_pct) wird als Basis (0.0 bis 1.0) verwendet und durch Alters-Mali (PH 5.2) reduziert:
2.6.3.2.1. Alter größergleich 8 Jahre: Faktor-Reduktion um 40%.
2.6.3.2.2. Alter größergleich 15 Jahre: Faktor-Reduktion um 70%.
2.6.3.2.3. Laufleistung größergleich 1 Mio. KM: Faktor-Reduktion um 20%.
2.6.3.2.4. Laufleistung größergleich 2 Mio. KM: Faktor-Reduktion um 50%.
2.6.3.3. Endformel: ROI-Score = (Preis - Tuning) / (Kapazität * (Zustand * Mali)).
2.6.4. Statistische Aggregation
2.6.4.1. Marktdurchschnitt: Das System berechnet bei Abfrage den AVG(roi_score) gruppiert nach vehicle_type und capacity_t.
2.6.4.2. Bewertung: Neue Anzeigen werden gegen diesen Durchschnitt validiert (z.B. "15% besser als Marktschnitt").
2.6.5. Objektorientierte Implementierung (PHP Class MarketHistory)
2.6.5.1. Kapselung: Die Klasse MarketHistory verwaltet den Zugriff auf das Preisarchiv.
2.6.5.2. Entkopplung: Die Anzeige des Verkaufsortes (location_label) erfolgt rein informativ und nimmt nicht am Routing-Algorithmus der distances-Logik teil.

3. Modulare Parser-Logik ("One Job, one Tool")

3.1. Geld-Logik (3-Stufen-Prozess):
3.1.1. Stufe 1: Entfernung aller Tausender-Kommas aus dem Roh-String
3.1.1.1. Funktionaler Zweck und Abgrenzung
3.1.1.1.1. Transformation von US-formatierten Währungs-Zeichenfolgen (z. B. 1,153.32) in ein für die PHP-Standardfunktionen verarbeitbares numerisches Format.
3.1.1.1.2. Sicherstellung, dass das Komma-Zeichen (ASCII 44) nicht fälschlicherweise als Dezimaltrenner interpretiert wird, was zu massiven Berechnungsfehlern (Faktor 100 oder höher) führen würde.
3.1.1.2. Architektur der Implementierung
3.1.1.2.1. Struktur: Die Logik wird in der statischen Klasse FinanceMapper gekapselt.
3.1.1.2.2. Methode: Implementierung der öffentlichen statischen Methode public static function stripSeparators(string $rawAmount): string.
3.1.1.2.3. Algorithmus: Verwendung der Funktion str_replace(',', '', $inputString) zur rückstandslosen Entfernung aller Tausender-Kommas.
3.1.1.2.4. Vorreinigung: Innerhalb der Methode werden mittels preg_replace('/[^\d.,]/', '', $inputString) sämtliche Nicht-Zahlzeichen (Währungssymbole, Leerzeichen, Texte wie "Euro") entfernt, bevor die Komma-Eliminierung stattfindet.
3.1.1.3. Daten-Transformation (Beispielhafte Verarbeitung)
3.1.1.3.1. Eingabe-Zustand: Ein String-Wert aus dem HTML-Extraktor, z. B. "12,121.91".
3.1.1.3.2. Prozessschritt: Der FinanceMapper lokalisiert alle Instanzen des Zeichens , und entfernt diese.
3.1.1.3.3. Ausgabe-Zustand: Ein bereinigter String-Wert "12121.91".
3.1.1.4. Validierung und Integritätssicherung
3.1.1.4.1. Konsistenz-Check: Die Methode prüft nach der Komma-Entfernung mittels substr_count(), ob die Anzahl der verbliebenen Dezimalpunkte (.) den Wert 1 nicht überschreitet.
3.1.1.4.2. Ausnahme-Handling: Bei Detektion von mehr als einem Dezimalpunkt oder verbliebenen Kommas löst das System eine MalformedCurrencyException aus. Dies unterbricht den Parsing-Vorgang sofort und verhindert die Injektion korrupter Finanzdaten in die SQL-Datenbank.
3.1.1.5. Interaktion mit dem Parser-Modul
3.1.1.5.1. Aufruf-Zeitpunkt: Die Stufe 1 wird unmittelbar nach der Extraktion des Textknotens aus dem Spiel-Quelltext durch die jeweilige Parser-Klasse (z. B. MarketParser) aufgerufen.
3.1.1.5.2. Datentyp-Garantie: Das Ergebnis von Stufe 1 bleibt ein String, um Präzisionsverluste vor der eigentlichen Konvertierung in Stufe 2 (Punkt 3.1.2) auszuschließen.

3.1.2. Stufe 2: Konvertierung in SQL-kompatiblen Float (Punkt bleibt Dezimaltrenner)
3.1.2.1. Funktionaler Zweck der Konvertierung
3.1.2.1.1. Finale Überführung der bereinigten Zeichenfolge (String) in einen numerischen Datentyp zur Ermöglichung mathematischer Operationen innerhalb der Anwendung.
3.1.2.1.2. Bereitstellung eines präzisen Übergabewertes für die SQL-Schnittstelle zur Speicherung in Festkommazahl-Spalten (DECIMAL).
3.1.2.2. Technische Umsetzung in PHP
3.1.2.2.1. Verarbeitungsort: Die Methode wird in der statischen Klasse FinanceMapper implementiert.
3.1.2.2.2. Konvertierungsmethode: Anwendung eines expliziten Type-Castings mittels (float)$cleanedString.
3.1.2.2.3. Standardisierung: Da Stufe 1 (Punkt 3.1.1) alle Tausender-Separatoren entfernt hat, gewährleistet dieser Schritt, dass PHP den verbleibenden Punkt (.) konsistent als Dezimaltrennzeichen interpretiert.
3.1.2.3. Mathematische Validierung und Rundung
3.1.2.3.1. Werteprüfung: Einsatz der Funktion is_numeric() vor dem Casting, um sicherzustellen, dass keine ungültigen Zeichenfolgen verarbeitet werden.
3.1.2.3.2. Präzisionssicherung: Das System behält die volle Genauigkeit der im Spiel-Quelltext angegebenen zwei Nachkommastellen bei. Eine Rundung findet auf dieser Ebene nicht statt, um Verfälschungen bei der späteren Aggregation (z. B. Umsatzsummen im Cockpit) zu vermeiden.
3.1.2.4. Vorbereitung für die SQL-Persistenz
3.1.2.4.1. Kompatibilität: Der resultierende Float-Wert ist direkt kompatibel mit den in den Tabellen orders (Punkt 2.5.2.3) und market_history (Punkt 2.6.1.9) definierten DECIMAL(12,2) Feldern.
3.1.2.4.2. PDO-Integration: Der numerische Wert wird als Parameter an das Prepared Statement übergeben. Das RDBMS übernimmt die finale Konvertierung in das interne Festkomma-Format der Datenbank.
3.1.2.5. Methodenspezifikation (PHP 8.2)
3.1.2.5.1. Signatur: public static function parseToNumeric(string $strippedAmount): float.
3.1.2.5.2. Fehlerfall: Sollte die Konvertierung einen unendlichen Wert (INF) oder den Zustand NAN (Not a Number) ergeben, ist eine ArithmeticException auszulösen.

3.1.3. Stufe 3: Formatierte Ausgabe im UI (Tausenderpunkte, Dezimalkomma)
3.1.3.1. Funktionaler Zweck der Ausgabeformatierung
3.1.3.1.1. Konvertierung der systeminternen numerischen Werte (Float/Decimal) in die deutsche Lokalisierungskonvention zur optimalen Lesbarkeit für den Benutzer.
3.1.3.1.2. Sicherstellung einer festen Spaltenoptik durch erzwungene Nachkommastellen bei allen Finanzbeträgen.
3.1.3.2. Technische Implementierung (FinanceMapper)
3.1.3.2.1. Funktionsaufruf: Einsatz der PHP-Funktion number_format($value, 2, ',', '.') innerhalb der statischen Methode FinanceMapper::format().
3.1.3.2.2. Rückgabetyp: Die Methode liefert einen rein formatierten String zurück (z. B. "1.153,32").
3.1.3.3. Formatierungs-Vorgaben
3.1.3.3.1. Trennzeichen-Mapping: Der Punkt (.) wird als Tausender-Separator und das Komma (,) als Dezimaltrenner gesetzt.
3.1.3.3.2. Dezimalstellen: Es werden ausnahmslos zwei Dezimalstellen ausgegeben, um Rundungsdifferenzen in der Anzeige zu vermeiden und eine vertikale Ausrichtung der Kommas in Tabellen zu ermöglichen.
3.1.3.4. Frontend-Integration und Element-Struktur
3.1.3.4.1. Daten-Kapselung: Finanzbeträge werden in Tabellenzellen (<td>) ausgegeben, die grundsätzlich die CSS-Klasse .cell-numeric tragen.
3.1.3.4.2. Währungssymbol-Implementierung: Das Währungssymbol wird über ein <span>-Element mit der CSS-Klasse .currency-unit realisiert. Die vollständige HTML-Struktur lautet: 1.153,32 <span class="currency-unit">€</span>.
3.1.3.4.3. CSS-Definition: Die Klasse .currency-unit wird in der zentralen CSS-Datei definiert (z. B. mit einem margin-left: 0.3em und einer reduzierten Deckkraft von 0.7), um den Fokus auf dem Zahlenwert zu belassen.
3.1.3.5. Konditionale Anzeige-Logik
3.1.3.5.1. Farbliche Kennzeichnung: Die View-Schicht umschließt den Betrag bei Erlöswerten mit einem zusätzlichen <span>, welcher die Klasse .text-profit (#2ecc71) führt.
3.1.3.5.2. Nullwerte: Beträge von genau 0.00 werden als - <span class="currency-unit">€</span> ausgegeben, um die Übersichtlichkeit im Dispatcher-Tableau (Punkt 4.2) bei Leerfahrten zu erhöhen.

3.2.2. Neuanlage von Städten und manuelle Matrix-Pflege
3.2.2.1. Funktionaler Zweck und Entstehungspfade
3.2.2.1.1. Gewährleistung der Datenbank-Vollständigkeit bei Expansion in neue Länderlizenzen.
3.2.2.1.2. Strikte Definition der drei zulässigen Entstehungspfade für neue Datensätze in der Tabelle cities:
3.2.2.1.2.1. Import via Lager-Aufträge (market.php).
3.2.2.1.2.2. Import via Pool-Aufträge (market.php).
3.2.2.1.2.3. Explizite manuelle Eingabe über das Matrix-Formular (matrix_admin.php).
3.2.2.2. Automatisierte Neuanlage beim Import (Pfad 1 & 2)
3.2.2.2.1. Trigger-Logik: Die Methode CityService::resolveId() (Punkt 3.2.1.3) wird von den Order-Parsern mit dem Parameter $autoCreate = true aufgerufen.
3.2.2.2.2. Persistenz-Schritt: Wird ein normalisierter Stadtname nicht gefunden, führt das System ein INSERT INTO cities (name) VALUES (:name) aus.
3.2.2.2.3. ID-Rückgabe: Unmittelbar nach dem Insert wird die neue technische ID mittels PDO::lastInsertId() für die weitere Verarbeitung im Auftrag (Tabelle orders) bereitgestellt.
3.2.2.3. Manuelle Matrix-Pflege (Pfad 3: Das Formular)
3.2.2.3.1. Tool-Zuweisung: Erstellung der Datei matrix_admin.php gemäß der Design-Prämisse "One Job, one Tool" (Punkt 1.3).
3.2.2.3.2. Formular-Struktur: Das Interface bietet drei Eingabefelder:
3.2.2.3.2.1. Feld "Stadt A": Textfeld mit Smart-Fill-Anbindung (Datalist).
3.2.2.3.2.2. Feld "Stadt B": Textfeld mit Smart-Fill-Anbindung (Datalist).
3.2.2.3.2.3. Feld "Distanz": Numerisches Feld für die Entfernung in vollen KM.
3.2.2.3.3. Transaktions-Logik bei Absenden:
3.2.2.3.3.1. Schritt 1: Auflösung/Neuanlage von Stadt A (liefert id_a).
3.2.2.3.3.2. Schritt 2: Auflösung/Neuanlage von Stadt B (liefert id_b).
3.2.2.3.3.3. Schritt 3: Aufruf der Methode DistanceService::setDistance(id_a, id_b, km) unter Einhaltung der Normalisierungsregel (Punkt 2.2.4.1).
3.2.2.4. Validierung bei Neuanlage
3.2.2.4.1. Dubletten-Sperre: Da die Spalte cities.name über einen UNIQUE Index verfügt (Punkt 2.1.3.1), fängt die Datenbank fehlerhafte Mehrfachanlagen auf, falls die Anwendungslogik versagt.
3.2.2.4.2. String-Integrität: Namen, die nach der Normalisierung (Punkt 3.2.1.2) kürzer als 2 Zeichen sind, werden als ungültig abgewiesen und führen nicht zur Neuanlage.
3.2.2.5. Benutzerrückmeldung
3.2.2.5.1. Status-Indikation: Bei automatischer Neuanlage während eines Imports wird im Erfolgsbericht (Punkt 1.3.4.3) zusätzlich die Anzahl der neu "gelernten" Städte ausgegeben (z.B. "3 neue Städte in die Matrix aufgenommen").

3.2.3. Speicherung neuer Entfernungen in distances aus Import-Daten
3.2.3.1. Funktionaler Zweck der Extraktion
3.2.3.1.1. Kontinuierliche Erweiterung der Matrix durch Erfassung bisher unbekannter Städteverbindungen während des Imports.
3.2.3.1.2. Schutz des Datenbestandes vor fehlerhaften Überschreibungen durch ungenaue Parser-Ergebnisse oder temporäre UI-Fehler im Quelltext.
3.2.3.2. Import-Logik und Kollisionsprüfung
3.2.3.2.1. Erfassung: Der Parser extrahiert id_a, id_b und km_neu aus dem Textblock.
3.2.3.2.2. Existenz-Check: Vor jedem Schreibvorgang führt der DistanceService eine Abfrage (SELECT distance_km) für das normalisierte Städtepaar durch.
3.2.3.2.3. Fall A (Neu): Existiert kein Datensatz, wird die Entfernung mittels INSERT angelegt.
3.2.3.2.4. Fall B (Identisch): Entspricht km_neu dem Wert in der Datenbank (km_db), wird die Operation ohne weitere Aktion beendet.
3.2.3.2.5. Fall C (Konflikt): Unterscheidet sich km_neu von km_db, wird der Schreibvorgang für diesen Datensatz blockiert.
3.2.3.3. Warnsystem bei Daten-Konflikten
3.2.3.3.1. Protokollierung: Im Falle eines blockierten Schreibvorgangs (Fall C) wird der Konflikt in einem internen Warn-Array gespeichert.
3.2.3.3.2. UI-Meldung: Nach Abschluss des Imports wird im Erfolgsbericht (Punkt 1.3.4.3) eine prominente Warnung ausgegeben: "Achtung: Abweichende Distanz für Route [Stadt A] ➔ [Stadt B] ignoriert (DB: X km / Import: Y km)."
3.2.3.3.3. Handlungsaufforderung: Der Benutzer wird darauf hingewiesen, die Korrektheit manuell in der matrix_admin.php zu prüfen.
3.2.3.4. Manuelle Korrektur-Schnittstelle (matrix_admin.php)
3.2.3.4.1. Autorisierter Pfad: Änderungen an bestehenden Distanzen sind ausschließlich über die Editiermaske dieses Moduls zulässig.
3.2.3.4.2. Darstellung: Bestehende Verbindungen werden in einer editierbaren Tabelle gelistet.
3.2.3.4.3. Sicherheits-Interlock (Frontend): Jede Änderung (Update) oder Löschung eines Datensatzes muss über einen JavaScript-confirm()-Dialog ("Möchten Sie die bestehende Distanz wirklich dauerhaft ändern?") durch den Benutzer bestätigt werden.
3.2.3.4.4. Validierung (Backend): Die PHP-Klasse prüft, ob der neue Wert numerisch und plausibel ist, bevor die SQL-Transaktion ausgeführt wird.
3.2.3.5. Daten-Integrität im SQL-Modell
3.2.3.5.1. Transaktions-Sicherheit: Alle manuellen Änderungen werden innerhalb einer SQL-Transaktion durchgeführt, um bei Fehlern einen Rollback zu ermöglichen.
3.2.3.5.2. Audit-Log: Optional wird jede manuelle Änderung mit einem Zeitstempel in einer separaten Log-Datei dokumentiert, um die Nachvollziehbarkeit bei Matrix-Fehlern zu gewährleisten.

3.3.1. Identifikation der ADR-Markierung im Spiel-Text
3.3.1.1. Funktionaler Zweck der ADR-Erkennung
3.3.1.1.1. Identifikation von Aufträgen mit speziellen Sicherheitsanforderungen (Gefahrgut), um Fehlplanungen durch Zuweisung nicht qualifizierter Fahrer (ohne ADR-Erlaubnis) auszuschließen.
3.3.1.1.2. Vorbereitung der Datenbasis für das visuelle Warnsystem im Dispatcher-Board (Punkt 4.3.2) und die Validierungslogik des Algorithmus (Punkt 6.4).
3.3.1.2. Mustererkennung und Tokenisierung
3.3.1.2.1. Erkennungs-Anker: Der Parser sucht innerhalb eines Auftrags-Datenblocks gezielt nach der exakten Zeichenfolge [Gefahrgut] (einschließlich der eckigen Klammern).
3.3.1.2.2. Robustheit: Die Suche erfolgt mittels eines regulären Ausdrucks unter Berücksichtigung optionaler Leerzeichen: /\[\s?Gefahrgut\s?\]/i.
3.3.1.2.3. Positionierung: Die Markierung tritt im Spiel-Text typischerweise zwischen der Warenbezeichnung und der Tonnage-Angabe auf. Der Parser darf diesen String nicht als Teil des Warennamens (commodity) interpretieren.
3.3.1.3. Technische Verarbeitung (Parser-Logik)
3.3.1.3.1. Extraktions-Schritt: Bevor die weiteren Auftragsdaten verarbeitet werden, prüft die Methode OrderParser::detectAdr() das Vorhandensein des Ankers.
3.3.1.3.2. Zustands-Zuweisung: Wird die Markierung gefunden, wird die interne Variable $isAdr auf den Boolean-Wert true gesetzt. Andernfalls ist der Standardwert false.
3.3.1.3.3. Bereinigung: Nach der Erkennung wird der String [Gefahrgut] aus dem Quelltext-Puffer entfernt, um die nachfolgende Extraktion von Gewicht und Städten (Punkt 3.2.1) nicht durch zusätzliche Zeichenfolgen zu stören.
3.3.1.4. Datenbank-Persistenz
3.3.1.4.1. Mapping: Der ermittelte Boolean-Status wird direkt in die Spalte is_adr der Tabelle orders (Punkt 2.5.1.6) geschrieben.
3.3.1.4.2. Synchronisation: Bei einem Re-Import (z.B. Lager-Update) wird der ADR-Status bestehender Aufträge grundsätzlich beibehalten, sofern die Ingame-ID identisch ist.
3.3.1.5. Validierung und Fehlerbehandlung
3.3.1.5.1. Plausibilitäts-Check: Der Parser stellt sicher, dass Markierungen nicht fälschlicherweise in Städtenamen oder Beträgen erkannt werden (Kontextprüfung innerhalb des Datenblocks).
3.3.1.5.2. Logging: Sollte ein Frachttyp erkannt werden, der laut Spiel-Definition IMMER Gefahrgut ist (z.B. "Benzin" im Tankwagen), die Markierung aber fehlen, kann das System eine Warnung für den Administrator generieren.

3.3.2. Visuelle Kennzeichnung der ADR-Aufträge und Filter-Logik
3.3.2.1. Funktionaler Zweck der Kennzeichnung und Filterung
3.3.2.1.1. Deklaratorische Kennzeichnung von Gefahrgut-Aufträgen in Listen, für die das Fahrzeug und der Fahrer grundsätzlich qualifiziert sind.
3.3.2.1.2. Strikte Ausschluss-Logik: Aufträge mit gesetztem is_adr-Flag (Punkt 2.5.1.6) dürfen in keinem Modul als Option für einen Fahrer ohne adr_permit (Punkt 2.3.2.3) erscheinen.
3.3.2.2. Definition der UI-Komponente (ADR-Badge)
3.3.2.2.1. HTML-Struktur: Einsatz eines <span>-Elements mit der CSS-Klasse .badge-adr.
3.3.2.2.2. Visuelle Gestaltung: Hintergrundfarbe #e67e22 (Warn-Orange), Textfarbe #000000, Schriftschnitt fett, Ecken abgerundet (3px).
3.3.2.2.3. Platzierung: Das Badge wird in der Zeile des Auftrags unmittelbar vor der Warenbezeichnung positioniert, um die Aufmerksamkeit des Disponenten sofort auf die Sonderfracht zu lenken.
3.3.2.3. Implementierung in der Frachtbörse (Market Pool)
3.3.2.3.1. Informationsgehalt: Da in der Börse noch keine feste Fahrerzuordnung besteht, werden hier alle ADR-Aufträge mit dem Badge gekennzeichnet, um den Planungsaufwand (Suche nach ADR-Fahrer) zu signalisieren.
3.3.2.3.2. Tooltip-Funktion: Das Badge erhält ein title-Attribut mit dem Text: "ADR-Sonderfracht: Nur für zertifiziertes Personal".
3.3.2.4. Implementierung im Dispatcher-Board (Harte Filterregel)
3.3.2.4.1. SQL-Integration: Bei der Generierung der Vorschlagsliste für ein Fahrzeug (Punkt 4.2.6) wird die SQL-Abfrage um eine Bedingung erweitert: AND (orders.is_adr = 0 OR (SELECT adr_permit FROM drivers WHERE assigned_truck_id = trucks.id) = 1).
3.3.2.4.2. Konsequenz: Verfügt der dem LKW zugewiesene Fahrer nicht über die notwendige Erlaubnis, wird der Auftrag im Backend verworfen. Er erscheint weder als Vorschlag noch als Fallback (Punkt 6.5).
3.3.2.4.3. Sichtbarkeit im Fahrplan: Bei bereits eingeplanten oder übernommenen Aufträgen im Lager (is_accepted = 1) dient das Badge in der Tour-Tabelle (Punkt 4.3.4) als rein informative Bestätigung der Sonderfracht.
3.3.2.5. Technische Logik der View-Schicht (PHP OOP)
3.3.2.5.1. Kapselung: Die Methode OrderViewHelper::renderAdrBadge(Order $order) prüft das Attribut is_adr.
3.3.2.5.2. Rückgabe-Standard: Liefert den vollständigen HTML-String oder einen leeren String ("") zurück, falls keine ADR-Anforderung vorliegt.
3.3.2.5.3. Zentrale Steuerung: Die Filter-Logik (Ausschluss) wird zentral in der OrderRepository-Klasse implementiert, um sicherzustellen, dass die "Unsichtbarkeit" systemweit konsistent angewendet wird.

3.4. Dubletten-Schutz und Archivierungs-Logik (Historische Einmaligkeit)
3.4.1. Identifikations-Architektur
3.4.1.1. Technischer Primärschlüssel (Archiv-ID): Jede Tabellenzeile erhält zwingend eine id (INT UNSIGNED, AUTO_INCREMENT). Diese fungiert als die vom Benutzer geforderte eindeutige Archiv-ID. Sie ist über die gesamte Lebensdauer des Systems einmalig.
3.4.1.2. Inhaltlicher Fingerabdruck (Hash): Die Spalte fingerprint (VARCHAR 32) speichert den inhaltlichen Code eines Auftrags (MD5 aus Typ, Route, Gewicht, Erlös). Dieser ist nicht eindeutig (kein UNIQUE Constraint), um mehrfache Vorkommen des gleichen Auftragstyps über Monate hinweg separat zu erfassen.
3.4.2. Logik der Dubletten-Prüfung (Import-Phase)
3.4.2.1. Berechnung: Bei jedem Import wird für jeden Markt-Auftrag der aktuelle Fingerprint berechnet.
3.4.2.2. Aktiv-Prüfung: Das System führt einen Abgleich gegen den operativen Bestand durch: SELECT id FROM orders WHERE fingerprint = :current_hash AND is_archived = 0.
3.4.2.3. Fallunterscheidung:
3.4.2.3.1. Treffer im aktiven Bestand: Nur der Zeitstempel last_seen_at wird aktualisiert. Es erfolgt kein neuer Eintrag.
3.4.2.3.2. Kein aktiver Treffer: Es wird ein neuer Datensatz angelegt. Dabei ist es unerheblich, ob bereits identische Fingerprints im Archiv (is_archived = 1) existieren. Jeder neue "Auftritt" in der Börse erhält so seine eigene, eindeutige Archiv-ID (id).
3.4.3. Automatisierter Archivierungsprozess
3.4.3.1. Trigger: Der Prozess wird am Ende jedes Import-Vorgangs für die Frachtbörse ausgelöst.
3.4.3.2. Abgleich: Alle Datensätze mit is_accepted = 0 (Markt) und is_archived = 0 (Aktiv), deren Zeitstempel last_seen_at älter ist als der Startzeitpunkt des aktuellen Imports, werden identifiziert.
3.4.3.3. Status-Wandlung: Diese Datensätze werden über UPDATE orders SET is_archived = 1 WHERE ... in das Archiv überführt.
3.4.3.4. Persistenz: Die ursprüngliche Archiv-ID (id) bleibt unverändert, wodurch eine lückenlose Historie über die Häufigkeit und Preisentwicklung identischer Routen ermöglicht wird.
3.4.4. Synchronisations-Logik für Lager-Aufträge (Bestand)
3.4.4.1. Identifikations-Vorrang: Bei Lager-Aufträgen erfolgt der Abgleich zwingend über die ingame_order_id (IDN-Nummer).
3.4.4.2. Automatischer Abschluss (Auto-Archivierung):
3.4.4.2.1. Erscheint eine ingame_order_id, die aktuell in der Datenbank mit is_archived = 0 geführt wird, nicht mehr im neuesten Import-Text des Lagers, wird sie automatisch als „erledigt“ betrachtet.
3.4.4.2.2. Der Datensatz wird auf is_archived = 1 gesetzt. Erfolgsrechnung: Die Archivierung ermöglicht es, den tatsächlichen Realerlös (abgeschlossene IDN-Aufträge) statistisch von den bloßen Marktangeboten zu trennen.
3.4.4.2.3. Der Zeitstempel des Verschwindens wird als completed_at gespeichert. LKW-Status: Das Verschwinden eines Lager-Auftrags ist der Trigger, um den LKW als „bereit für neue Aufgaben“ zu markieren (da die geplante Tour abgearbeitet wurde).
3.4.5. Analyse-Spezifikation (VWL-Modul)
3.4.5.1. Eindeutigkeit: Durch die AUTO_INCREMENT-ID ist sichergestellt, dass auch bei zwei exakt identischen Aufträgen (z.B. im Abstand von 2 Monaten) beide Ereignisse statistisch separat gezählt werden können.
3.4.5.2. Gruppierung: Für Routen-Analysen wird die SQL-Klausel GROUP BY fingerprint verwendet, um alle historischen Vorkommen desselben Typs zusammenzufassen.3.4. Dubletten-Schutz: Prüfung auf existierende Ingame-IDs oder Fingerprint-Abgleich bei Marktdaten.

4. Strategische Disposition (Dispatcher)

4.1. Strategie-Sidebar (Links): Zweck: Die Sidebar fungiert als geografisches Frühwarnsystem. Sie ermöglicht dem Disponenten, auf einen Blick zu erkennen, in welchen lizensierten Städten aktuell keine Rückladungen im eigenen Lager vorhanden sind, um gezielt Akquise im Marktpool zu betreiben.
4.1.1. Anzeige aller Städte aus Tabelle cities
4.1.1.1. Zielsetzung und strategischer Nutzen
4.1.1.1.1. Bereitstellung einer lückenlosen Übersicht des gesamten lizensierten Transportnetzwerks in der Sidebar des Dispatchers.
4.1.1.1.2. Im Gegensatz zu einer reinen Auftragsliste müssen hier alle Städte der Matrix erscheinen, um geografische "Vakuum-Zonen" (Städte ohne Rückladung im Lager) sofort identifizierbar zu machen.
4.1.1.2. Backend-Logik (SQL-Query)
4.1.1.2.1. Abfrage-Typ: Einsatz eines LEFT JOIN ausgehend von der Tabelle cities.
4.1.1.2.2. Verknüpfungs-Bedingung: Verknüpfung der cities.id mit orders.from_city_id unter Berücksichtigung der Filter is_accepted = 1 (Lager) und is_archived = 0 (Aktiv).
4.1.1.2.3. Aggregation: Nutzung der SQL-Funktionen COUNT(orders.id) AS job_count und SUM(orders.weight_remaining) AS total_weight zur Berechnung der Bestandsdaten pro Stadt.
4.1.1.2.4. Gruppierung: Strikte Gruppierung nach cities.id, um sicherzustellen, dass jede Stadt exakt eine Zeile in der Sidebar belegt.
4.1.1.3. Datenaufbereitung in der Repository-Klasse (PHP)
4.1.1.3.1. Methode: Implementierung von CityRepository::getStrategicStatus(string $sortBy, string $direction): array.
4.1.1.3.2. Mapping: Das Ergebnis der SQL-Abfrage wird in ein Array von spezialisierten CityStatus-Value-Objects transformiert.
4.1.1.3.3. Null-Handling: Falls der LEFT JOIN für eine Stadt keine Aufträge findet, müssen job_count und total_weight im PHP-Objekt explizit mit 0 initialisiert werden, um die "FEHLT"-Logik (Punkt 4.1.3) vorzubereiten.
4.1.1.4. Frontend-Architektur (HTML/CSS)
4.1.1.4.1. Container-Struktur: Einbettung der Liste in ein <aside>-Element mit fester Breite (z. B. 320px) und unabhängigem vertikalen Scroll-Verhalten (sticky Positionierung mit overflow-y: auto).
4.1.1.4.2. Tabellarische Darstellung: Nutzung eines <table>-Elements innerhalb der Sidebar zur Gewährleistung einer sauberen vertikalen Ausrichtung der Kennzahlen (Stadtname, Job-Anzahl, Tonnage).
4.1.1.4.3. DOM-Optimierung: Da die Stadtliste bei hoher Länderanzahl hunderte Einträge umfassen kann, wird das HTML-Fragment serverseitig vor-gerendert, um die Client-Last zu minimieren.
4.1.1.5. Synchronisations-Trigger
4.1.1.5.1. Refresh-Bedingung: Die Anzeige muss zwingend aktualisiert werden, sobald im Fuhrpark-Board (Punkt 4.3) ein Auftrag geladen oder entfernt wird, da dies den Lagerbestand der betroffenen Städte unmittelbar verändert.
4.1.1.5.2. AJAX-Update: Zur Vermeidung eines kompletten Page-Reloads wird der Sidebar-Inhalt über einen gezielten AJAX-Call nach jeder Dispo-Aktion neu vom Server angefordert und in das DOM injiziert.

4.1.2. Spalten-Definition: Stadtname, Anzahl Jobs, Tonnage
4.1.2.1. Spalte 1: Stadtname (Identifikator)
4.1.2.1.1. Datenquelle: Attribut name aus der Tabelle cities.
4.1.2.1.2. Visuelle Repräsentation: Linksbündige Textausrichtung innerhalb der Tabellenzelle (<td>).
4.1.2.1.3. Dynamisches Styling: Städte, die über mindestens einen Lager-Auftrag verfügen (job_count > 0), werden mittels der CSS-Klasse .has-cargo farblich hervorgehoben (Blau-Ton gemäß PH 1.4.1.4), um die Einsatzbereitschaft zu signalisieren.
4.1.2.2. Spalte 2: Anzahl Jobs (Frequenz-Indikator)
4.1.2.2.1. Datenquelle: Aggregierter Wert COUNT(orders.id) aus der Sidebar-Query (Punkt 4.1.1.2).
4.1.2.2.2. Definition: Gezählt werden ausschließlich Datensätze im Status is_accepted = 1 (Lager) und is_archived = 0 (Aktiv), deren Startort (from_city_id) der jeweiligen Zeile entspricht.
4.1.2.2.3. Anzeige-Logik: Zentrierte Ausrichtung. Bei einem Wert von 0 wird zur Verbesserung der Scan-Lesbarkeit lediglich ein Bindestrich (-) ausgegeben, um das visuelle Rauschen zu minimieren.
4.1.2.3. Spalte 3: Tonnage (Volumen-Indikator)
4.1.2.3.1. Datenquelle: Aggregierter Wert SUM(orders.weight_remaining) aus der Sidebar-Query.
4.1.2.3.2. Datentyp-Handling: Der Wert wird in PHP als Integer verarbeitet.
4.1.2.3.3. Formatierung: Rechtsbündige Ausrichtung (text-align: right) zur Gewährleistung der vertikalen Vergleichbarkeit von Zahlenwerten.
4.1.2.3.4. Suffix-Regel: Jedem Wert ungleich Null wird die Maßeinheit t (Tonnen) angefügt.
4.1.2.4. Header-Konfiguration und Layout
4.1.2.4.1. Struktur: Einsatz eines <thead>-Bereichs mit drei Kopfzellen (<th>).
4.1.2.4.2. Benennung: Die Spaltenüberschriften lauten "Stadt", "Jobs" und "Bestand".
4.1.2.4.3. Interaktions-Indikator: Jede Kopfzelle erhält ein visuelles Sortier-Icon (Unicode Pfeilsymbole oder SVG), um die Funktionalität nach PH 4.1.4 zu kennzeichnen.
4.1.2.5. Programmierlogik der View-Komponente (PHP)
4.1.2.5.1. Template-Iteration: Die Generierung der Spalten erfolgt innerhalb einer foreach-Schleife über das vom Repository gelieferte Array (Punkt 4.1.1.3).
4.1.2.5.2. Escaping: Sämtliche Stadtnamen sind vor der Ausgabe mittels htmlspecialchars() zu maskieren, um XSS-Vulnerabilitäten bei potenziellen Sonderzeichen in neuen Stadtnamen (Punkt 3.2.2) zu verhindern.

4.1.3. Frühwarnsystem: Markierung "FEHLT" bei fehlender Lager-Tonnage
4.1.3.1. Funktionaler Zweck der Warnung
4.1.3.1.1. Identifikation von Versorgungslücken innerhalb des durch die Tabelle cities definierten lizensierten Transportnetzwerks.
4.1.3.1.2. Präventive Warnung vor der Entsendung von Fahrzeugen in Zielgebiete, für die zum aktuellen Planungszeitpunkt keine Rückladungen im Lagerbestand (is_accepted = 1) gesichert sind.
4.1.3.2. Definition des Daten-Scope
4.1.3.2.1. Die Sidebar basiert technisch ausschließlich auf der Tabelle cities (Stammdaten lizensierter/aktiver Standorte).
4.1.3.3. Logik des Alarmzustands
4.1.3.3.1. Aktivierungs-Trigger: Der Status FEHLT wird für eine Zeile generiert, wenn die SQL-Aggregation (Punkt 4.1.1.2.3) für die entsprechende Stadt einen job_count von exakt Null ergibt.
4.1.3.3.2. Prüf-Reichweite: Der Abgleich erfolgt ausschließlich gegen den aktiven, nicht archivierten Lagerbestand (is_accepted = 1 AND is_archived = 0). Die bloße Verfügbarkeit von Aufträgen in der Frachtbörse (Marktpool) verhindert den Alarmstatus nicht.
4.1.3.4. Visuelle Spezifikation (Frontend)
4.1.3.4.1. Daten-Substitution: In der Spalte "Tonnage" wird bei Alarmzustand der numerische Wert durch den statischen Text-String FEHLT ersetzt.
4.1.3.4.2. Farbmetrik: Zuweisung der Warnfarbe #e74c3c (Signalrot) für den Text-String.
4.1.3.4.3. Typografie: Verwendung eines fetten Schriftschnitts (font-weight: 700) zur sofortigen visuellen Unterscheidung von regulären Bestandszahlen.
4.1.3.5. Implementierung in der View-Schicht (PHP)
4.1.3.5.1. Die Generierung erfolgt innerhalb der Render-Schleife der Sidebar durch eine binäre Entscheidung.
4.1.3.5.2. Logik-Ablauf:
4.1.3.5.2.1. Prüfung: Verfügt das Stadt-Objekt über aktive Lager-Jobs ($city->getJobCount() > 0)?
4.1.3.5.2.2. Ja-Zweig: Ausgabe des summierten Gewichts formatiert als Ganzzahl mit dem Suffix " t".
4.1.3.5.2.3. Nein-Zweig: Erzeugung eines HTML-Elements <span class="status-missing">FEHLT</span>.

4.1.4. Interaktion: Dynamische Sortierung der Strategie-Matrix
4.1.4.1. Funktionaler Zweck und Anwendungslogik
4.1.4.1.1. Optimierung der Datensichtung zur Identifikation logistischer Engpässe.
4.1.4.1.2. Fokus-Priorisierung: Ermöglichung der Gruppierung von Städten mit dem Status FEHLT (Punkt 4.1.3) am Kopf der Liste durch aufsteigende Sortierung der Spalte job_count.
4.1.4.2. Zustandsverwaltung via localStorage
4.1.4.2.1. Datenspeicherung: Die Parameter der aktiven Sortierung werden in den Schlüsseln tb_sidebar_sort_key und tb_sidebar_sort_dir im localStorage des Browsers persistiert.
4.1.4.2.2. Ereignis-Trigger: Bei jedem Klick auf eine Spaltenüberschrift wird der Speicherwert mittels JavaScript unmittelbar aktualisiert.
4.1.4.2.3. Lebenszyklus: Die gewählte Ansicht bleibt über Browsersitzungen und Systemneustarts hinweg erhalten.
4.1.4.3. Technische Umsetzung im Backend (SQL-Logik)
4.1.4.3.1. Schnittstellen-Definition: Die Übertragung der Sortierparameter erfolgt als AJAX-Request via GET-Parameter an einen dedizierten PHP-Endpunkt (z. B. api/get_sidebar_data.php).
4.1.4.3.2. Validierung: Die PHP-Logik prüft die eingehenden Werte gegen eine Whitelist (name, job_count, total_weight). Unbekannte Werte führen zum Fallback auf name.
4.1.4.3.3. Sortier-Hierarchie: Die SQL-Abfrage implementiert eine zweistufige ORDER BY-Klausel.
4.1.4.3.3.1. Bedingung A (Primärsortierung != name): Die Klausel lautet ORDER BY [key] [dir], name ASC.
4.1.4.3.3.2. Bedingung B (Primärsortierung == name): Die Klausel lautet ORDER BY name [dir].
4.1.4.3.4. Ergebnis: Dies garantiert eine stabile Darstellung, bei der Städte mit gleichen Werten (z. B. mehrere Städte mit 0 Jobs) immer alphabetisch sortiert bleiben.
4.1.4.4. Frontend-Interaktion (UI)
4.1.4.4.1. Steuerung: Die Tabellenköpfe (<th>) dienen als interaktive Elemente.
4.1.4.4.2. Logik der Richtungssteuerung: Ein Klick auf die bereits aktive Spalte invertiert die Richtung (ASC ↔ DESC). Ein Klick auf eine inaktive Spalte aktiviert diese und setzt die Richtung standardmäßig auf ASC.
4.1.4.4.3. Visuelle Indikatoren: Der aktive Sortierzustand wird durch CSS-Pseudoelemente (::after) an der jeweiligen Spalte angezeigt (z. B. ein High-Contrast-Pfeilsymbol).
4.1.4.5. Daten-Aktualisierung (AJAX-Workflow)
4.1.4.5.1. Asynchroner Austausch: Der AJAX-Request fordert ausschließlich das HTML-Fragment des Tabellenkörpers (<tbody>) an.
4.1.4.5.2. DOM-Manipulation: JavaScript ersetzt die bestehenden Zeilen. Dabei wird der Container der Sidebar (overflow-y: auto) nicht neu geladen, um die vertikale Scrollposition des Benutzers beizubehalten.

4.2. Fahrzeug-Tableau (Rechts): Zweck: Da die Anzahl der gleichzeitig annehmbaren Aufträge durch die Büroform (Baracke, Büro klein etc.) und die Anzahl der Disponenten limitiert ist, überwacht dieses Modul die "Mangelressource Slot". Es verhindert Fehlplanungen durch Überschreitung des Ingame-Limits und steuert die Verteilung freier Slots auf die zur Planung ausgewählten Fahrzeuge.
4.2.1. Kapazität: System-Skalierbarkeit für den Gesamt-Fuhrpark
4.2.1.1. Funktionale Zielsetzung der Skalierbarkeit
4.2.1.1.1. Gewährleistung einer flüssigen Benutzererfahrung und einer stabilen Interface-Darstellung, unabhängig von der Anzahl der im System registrierten Fahrzeuge (skalierbar über die initiale Planung von 60 LKW hinaus).
4.2.1.1.2. Vermeidung von Performance-Einbußen bei Massen-Datenoperationen durch effiziente Datenbank-Abfragen und optimiertes Frontend-Rendering.
4.2.1.2. Backend-Architektur und Datenbeschaffung (SQL-Logik)
4.2.1.2.1. Vermeidung von N+1-Abfragen: Das Laden der Fahrzeugliste erfolgt über eine einzige, zentrale SQL-Abfrage unter Nutzung von JOIN-Operationen.
4.2.1.2.2. Query-Struktur: Einsatz eines LEFT JOIN zur Tabelle drivers (Punkt 2.3) und zur Tabelle cities (Punkt 2.1).
4.2.1.2.3. Daten-Aggregation: In der gleichen Abfrage wird die Anzahl der aktuell zugewiesenen Jobs (COUNT(orders.id)) über einen weiteren LEFT JOIN zur Tabelle orders (Punkt 2.5) ermittelt.
4.2.1.2.4. Index-Nutzung: Die Abfrage nutzt die in Punkt 1.2.5 definierten Fremdschlüssel-Indizes, um die Ausführungszeit im Millisekundenbereich zu halten.
4.2.1.3. Frontend-Layout (CSS-Grid-Spezifikation)
4.2.1.3.1. Container-Modell: Der Hauptbereich für die Fahrzeug-Karten wird als CSS Grid definiert.
4.2.1.3.2. Responsive Spalten-Logik: Verwendung von grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)). Dies erlaubt die automatische Anpassung der Spaltenanzahl an die verfügbare Bildschirmbreite, ohne das Layout zu sprengen.
4.2.1.3.3. Abstands-Management: Definition eines festen grid-gap (z. B. 15px), um die Trennung der Karten bei hoher Dichte optisch sauber zu halten.
4.2.1.4. DOM-Performance und Rendering-Strategie
4.2.1.4.1. Serverseitige Generierung: PHP generiert die Kartenstruktur als statisches HTML-Fragment, was die Initialisierungszeit im Browser minimiert.
4.2.1.4.2. Minimalistischer DOM: Jede Fahrzeug-Karte wird mit einer flachen HTML-Hierarchie gerendert, um den Speicherbedarf des Browsers bei 60+ Instanzen gering zu halten.
4.2.1.4.3. Lazy-Loading für Details: Umfangreiche Daten wie die Vorschlagslisten (Punkt 4.2.6) werden erst beim Fokussieren eines Fahrzeugs generiert oder eingeblendet, um das initiale Rendering nicht zu verzögern.
4.2.1.5. Ressourcenschonung
4.2.1.5.1. Assets: Die Darstellung verzichtet auf komplexe Bilddateien pro Fahrzeug. Alle Indikatoren (ADR-Badges, Status-Icons) werden ausschließlich über CSS oder ressourcenschonende SVG-Vektorgrafiken realisiert.
4.2.1.5.2. Skalierbarer Viewport: Der Container des Tableaus erhält ein overflow-y: auto, wodurch die Navigation durch den Fuhrpark über das Standard-Scrolling des Betriebssystems erfolgt (Performance-Vorteil gegenüber komplexen Custom-Scrollern).

4.2.2. Header: Name, Fahrzeugtyp, Kapazität, Anzahl eingeplanter Jobs, virtuelles Tourende.
4.2.2.1.1. Zentrale Identifikationsebene für jedes Fahrzeug innerhalb des Fuhrpark-Tableaus.
4.2.2.1.2. Aggregation der kritischen Kennzahlen zur sofortigen Beurteilung der Fahrzeugauslastung und des aktuellen Standorts ohne Interaktionszwang.
4.2.2.2. Datenbeschaffung und Feld-Mapping
4.2.2.2.1. Name des Fahrers: Abfrage aus drivers.first_name und drivers.last_name. Falls kein Fahrer zugewiesen ist (Punkt 2.3.3.1), erfolgt die Ausgabe des Platzhalters "UNBESETZT" in Warnfarbe (#e74c3c).
4.2.2.2.2. Fahrzeugtyp: Abfrage aus trucks.vehicle_type.
4.2.2.2.3. Kapazität: Abfrage aus trucks.capacity_t unter Anfügung des Suffixes " t".
4.2.2.2.4. Anzahl Jobs: Dynamische SQL-Aggregation COUNT(orders.id) für alle Aufträge mit is_archived = 0, die der technischen trucks.id zugewiesen sind.
4.2.2.2.5. Virtuelles Tourende: Identifikation des Zielortes (cities.name) des zeitlich letzten Auftrags (höchster Wert in orders.assigned_at).
4.2.2.3. Logik des virtuellen Tourendes und Status-Indikation
4.2.2.3.1. Ermittlungs-Algorithmus: Das System prüft die Existenz zugewiesener Aufträge für das Fahrzeug.
4.2.2.3.2. Fall A (Tour vorhanden): Das Tourende entspricht dem Attribut to_city_id des Auftrags mit dem jüngsten assigned_at-Zeitstempel.
4.2.2.3.3. Fall B (Keine Tour vorhanden): Das Tourende entspricht dem physischen Standort des Fahrzeugs (trucks.current_city_id).
4.2.2.3.4. Visuelle Differenzierung Fall A (Aktiv): Verfügt das Fahrzeug über größergleich 1 zugewiesene Aufträge, wird das Tourende durch das Icon ➔ (En Route) eingeleitet. Die Stadtbezeichnung wird in Akzentfarbe (#f39c12) dargestellt.
4.2.2.3.5. Visuelle Differenzierung Fall B (Stationär): Verfügt das Fahrzeug über 0 Aufträge, wird das Tourende durch das Icon 📍 (Location Pin) oder das Präfix "POS:" eingeleitet. Die Stadtbezeichnung wird in einem neutralen Blau-Ton (#3498db) dargestellt, um zu signalisieren, dass der LKW dort aktuell steht und keine Bewegung geplant ist.

4.2.2.4. UI-Spezifikation und Lesbarkeits-Standards
4.2.2.4.1. Typografie: Strikte Verwendung einer normalen, gut lesbaren Schriftgröße (Standard: 14px) für alle Header-Informationen. Die Verwendung von Schriften unter 11px oder kontrastarmen Grautönen (heller als #aaaaaa auf dunklem Grund) ist für Stamm-Informationen untersagt.
4.2.2.4.2. Farbkontrast: Fahrername und Tourende werden in Hochkontrast-Farben (#ffffff oder #f39c12) ausgegeben.
4.2.2.4.3. Badge-System: Die Job-Anzahl wird in ein visuell abgesetztes Element (.badge-count) gekapselt, welches bei einer Anzahl von 0 farblich neutral (Grau) und bei > 0 in Akzentfarbe (Orange) erscheint.
4.2.2.5. Layout-Struktur innerhalb der Karte
4.2.2.5.1. Flexbox-Modell: Der Header wird als display: flex mit justify-content: space-between definiert.
4.2.2.5.2. Linke Sektion: Gruppierung von Fahrername, Fahrzeugtyp und Kapazität zur schnellen Identifikation der Kapazitätseinheit.
4.2.2.5.3. Rechte Sektion: Gruppierung der dynamischen Statuswerte (Job-Count und Tourende) zur Überwachung des operativen Fortschritts.
4.2.2.5.4. Trennung: Ein horizontaler Separator (border-bottom) mit 1px Stärke grenzt den Header optisch vom Fahrplan-Bereich (Punkt 4.3) ab.

4.2.3. UI-Standards und Barrierefreiheit (WCAG-Konformität)
4.2.3.1. Einhaltung der WCAG 2.1 AA Richtlinien
4.2.3.1.1. Kontrastverhältnisse: Sämtliche Text-Hintergrund-Kombinationen im Header müssen ein Kontrastverhältnis von mindestens 4.5:1 aufweisen. Dies gilt insbesondere für Status-Badges und Fahrernamen.
4.2.3.1.2. Schriftgrößen-Standard: Die Basisschriftgröße für den Header beträgt 14px (0.875rem). Eine Unterschreitung von 12px für ergänzende Informationen (z.B. Fahrzeug-ID) ist untersagt, um die Lesbarkeit unter suboptimalen Lichtbedingungen (Dark Mode) zu gewährleisten.
4.2.3.1.3. Skalierbarkeit: Das Layout muss so aufgebaut sein, dass Texte bei einer Browser-Zoom-Stufe von bis zu 200% nicht überlappen oder abgeschnitten werden (Flexible Box/Grid-Container).
4.2.3.2. Visuelle Hierarchie und Typografie
4.2.3.2.1. Primär-Informationen: Fahrername und Fahrzeug-Status (Jobs/Tour) erhalten die höchste visuelle Gewichtung durch einen Schriftschnitt von 600 (Semi-Bold).
4.2.3.2.2. Sekundär-Informationen: Fahrzeugtyp und Kapazität werden in regulärem Schriftschnitt (400) ausgegeben, jedoch unter strikter Einhaltung des Farbkontrasts gemäß 4.2.3.1.1.

4.2.4. Sortierung: Automatische Priorisierung nach Auslastungsgrad und Topologie
4.2.4.1. Funktionale Zielsetzung der Sortierlogik
4.2.4.1.1. Dynamische Re-Priorisierung: Unmittelbar nach der Zuweisung eines Auftrags (load_job) rücken alle Fahrzeuge mit einer geringeren Anzahl geplanter Stopps in der Listen-Hierarchie nach oben.
4.2.4.1.2. Workflow-Erhalt durch Scroll-Korrektur: Das System erzwingt die visuelle Immobilität der fokussierten Fahrzeugkarte (is_focussed = TRUE). Jede durch die Neusortierung bedingte Verschiebung von Elementen oberhalb des Fokus-Punkts wird durch eine automatische Anpassung des Scroll-Offsets kompensiert.
4.2.4.2. Datenermittlung und Aggregation (Backend)
4.2.4.2.1. Abfragemethode: Einsatz eines LEFT JOIN der Tabelle trucks auf die Tabelle orders (gefiltert auf is_archived = 0).
4.2.4.2.2. Aggregation: Nutzung von COUNT(orders.id) gruppiert nach trucks.id zur Ermittlung des Alias job_count.
4.2.4.2.3. Performance: Da 60 LKW und potenziell hunderte Aufträge verarbeitet werden, nutzt der LEFT JOIN den Index auf assigned_truck_id (Punkt 2.5.4.1), um die Serverantwortzeit unter 50ms zu halten.
4.2.4.3. Mehrstufige, definierte Sortierhierarchie
4.2.4.3.1. Primärfaktor (Auslastung): Sortierung nach job_count ASC. Fahrzeuge, die Arbeit benötigen, führen die Liste an.
4.2.4.3.2. Sekundärfaktor (Geografie): Bei identischer Job-Anzahl erfolgt eine alphabetische Sortierung nach dem Namen des aktuellen Standorts (cities.name ASC). Dies erlaubt dem Disponenten, Fahrzeuge im gleichen Einsatzgebiet gruppiert zu sichten.
4.2.4.3.3. Tertiärfaktor (Leistung): Bei gleicher Job-Anzahl und gleichem Standort wird nach trucks.capacity_t DESC sortiert, um die stärksten Kapazitäten bevorzugt anzubieten.
4.2.4.4. UI-Synchronisation und Fokus-Erhalt
4.2.4.4.1. Schritt 1 (Pre-Update): JavaScript ermittelt mittels getBoundingClientRect().top die exakte vertikale Position der fokussierten Karte relativ zum Viewport vor der DOM-Manipulation.
4.2.4.4.2. Schritt 2 (Update): Die sortierte Fahrzeugliste wird vom Server via AJAX geladen und in das Dashboard injiziert (Injektion in den Container #fleet-board).
4.2.4.4.3. Schritt 3 (Post-Update): JavaScript berechnet die neue Position der fokussierten Karte.
4.2.4.4.4. Schritt 4 (Korrektur): Die Differenz (Delta) zwischen alter und neuer Position wird berechnet. Das System führt einen sofortigen window.scrollBy(0, delta) aus.
4.2.4.4.5. Ergebnis: Für das menschliche Auge bleibt die aktive Karte an der identischen Bildschirmposition fixiert, während die restliche Liste im Hintergrund unmerklich verschoben wird.
4.2.4.5. Technische SQL-Umsetzung (Reference)
4.2.4.5.1. Definition der Persistenz-Schnittstelle: Das Truck-Repository muss eine Methode zur Verfügung stellen, die ein konsolidiertes Resultset liefert, welches sämtliche für das Tableau (Punkt 4.2) erforderlichen Datenfelder in einer einzigen Transaktion zusammenfasst.
4.2.4.5.2. Spezifikation der Join-Hierarchie:
4.2.4.5.2.1. Basis-Entität: Die Tabelle trucks bildet den Ausgangspunkt der Abfrage.
4.2.4.5.2.2. Standort-Relation: Ein LEFT JOIN zur Tabelle cities ist zwingend, um den Klarnamen des physischen Standorts (location_name) für die geografische Sortierung aufzulösen.
4.2.4.5.2.3. Auftrags-Relation: Ein LEFT JOIN zur Tabelle orders stellt die Basis für die Auslastungsberechnung dar.
4.2.4.5.3. Anforderungen an die Aggregations-Logik:
4.2.4.5.3.1. Filter-Zwang: Der Join zur Auftragstabelle muss innerhalb der Join-Bedingung (ON) oder einer Subquery strikt auf Datensätze mit is_archived = 0 begrenzt werden.
4.2.4.5.3.2. Null-Werte-Behandlung: Das System muss sicherstellen, dass LKWs ohne zugewiesene Aufträge durch die Aggregatfunktion COUNT() den numerischen Wert 0 erhalten, um die Sortierpriorität nach Punkt 4.2.4.3.1 zu wahren.
4.2.4.5.4. Implementierung der Sortier-Instruktion:
4.2.4.5.4.1. Die SQL-Anweisung muss die unter Punkt 4.2.4.3 definierte Hierarchie (Auslastung, Geografie, Leistung) zwingend als hartkodierte ORDER BY-Klausel enthalten.
4.2.4.5.4.2. Sekundär-Sortierung: Die alphabetische Sortierung nach Städtenamen muss auf dem aufgelösten Klarnamen der Tabelle cities basieren, nicht auf der technischen ID.
4.2.4.5.5. Anforderungen an die Daten-Mapping-Schicht (PHP Model):
4.2.4.5.5.1. Das Resultset der SQL-Abfrage muss die berechnete Job-Anzahl als virtuelles Attribut (Alias) an das Truck-Objekt übergeben.
4.2.4.5.5.2. Dieses Alias-Feld dient als Datenquelle für alle logischen Prüfungen im Frontend sowie für die Synchronisations-Logik des Focus-Systems (Punkt 4.2.4.4).

4.2.5. Selektion: Checkbox "AKTIV" zur Einbeziehung in die Tagesplanung
4.2.5.1. Funktionaler Zweck und Steuerungs-Logik
4.2.5.1.1. Ermöglichung einer selektiven Tourenplanung: Der Disponent entscheidet pro Fahrzeug, ob es für die automatische Generierung von Auftrags-Ketten (Punkt 4.4) berücksichtigt werden soll.
4.2.5.1.2. Ausschluss-Prinzip: Inaktive Fahrzeuge verbleiben rein informativ in der Liste, werden jedoch von den rechenintensiven Routing-Operationen der Topology-Engine (Punkt 6) vollständig ignoriert.
4.2.5.2. Datenhaltung und Persistenz (Backend)
4.2.5.2.1. Mapping: Die Selektion wird direkt auf die Spalte trucks.is_active_planning (Punkt 2.4.3.1) abgebildet.
4.2.5.2.2. SQL-Transaktion: Jede Statusänderung im Frontend muss eine unmittelbare UPDATE-Operation in der Datenbank auslösen, um die Konsistenz über verschiedene Sitzungen und Browser-Tabs hinweg zu gewährleisten.
4.2.5.3. Interaktions-Design (Frontend-Worklow)
4.2.5.3.1. UI-Element: Platzierung eines Checkbox-Eingabefeldes im Header der Fahrzeug-Karte (Punkt 4.2.2). Das Label trägt die Bezeichnung "AKTIV".
4.2.5.3.2. Ereignis-Steuerung: Die Checkbox ist an einen asynchronen JavaScript-Event-Listener (change-Event) gebunden.
4.2.5.3.3. AJAX-Schnittstelle: Bei Betätigung sendet das Frontend einen POST-Request an den Endpunkt api/truck_status.php. Payload: truck_id und state (0 oder 1).
4.2.5.3.4. Fehlerbehandlung: Bleibt die Bestätigung des Servers aus, muss die Checkbox über einen Rollback-Mechanismus in den Ursprungszustand zurückversetzt werden, begleitet von einer visuellen Fehlermeldung.
4.2.5.4. Visuelle Repräsentation und Status-Feedback
4.2.5.4.1. Card-Styling: Fahrzeuge mit dem Status is_active_planning = TRUE erhalten eine spezifische CSS-Klasse .truck-card-selected.
4.2.5.4.2. Akzentuierung: Diese Klasse aktiviert einen farbigen Randindikator an der linken Seite der Karte (border-left: 5px solid #27ae60) zur sofortigen Unterscheidung von inaktiven Fahrzeugen.
4.2.5.4.3. Deaktivierungs-Optik: Inaktive Karten werden mittels CSS (filter: opacity(0.6)) visuell leicht zurückgenommen, um den Fokus auf die zu disponierenden Einheiten zu lenken.
4.2.5.5. Integration in die Verarbeitungs-Pipeline
4.2.5.5.1. Filter-Zwang: Sämtliche Methoden der Klasse TourOptimizer müssen bei der Initialisierung des Such-Pools zwingend die Bedingung WHERE is_active_planning = 1 anwenden.
4.2.5.5.2. Massen-Aktionen: Bereitstellung von zwei globalen Steuerungs-Buttons im Board-Header: "ALLE AKTIVIEREN" und "ALLE DEAKTIVIEREN", welche eine Batch-Update-Query auf die gesamte Tabelle trucks ausführen.

4.2.6. Fokus-System: Interaktive Aktivierung der Vorschlagsliste
4.2.6.1. Funktionale Zielsetzung und Abgrenzung
4.2.6.1.1. Steuerung der Informationsdichte: Die detaillierte Liste der Auftrags-Vorschläge (nach dem Topology-Algorithmus aus Punkt 6) wird exklusiv für das aktuell fokussierte Fahrzeug eingeblendet.
4.2.6.1.2. Funktionale Trennung (Kollisionsschutz): Die Sichtbarkeit der Vorschlagsliste ist technisch strikt von der Sichtbarkeit des Fahrplans (Punkt 4.2.7 / 4.3) getrennt. Während der Fahrplan (bereits geladene Jobs) über die globale Klapp-Logik gesteuert wird, unterliegt die Vorschlagsliste (potenzielle neue Jobs) ausschließlich dem Fokus-Status.
4.2.6.2. Logik der Exklusivität (Single-Focus-Prinzip)
4.2.6.2.1. Zustands-Regel: Es kann systemweit zu jedem Zeitpunkt nur exakt ein Fahrzeug den Status is_focussed = TRUE besitzen (Singleton-Muster auf Datenbankebene).
4.2.6.2.2. Umschalt-Logik: Durch den Klick auf eine Fahrzeugkarte (Trigger-Bereich: Header oder freie Kartenfläche) wird der Fokus des zuvor aktiven Fahrzeugs automatisch entzogen und auf das neue Fahrzeug übertragen.
4.2.6.3. Datenhaltung und Persistenz (Backend)
4.2.6.3.1. Datenbank-Update: Jede Fokus-Änderung triggert eine SQL-Transaktion, die in der Tabelle trucks alle Felder is_focussed auf FALSE setzt und anschließend den gewählten Datensatz auf TRUE aktualisiert.
4.2.6.3.2. Session-Synchronität: Durch die Speicherung in der SQL-Datenbank (Punkt 2.4.3.2) bleibt das aktuell bearbeitete Fahrzeug auch nach einem Browser-Refresh oder Modul-Wechsel im Fokus.
4.2.6.4. Technische UI-Umsetzung (Frontend)
4.2.6.4.1. Trigger-Spezifikation: Der Event-Listener für den Fokus-Klick wird mittels Event-Delegation auf dem Tableau-Container implementiert. Klicks auf Unterelemente wie Checkboxen (Punkt 4.2.5) oder Unload-Buttons (Punkt 4.3.4) lösen keinen Fokus-Wechsel aus (stopPropagation).
4.2.6.4.2. Container-Sichtbarkeit: Die Sektion .proposal-area (Vorschlagsliste) wird nur gerendert oder mittels CSS display: block eingeblendet, wenn das Attribut is_focussed des Objekts TRUE ist.
4.2.6.4.3. Visuelle Markierung: Die fokussierte Karte erhält die CSS-Klasse .truck-card-focussed. Diese aktiviert eine visuelle Hervorhebung (z.B. eine Randbetonung in Orange #f39c12), um das aktive Arbeitsfeld zu kennzeichnen.
4.2.6.5. Interaktion mit dem Algorithmus
4.2.6.5.1. On-Demand Berechnung: Um Server-Ressourcen zu schonen, wird die rechenintensive Suche im 3-Städte-Radius (Punkt 6.1) primär für das fokussierte Fahrzeug durchgeführt.
4.2.6.5.2. AJAX-Injektion: Beim Fokus-Wechsel wird die Vorschlagsliste asynchron via AJAX nachgeladen und in die Karte injiziert, ohne das restliche Tableau neu zu rendern.

4.2.7. Interaktive Ansichtssteuerung - Wegklappbarkeit der Tourplanung
4.2.7.1. Funktionale Zielsetzung der Sektions-Steuerung
4.2.7.1.1. Platzökonomie: Reduzierung der vertikalen Ausdehnung des Tableaus bei großen Fuhrparks (bis zu 60 LKW) durch selektives Verbergen der Touren-Details.
4.2.7.1.2. Informationstrennung: Ermöglichung der Konzentration auf die reine Flottenübersicht (Header-Daten) ohne Ablenkung durch komplexe Fahrpläne.
4.2.7.1.3. Unabhängigkeits-Garantie: Die Steuerung der Sichtbarkeit der Tourplanung (4.2.7) erfolgt technisch vollkommen autark von der Sichtbarkeit der Vorschlagsliste (4.2.6). Das Ein- oder Ausklappen einer Tour beeinflusst nicht den Fokus-Status eines Fahrzeugs.
4.2.7.2. Technische Umsetzung der Sichtbarkeit (CSS-Logik)
4.2.7.2.1. Container-Kapselung: Die Liste der eingeplanten Aufträge wird in ein <div>-Element mit der Klasse .tour-plan-container eingeschlossen.
4.2.7.2.2. Zustands-Klasse: Die Sichtbarkeit wird über die CSS-Klasse .is-collapsed am übergeordneten Fahrzeug-Card-Element gesteuert.
4.2.7.2.3. Animation: Einsatz von transition: max-height 0.3s ease-out und overflow: hidden zur Gewährleistung eines flüssigen Benutzererlebnisses beim Auf- und Zuklappen.
4.2.7.3. Interaktions-Design (Frontend-Events)
4.2.7.3.1. Individueller Trigger: Platzierung eines Buttons im Header (PH 4.2.2) mit der Beschriftung "Tour ±" oder einem korrespondierenden Icon.
4.2.7.3.2. Event-Handler: Ein JavaScript-Listener fängt den Klick ab, verhindert die Weitergabe an das Fokus-System (via event.stopPropagation()) und toggelt die Klasse .is-collapsed.
4.2.7.4. Globale Steuerung (Massen-Operation)
4.2.7.4.1. Master-Interface: Im Kopfbereich des gesamten Dispatcher-Boards wird eine Schaltfläche "ALLE TOUREN EINKLAPPEN/AUSKLAPPEN" implementiert.
4.2.7.4.2. Batch-Verarbeitung: Ein JavaScript-Prozess traversiert bei Klick alle DOM-Elemente des Typs .truck-card und setzt deren Klapp-Zustand uniform auf den gewünschten Zielwert.
4.2.7.5. Persistenz-Modell (LocalStorage)
4.2.7.5.1. Datenstruktur: Die Zustände werden im localStorage unter dem Schlüssel tb_ui_tour_visibility als JSON-Objekt persistiert.
4.2.7.5.2. Schlüssel-Wert-Paarung: Das Objekt nutzt die ingame_vehicle_id (PH 2.4.1.2) als Key und einen Boolean als Value (z.B. { "10616810": true, "10619039": false }).
4.2.7.5.3. Wiederherstellungs-Logik: Beim Initial-Rendering der Seite (window.onload) liest das Frontend diesen Speicher aus und weist den Karten-Containern die entsprechenden CSS-Klassen zu, bevor das erste Layout-Painting durch den Browser erfolgt.

4.3. Fahrplan-Logik (Dispatcher-Operations): Zweck: Das Board ist die visuelle Repräsentation der Flotte. Es bietet eine hochverdichtete Informationsdarstellung für alle LKW. Durch ein Fokus-System wird sichergestellt, dass trotz der großen Fahrzeuganzahl eine übersichtliche und zielgerichtete Planung für einzelne Einheiten möglich bleibt.

4.3.1. Chronologie: Tabellarische Auflistung der Tour-Etappen
4.3.1.1. Funktionale Zielsetzung:
4.3.1.1.1. Lückenlose Rekonstruktion der geplanten Transportkette eines Fahrzeugs in der exakten Reihenfolge ihrer Zuweisung.
4.3.1.1.2. Bereitstellung einer detaillierten Entscheidungsgrundlage für den Disponenten zur Prüfung der geografischen Plausibilität einer Tour.
4.3.1.2. Datenbeschaffung und Sortier-Algorithmus (Backend):
4.3.1.2.1. Abfrage-Spezifikation: Das System selektiert alle Datensätze aus der Tabelle orders, deren assigned_truck_id der technischen ID des aktuellen Fahrzeugs entspricht.
4.3.1.2.2. Filter-Zwang: Berücksichtigung ausschließlich aktiver Aufträge (is_archived = 0).
4.3.1.2.3. Chronologische Integrität: Die Sortierung erfolgt zwingend über die Spalte assigned_at ASC (PH 2.5.4.3). Dies stellt sicher, dass die Kette in der Reihenfolge abgebildet wird, in der der Disponent die Aufträge "geladen" hat.
4.3.1.3. Struktur der tabellarischen Darstellung (Frontend):
4.3.1.3.1. Element-Wahl: Einsatz eines <table>-Elements mit der Klasse .tour-table innerhalb des einklappbaren Containers (PH 4.2.7.2.1).
4.3.1.3.2. Spalten-Definition:
4.3.1.3.2.1. Spalte 1 (Modus): Visuelle Kennung der Etappenart (LEER/JOB).
4.3.1.3.2.2. Spalte 2 (Route): Startort ➔ Zielort als Klarnamen aus Tabelle cities.
4.3.1.3.2.3. Spalte 3 (Distanz): Wegstrecke der Etappe in km.
4.3.1.3.2.4. Spalte 4 (Details): Kombination aus Tonnage und Frachttyp.
4.3.1.3.2.5. Spalte 5 (Wirtschaftlichkeit): Netto-Erlös der Etappe (formatiert nach PH 3.1.3).
4.3.1.4. Logik der virtuellen Startpunkt-Berechnung:
4.3.1.4.1. Anker-Punkt: Für die erste Etappe einer Tour dient der Wert trucks.current_city_id als physischer Startort.
4.3.1.4.2. Ketten-Fortschreibung: Für jede nachfolgende Etappe n dient der Zielort (to_city_id) der vorhergehenden Etappe n−1 als Startpunkt.
4.3.1.5. Interaktives Daten-Rendering (AJAX/PHP):
4.3.1.5.1. On-Demand-Update: Bei jeder Zuweisung eines Auftrags (load_job) oder dem Entfernen (unload_job) wird das HTML-Fragment der Tabelle serverseitig neu generiert und mittels JavaScript in den Container injiziert.
4.3.1.5.2. Summen-Logik: Am Ende der Tabelle wird eine Fußzeile (<tfoot>) ausgegeben, welche die kumulierten Werte für Gesamt-KM und Gesamt-Erlös der aktuellen Tour berechnet und anzeigt.

4.3.2. Typisierung: Kennzeichnung LEERFAHRT (Anfahrt) vs. JOB (Fracht)
4.3.2.1. Funktionaler Zweck der Differenzierung
4.3.2.1.1. Transparenz-Steigerung: Ermöglichung einer präzisen Unterscheidung zwischen wertschöpfenden Transportkilometern (Last-km) und unproduktiven Bereitstellungskilometern (Leer-km).
4.3.2.1.2. Ökonomische Analyse: Bereitstellung der Datengrundlage für die Berechnung der realen Marge, bei der die Kosten der Anfahrt dem Erlös des Auftrags gegenübergestellt werden.
4.3.2.2. Logik der virtuellen Etappengenerierung (Backend-Algorithmus)
4.3.2.2.1. Dynamische Berechnung: Da Leerfahrten keine eigenständigen Datensätze in der Tabelle orders sind, werden sie während der Laufzeit des Rendering-Prozesses durch die Klasse TourCalculator erzeugt.
4.3.2.2.2. Iterations-Verfahren: Das System durchläuft die Liste der zugewiesenen Aufträge (sortiert nach assigned_at).
4.3.2.2.3. Vergleichs-Anker: Für jedes Element n wird geprüft, ob dessen Startort (from_city_id) identisch mit dem Endpunkt des Elements n−1 ist.
4.3.2.2.4. Trigger-Bedingung: Ergibt der Vergleich eine Ungleichheit der Stadt-IDs, generiert der Algorithmus unmittelbar vor dem Auftrag n eine zusätzliche virtuelle Zeile des Typs LEERFAHRT.
4.3.2.3. Spezifikation der Etappen-Typen
4.3.2.3.1. Typ LEERFAHRT (Virtual Leg):
4.3.2.3.1.1. Herkunft: Automatisch generiert bei geografischer Diskrepanz zwischen zwei Standorten.
4.3.2.3.1.2. Erlös-Attribut: Fest definiert auf den Wert 0.00 (Punkt 3.1.3.5.2).
4.3.2.3.1.3. Distanz-Attribut: Der Wert wird mittels DistanceService::getDistance() zwischen dem aktuellen Ankerpunkt und dem Auftrags-Startort ermittelt.
4.3.2.3.2. Typ JOB (Cargo Leg):
4.3.2.3.2.1. Herkunft: Physischer Datensatz aus der Tabelle orders.
4.3.2.3.2.2. Erlös-Attribut: Der in der Spalte revenue hinterlegte Betrag.
4.3.2.3.2.3. Distanz-Attribut: Die Wegstrecke zwischen from_city_id und to_city_id.
4.3.2.4. Visuelle Differenzierung im Frontend (CSS)
4.3.2.4.1. Semantisches Farbschema:
4.3.2.4.1.1. Zeilen des Typs LEERFAHRT erhalten die CSS-Klasse .row-type-empty (Hintergrund: Dezentes Warn-Rot rgba(231, 76, 60, 0.1), Textfarbe: Gedimmtes Grau #888888).
4.3.2.4.1.2. Zeilen des Typs JOB erhalten die CSS-Klasse .row-type-cargo (Hintergrund: Dezentes Erfolgs-Grün rgba(46, 204, 113, 0.1), Textfarbe: Standard-Weiß #ffffff).
4.3.2.4.2. Icon-Indikation: Zur schnellen Erfassung wird der Typ zusätzlich durch ein Label (z.B. "LEER" vs. "FRACHT") in der ersten Spalte der Tabelle (Punkt 4.3.1.3.2.1) gekennzeichnet.
4.3.2.5. Daten-Integrität bei Tour-Änderungen
4.3.2.5.1. Flüchtigkeit der Leerfahrt: Wird ein Auftrag aus dem Fahrplan entfernt (unload_job), löscht das System automatisch die damit verknüpfte virtuelle Leerfahrt-Zeile, da die Vergleichslogik (Punkt 4.3.2.2.3) beim nächsten Rendering-Zyklus keine Diskrepanz mehr feststellt.

4.3.3. Status: Kennzeichnung RESERVIERT (Lager) vs. VERFÜGBAR (Markt/Börse)
4.3.3.1. Funktionaler Zweck der Status-Differenzierung
4.3.3.1.1. Administrative Kontrolle: Eindeutige Kennzeichnung von Aufträgen, die sich bereits im rechtlichen Besitz der Spedition befinden (Lager), gegenüber unverbindlichen Angeboten aus der Frachtbörse (Marktpool).
4.3.3.1.2. Entscheidungsunterstützung: Visualisierung der Slot-Belastung innerhalb einer geplanten Kette zur Vermeidung von Kapazitätsengpässen bei den Disponenten (Punkt 4.2).
4.3.3.2. Datenbasis und Attribut-Mapping (SQL)
4.3.3.2.1. Status-Indikator: Die Grundlage bildet die Spalte orders.is_accepted (Punkt 2.5.3.3).
4.3.3.2.2. Zustand TRUE (1): Definition als Status RESERVIERT. Dieser Auftrag belegt bereits einen Disponenten-Slot und ist gegen Zugriff durch Konkurrenten im Spiel gesichert.
4.3.3.2.3. Zustand FALSE (0): Definition als Status VERFÜGBAR. Dieser Auftrag stammt aus dem Markt-Import und muss bei Realisierung der Planung erst im Spiel-Interface angenommen werden.
4.3.3.3. Visuelle Repräsentation (Badge-System)
4.3.3.3.1. Komponentendesign: Einsatz von kompakten Label-Elementen (<span>) mit spezifischen CSS-Klassen zur sofortigen Farberkennung.
4.3.3.3.2. Status RESERVIERT (Lager):
4.3.3.3.2.1. CSS-Klasse: .badge-status-reserved.
4.3.3.3.2.2. Farbmetrik: Hintergrundfarbe #3498db (Informations-Blau), Textfarbe #ffffff.
4.3.3.3.2.3. Label-Text: "LAGER" oder "RESERVIERT".
4.3.3.3.3. Status VERFÜGBAR (Markt):
4.3.3.3.3.1. CSS-Klasse: .badge-status-market.
4.3.3.3.3.2. Farbmetrik: Hintergrundfarbe #e67e22 (Börsen-Orange), Textfarbe #000000.
4.3.3.3.3.3. Label-Text: "BÖRSE" oder "VERFÜGBAR".
4.3.3.4. Logische Verknüpfung im Dispatcher-Board
4.3.3.4.1. Sichtbarkeit im Fahrplan: In der Tour-Tabelle (Punkt 4.3.1) wird das entsprechende Badge in jeder Zeile des Typs JOB (Punkt 4.3.2.3.2) ausgegeben.
4.3.3.4.2. Sichtbarkeit in der Vorschlagsliste: Bei der Anzeige potenzieller Anschlussfrachten (Punkt 4.2.6) dient das Status-Badge als primärer Indikator für den Handlungsbedarf (Sofortige Annahme in TB notwendig?).
4.3.3.5. Technische Umsetzung in der View-Schicht (PHP OOP)
4.3.3.5.1. Implementierung: Die Klasse OrderViewHelper stellt die öffentliche Methode public function renderStatusBadge(Order $order): string bereit.
4.3.3.5.2. Logik-Ablauf: Die Methode evaluiert das Attribut is_accepted und gibt den fertig formatierten HTML-String des jeweiligen Badges zurück.
4.3.3.5.3. Interaktions-Hinweis: Für Markt-Aufträge wird dem Badge ein title-Attribut angefügt: "Achtung: Dieser Auftrag ist noch nicht reserviert und verbraucht bei Annahme einen Disponenten-Slot."

4.3.4. Details: Anzeige von Start ➔ Ziel, Distanz, Tonnage und Erlös
4.3.4.1. Funktionaler Zweck der Detail-Transparenz
4.3.4.1.1. Bereitstellung aller für die Durchführung des Transports im Spiel notwendigen Parameter innerhalb einer Tabellenzeile.
4.3.4.1.2. Ermöglichung der visuellen Kontrolle der Transportkette zur Vermeidung von Fehlbeladungen oder geografischen Unstimmigkeiten.
4.3.4.2. Spezifikation der Routen-Darstellung (Start ➔ Ziel)
4.3.4.2.1. Daten-Extraktion: Die Ortsbezeichnungen werden über die in der Tabelle orders gespeicherten IDs (from_city_id, to_city_id) aus der Tabelle cities aufgelöst.
4.3.4.2.2. Visuelle Komponente: Darstellung der Städte als fettgesetzte Textwerte. Zwischen Start- und Zielort wird das Unicode-Symbol ➔ (U+2794) als gerichteter Separator platziert.
4.3.4.2.3. Kontext-Anpassung: Bei Etappen des Typs LEERFAHRT (Punkt 4.3.2.3.1) wird die Route automatisiert aus dem aktuellen Standort des LKWs (bzw. dem Zielort der Vor-Etappe) und dem Startort des nächsten Auftrags generiert.
4.3.4.3. Spezifikation der Distanz-Anzeige
4.3.4.3.1. Datenquelle: Auslesen des Attributs distance_km (bei LEERFAHRT) oder der Distanz-Metrik des Auftragsdatensatzes.
4.3.4.3.2. Formatierung: Ausgabe als Ganzzahl, gefolgt von einem geschützten Leerzeichen und der Maßeinheit km.
4.3.4.3.3. Ausrichtung: Zentrierte oder rechtsbündige Formatierung zur Wahrung der tabellarischen Integrität.
4.3.4.4. Spezifikation der Tonnage-Anzeige
4.3.4.4.1. Datenquelle: Abfrage der Spalte orders.weight_total (Punkt 2.5.2.1).
4.3.4.4.2. Formatierung: Ausgabe des numerischen Wertes mit dem Suffix t.
4.3.4.4.3. Sonderfall LEERFAHRT: In Zeilen des Typs Leerfahrt wird in dieser Spalte ein Bindestrich (-) oder der Wert 0 t ausgegeben, um die Abwesenheit von Frachtgut zu kennzeichnen.
4.3.4.5. Spezifikation der Erlös-Anzeige
4.3.4.5.1. Datenquelle: Abfrage der Spalte orders.revenue (Punkt 2.5.2.3).
4.3.4.5.2. Formatierung: Strikte Anwendung der 3-Stufen-Geldlogik gemäß Punkt 3.1.3 (Deutsches Format mit Währungssymbol).
4.3.4.5.3. Layout-Vorgabe: Rechtsbündige Ausrichtung innerhalb der Tabellenzelle. Bei Leerfahrten erfolgt die Ausgabe als 0,00 € in neutraler Farbgebung (Grau).
4.3.4.6. Frontend-Implementierung (Markup-Struktur)
4.3.4.6.1. Zell-Definition: Jedes Detail erhält eine eigene <td> innerhalb der Fahrplan-Tabelle.
4.3.4.6.2. Klassen-Zuweisung: Einsatz von semantischen CSS-Klassen wie .col-route, .col-dist, .col-weight und .col-revenue zur Steuerung der Spaltenbreiten und Ausrichtungen.
4.3.4.6.3. Lesbarkeit: Einhaltung der Kontrastvorgaben nach WCAG (Punkt 4.2.3.1), insbesondere bei der Darstellung von Leerfahrten auf dunklem Hintergrund.

4.3.5. Auto-Revision: Dynamische Neuberechnung der Tour-Parameter
4.3.5.1. Funktionale Zielsetzung der Auto-Revision
4.3.5.1.1. Gewährleistung einer permanenten logischen Konsistenz zwischen dem physischen Standort des Fahrzeugs, der zugewiesenen Aufträge und der daraus resultierenden Kette von Leerfahrten.
4.3.5.1.2. Echtzeit-Aktualisierung des visuellen Feedbacks: Der Disponent muss ohne manuelles Auslösen eines Refresh-Befehls ("da muss ich nix klicken") sofort die geänderte geografische Realität sehen.
4.3.5.1.3. Sicherstellung der mathematischen Korrektheit des Tourendes (Header-Statistik) nach jeder Änderung an der Kette.
4.3.5.2. Prozessablauf der Daten-Manipulation (Backend)
4.3.5.2.1. Trigger-Ereignis: Empfang eines asynchronen POST-Requests durch den Klick auf das Lösch-Symbol (unload_job) einer JOB-Etappe.
4.3.5.2.2. SQL-Transaktion: Durchführung eines UPDATE auf die Tabelle orders. Die Felder assigned_truck_id und assigned_at werden für den betroffenen Datensatz auf NULL gesetzt.
4.3.5.2.3. Integritäts-Check: Das System validiert, ob nach der Löschung verwaiste zeitliche Lücken in der Kette entstanden sind. Da die Sortierung jedoch auf assigned_at basiert (Punkt 4.3.1.2.3), rücken nachfolgende Jobs in der Berechnung automatisch nach.
4.3.5.3. Algorithmus zur dynamischen Fahrplan-Rekonstruktion
4.3.5.3.1. Neu-Initialisierung: Die Methode TourService::generateSchedule() wird für das betroffene Fahrzeug aufgerufen.
4.3.5.3.2. Anker-Positionierung: Der Algorithmus setzt den Startpunkt (Pointer) auf die current_city_id der Tabelle trucks.
4.3.5.3.3. Iterative Leerfahrt-Prüfung: Das System durchläuft die verbliebene Menge der Aufträge.
4.3.5.3.3.1. Falls Pointer != Start_City_ID des aktuellen Jobs, wird eine neue virtuelle Zeile LEERFAHRT zwischengeschaltet.
4.3.5.3.3.2. Die Distanz dieser Leerfahrt wird mittels DistanceService ab der Pointer-Position neu berechnet.
4.3.5.3.4. Pointer-Update: Nach jeder verarbeiteten JOB-Etappe wird der Pointer auf den Zielort (to_city_id) dieser Etappe gesetzt.
4.3.5.4. Synchronisation des virtuellen Tourendes (Header-Update)
4.3.5.4.1. Finaler Wert: Der nach Abschluss der Iteration (4.3.5.3) verbleibende Wert des Pointers definiert das neue "Virtuelle Tourende" für den Karten-Header.
4.3.5.4.2. Status-Wandlung: War der gelöschte Auftrag der letzte in der Kette, detektiert das System den Wegfall der Zielrichtung (Fall A) und schaltet die Header-Anzeige automatisch auf die POS-Anzeige (Fall B) gemäß Punkt 4.2.2.3.5 um.
4.3.5.5. Frontend-Reaktion (AJAX-Response)
4.3.5.5.1. Partial Rendering: Der Server sendet als Antwort auf den Lösch-Befehl ein JSON-Objekt zurück, welches zwei HTML-Fragmente enthält: Den aktualisierten Header (Statistiken) und den neu berechneten Tabellenkörper (Fahrplan).
4.3.5.5.2. DOM-Injektion: JavaScript ersetzt die Inhalte der betroffenen Fahrzeug-Karte. Bestehende Animationen für das Ausklappen (Punkt 4.2.7.2.3) bleiben dabei unberührt.

4.4. Der Touren-Optimierungs-Algorithmus (Topology-Engine) - Zweck: Dies ist die mathematische Logik hinter der Kettenbildung. Der Algorithmus sucht nicht nach isolierten Aufträgen, sondern berechnet auf Basis der 3-Städte-Nachbarschaft (Matrix-Topologie) fortlaufende Ketten. Dabei werden bereits reservierte Lager-Aufträge und verfügbare Markt-Angebote in einer einheitlichen Logik verarbeitet, um das ökonomische Optimum zu finden.
4.4.1. Funktionale Zielsetzung und mathematische Basis
4.4.1.1. Erzeugung von lückenlosen Transportketten durch simultane Auswertung des Lagerbestands und des Marktpools.
4.4.1.2. Strikte Einhaltung der Matrix-Topologie: Die Suche nach dem jeweils nächsten Glied einer Kette ist auf den Standort des LKWs sowie dessen zwei geografisch nächste Nachbarstädte begrenzt (3-Städte-Regel).
4.4.1.3. Maximierung der Ressourceneffizienz unter Berücksichtigung der administrativen Obergrenze für zeitgleiche Aufträge (Disponenten-Slots).
4.4.2. Logik der Slot-Kontingentierung (Ressourcen-Management)
4.4.2.1. Ermittlung der Netto-Kapazität: Freie_Slots = Globales_Limit - Anzahl_Lageraufträge.
4.4.2.2. Berechnung des Basis-Kontingents: Ziel_Quote = floor(Gesamt_Slots / Anzahl_aktivierte_LKW).
4.4.2.3. Dynamische Slot-Umverteilung: Der Algorithmus arbeitet iterativ. Schließt ein Fahrzeug seine Kette vorzeitig ab (aufgrund der Topologie-Regel 4.4.1.2), werden dessen ungenutzte Slots sofort dem globalen Pool für die verbleibenden aktiven Fahrzeuge zur Verfügung gestellt.
4.4.3. Der Such-Algorithmus (Iteratives Round-Robin-Verfahren)
4.4.3.1. Initialisierung: Das System lädt alle aktivierten Fahrzeuge (Punkt 4.2.5) und setzt deren Startpunkt auf das jeweilige Tourende (Punkt 4.2.2.3).
4.4.3.2. Such-Schleife: Der Algorithmus durchläuft mehrere Runden, bis entweder alle Slots belegt sind oder keine topologisch sinnvollen Aufträge mehr existieren.
4.4.3.3. Schrittweise Zuweisung: In jeder Runde erhält jeder LKW genau einen Auftrag, sofern die Kriterien erfüllt sind. Dies gewährleistet die vom Benutzer geforderte gleichmäßige Auslastung der Flotte.
4.4.4. Selektions- und Filter-Kriterien (Unified Pool Logic)
4.4.4.1. Topologie-Filter (Strict Neighbors): Das System führt für den aktuellen Standort eine SQL-Abfrage auf der Tabelle distances aus, um die zwei Städte mit der geringsten KM-Distanz zu identifizieren.
4.4.4.2. Kandidaten-Pool: Für die aktuelle Etappe werden alle Aufträge (orders) herangezogen, deren Startort (from_city_id) in der Menge {Standort, Nachbar_1, Nachbar_2} liegt.
4.4.4.3. Validierungs-Layer: Jeder Kandidat muss zwingend den Fahrzeugtyp-Check und den Tonnage-Check (weight_remaining <= truck_capacity) bestehen.
4.4.4.4. Slot-Prüfung: Markt-Aufträge werden nur in die Kandidatenliste aufgenommen, wenn zum Zeitpunkt der Berechnung die Freie_Slots > 0 ist. Lager-Aufträge werden slot-neutral behandelt.
4.4.5. Die Optimierungs-Hierarchie (Ranking)
4.4.5.1. Distanz-Diktat (Primär): Die Sortierung der Kandidaten erfolgt primär nach der Leerfahrt-Distanz zum Startort des Auftrags. Ein 0-km-Anschluss (Standort) wird zwingend gegenüber Nachbarstädten bevorzugt.
4.4.5.2. Ökonomischer Tie-Breaker (Sekundär): Existieren innerhalb der gleichen geografischen Ebene (z.B. mehrere Aufträge ab Standort) mehrere Optionen, gewinnt der Auftrag mit dem höchsten berechneten Erlös pro Tonnenkilometer (€/tkm).
4.4.5.3. Ergebnis-Sicherung: Nach jeder Auswahl eines Auftrags wird der fiktive Standort des LKWs für die nächste Runde auf den Zielort (to_city_id) des gewählten Auftrags gesetzt.
4.4.6. Technische Implementierung (PHP OOP & SQL)
4.4.6.1. Klassen-Struktur: Die Logik wird in der Klasse TopologyEngine gekapselt, die als Input ein Array von Truck-Objekten und das OrderRepository erwartet.
4.4.6.2. Daten-Integrität (Virtual Planning): Die Berechnungen erfolgen ausschließlich im Arbeitsspeicher (Arbeitskopien der Datenbank-Objekte). Eine persistente Speicherung in der SQL-Datenbank erfolgt erst durch die manuelle Bestätigung ("LADEN") durch den Disponenten im UI.

5. Fuhrpark- & Markt-Monitoring
5.1. Investitions-Check: Gebrauchtwagen-Parser (HTML-Import)

5.1.1. Extraktions-Technik:
5.1.1.1. Einsatz der Klasse DOMXPath zur Identifikation der Anzeigen-Container im einkopierten HTML-Quelltext.
5.1.1.2. Identifikation der eindeutigen ingame_vehicle_id aus den Quelltext-Attributen zur Dubletten-Vermeidung (Punkt 2.6.2.1).

5.1.2. Attribut-Mapping und Validierung:
5.1.2.1. Erfassung der Basisdaten: Baujahr, KM-Stand, Zustand (%) und Preis.
5.1.2.2. Tuning-Detektion: Prüfung auf das Vorhandensein der Strings für Motor-Tuning, Aerodynamik-Paket und Stauwarner.
5.1.2.3. Preis-Bereinigung: Anwendung der 3-Stufen-Geldlogik (Punkt 3.1) auf den inserierten Verkaufspreis.

5.1.3. Automatisierte Analyse:
5.1.3.1. ROI-Score-Berechnung: Unmittelbare Ausführung der Formel aus Punkt 2.6.3 nach dem Import.
5.1.3.2. Historien-Abgleich: SQL-Vergleich des neuen Scores mit dem AVG(roi_score) der Tabelle market_history für diesen Fahrzeugtyp.

5.2. Wartungs-Monitor: Alters- und Laufleistungs-Warnung
5.2.1. Logik der Zustands-Warnung:
5.2.1.1. Trigger "Alter": Differenz zwischen trucks.year_built und dem konfigurierten game_year ≥ 8 Jahre.
5.2.1.2. Trigger "Laufleistung": Wert in trucks.km_stand ≥ 1.000.000 km und größer 2.000.000 km.

5.2.2. Visuelle Indikation (Dashboard):
5.2.2.1. Darstellung einer Warn-Kachel im Cockpit mit der Anzahl der betroffenen Fahrzeuge.
5.2.2.2. Highlighting: In der Fuhrpark-Liste (Punkt 4.2) werden diese Fahrzeuge mit einem Warn-Icon und dem Text "ÜBERALTERT" oder "HOHE LAUFLEISTUNG" markiert, um auf das erhöhte Pannenrisiko hinzuweisen.

5.3. Personal-Parser: Stellengesuche (HTML-Import)

5.3.1. Extraktions-Logik:
5.3.1.1. Zielgerichtetes Ansteuern der Knoten <div class="humanresources"> im HTML-Quelltext.
5.3.1.2. Extraktion der ingame_driver_id (Punkt 2.3.1.2) als eindeutiges Kriterium für die Anlage oder das Update des Datensatzes.
5.3.2. Qualitäts-Erfassung:
5.3.2.1. Numerische Extraktion von Fahrkönnen, Zuverlässigkeit, Gehaltswunsch und Strafpunkten.
5.3.2.2. ADR-Status: Binäre Erkennung, ob eine Gefahrguterlaubnis vorliegt oder nicht.
5.3.3. Dubletten-Management:
5.3.3.1. Das System nutzt INSERT ... ON DUPLICATE KEY UPDATE. Falls ein Fahrer sich mehrfach bewirbt, werden seine Qualifikationsdaten aktualisiert, statt einen neuen Fahrer anzulegen.

5.4. Manuelle Datenpflege (CRUD-Schnittstellen)

5.4.1. Prinzip der manuellen Korrektur:
5.4.1.1. Jedes Modul (Fuhrpark, Personal, Matrix) erhält einen geschützten Editier-Bereich, um fehlerhafte Importe zu korrigieren oder Statusänderungen (z.B. Verkauf eines LKW) vorzunehmen.
5.4.2. Personal-Verwaltung (Manual Personnel Management):
5.4.2.1. Formular zur manuellen Anpassung von Gehalt, ADR-Status oder zur Entlassung (is_employed = 0).
5.4.2.2. Zuweisungs-Steuerung: Manuelle Verknüpfung oder Lösung der Verbindung zwischen Fahrer und LKW via Dropdown-Menü.
5.4.2.3. Automatischer Tour-Bruch bei Fahrerwechsel:
5.4.2.3.1. Trigger: Jede Änderung der Fahrer-LKW-Zuordnung in der Tabelle drivers oder eine Änderung des adr_permit-Status eines bereits zugewiesenen Fahrers.
5.4.2.3.2. Prüf-Algorithmus: Das System durchläuft die geplante Tour des betroffenen LKWs in chronologischer Reihenfolge (assigned_at ASC).
5.4.2.3.3. Mismatch-Detektion: Sobald ein Auftrag mit is_adr = 1 auf einen Fahrer trifft, dessen adr_permit = 0 ist, wird der Tour-Bruch eingeleitet.
5.4.2.3.4. Entkoppelungs-Logik: Dieser spezifische Auftrag sowie alle zeitlich nachfolgenden Aufträge dieser Tour werden sofort vom Fahrzeug gelöst (assigned_truck_id = NULL, assigned_at = NULL).
5.4.2.3.5. Bestandsschutz: Die gelösten Aufträge verbleiben im Status is_accepted = 1 (Lager-Bestand), werden jedoch in der Strategie-Sidebar (Punkt 4.1) wieder als "nicht zugewiesen" gewertet und im Dispatcher-Board für andere qualifizierte Fahrer sichtbar.
5.4.2.3.6. Standort-Synchronisation: Das virtuelle Tourende des Fahrzeugs wird gemäß Punkt 4.3.5 automatisch auf den Zielort des letzten vor dem Bruch liegenden (gültigen) Auftrags zurückgesetzt.

5.4.3. Fahrzeug-Verwaltung (Manual Fleet Management):
5.4.3.1. Bearbeitungsmaske für KM-Stand, Zustand und die manuelle Ingame-Kennung (user_label).
5.4.3.2. Lösch-Logik: Funktion "Fahrzeug verkaufen". Dies entfernt den LKW aus der Tabelle trucks, setzt aber die assigned_truck_id in der Fahrer-Tabelle auf NULL und in der Auftrags-Tabelle auf archived (falls Tour noch nicht beendet).
5.4.4. Sicherheits-Interlocks:
5.4.4.1. Jede manuelle Änderung an Stammdaten muss durch einen Bestätigungs-Dialog (JS Confirm) abgesichert werden, um versehentliche Datenverluste zu vermeiden.

5.5. Personal-Check (ADR-Konformität)

5.5.1. Präventive Filterung: Der Touren-Optimierungs-Algorithmus (Punkt 4.4) schließt ADR-Aufträge für nicht qualifizierte Fahrer bereits in der Kandidaten-Suche (Stufe 4) strikt aus.
5.5.2. Reaktive Validierung: Sicherstellung der Datenintegrität durch den automatischen Tour-Bruch bei personellen oder qualifikatorischen Änderungen gemäß Punkt 5.4.2.3.