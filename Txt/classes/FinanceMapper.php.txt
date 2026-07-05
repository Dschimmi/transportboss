<?php
declare(strict_types=1);

/**
 * Exception für fehlerhafte Finanzdaten[cite: 3]
 */
class MalformedCurrencyException extends Exception {}

/**
 * Exception für Berechnungsfehler bei der Float-Konvertierung[cite: 3]
 */
class ArithmeticException extends Exception {}

/**
 * FinanceMapper: Kapselt die 3-Stufen-Geldlogik[cite: 3]
 */
class FinanceMapper
{
    /**
     * Stufe 1: Entfernung aller Tausender-Kommas aus dem Roh-String[cite: 3]
     *
     * @param string $rawAmount Roher Währungs-String aus dem Quelltext
     * @return string Bereinigter String mit Punkt als Dezimaltrenner
     * @throws MalformedCurrencyException Wenn mehr als ein Dezimalpunkt existiert
     */
    public static function stripSeparators(string $rawAmount): string
    {
        // Sämtliche Nicht-Zahlzeichen entfernen, Punkt und Komma behalten[cite: 3]
        $cleaned = preg_replace('/[^\d.,]/', '', $rawAmount);
        
        // Tausender-Kommas rückstandslos entfernen[cite: 3]
        $cleaned = str_replace(',', '', $cleaned);
        
        // Konsistenz-Check: Prüfen ob maximal 1 Dezimalpunkt verblieben ist[cite: 3]
        if (substr_count($cleaned, '.') > 1) {
            throw new MalformedCurrencyException("Zu viele Dezimalpunkte im Betrag: " . $rawAmount);
        }
        
        return $cleaned;
    }

    /**
     * Stufe 2: Konvertierung in SQL-kompatiblen Float[cite: 3]
     *
     * @param string $strippedAmount Bereinigter String (Ergebnis aus Stufe 1)
     * @return float Konvertierter numerischer Wert
     * @throws ArithmeticException Bei ungültigen mathematischen Zuständen
     */
    public static function parseToNumeric(string $strippedAmount): float
    {
        if (!is_numeric($strippedAmount)) {
            throw new ArithmeticException("Wert kann nicht numerisch konvertiert werden: " . $strippedAmount);
        }
        
        $value = (float)$strippedAmount;
        
        // Prüfung auf INF oder NAN[cite: 3]
        if (is_infinite($value) || is_nan($value)) {
            throw new ArithmeticException("Konvertierung ergab INF oder NAN.");
        }
        
        return $value;
    }

    /**
     * Stufe 3: Formatierte Ausgabe im UI (Tausenderpunkte, Dezimalkomma)[cite: 3]
     *
     * @param float $value Interner numerischer System-Wert
     * @return string Formatierter Text-Wert
     */
    public static function format(float $value): string
    {
        // 2 Dezimalstellen, Komma als Dezimaltrenner, Punkt als Tausendertrenner[cite: 3]
        return number_format($value, 2, ',', '.');
    }
}