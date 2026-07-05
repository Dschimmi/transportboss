<?php
declare(strict_types=1);

/**
 * FinanceViewHelper: Generiert die HTML-Ausgabe für Finanzwerte gemäß UI-Standards[cite: 3].
 */
class FinanceViewHelper
{
    /**
     * Erzeugt den HTML-String für Finanzbeträge inkl. Währungssymbol[cite: 3].
     *
     * @param float $value Der auszugebende Betrag (z.B. Erlös).
     * @param bool $isProfit Gibt an, ob der Betrag farblich als Profit markiert werden soll.
     * @return string Der finale HTML-String für das Frontend.
     */
    public static function renderAmount(float $value, bool $isProfit = false): string
    {
        $currencySpan = '<span class="currency-unit">€</span>';

        // Nullwerte (z.B. bei Leerfahrten) durch Bindestrich ersetzen[cite: 3]
        if ($value === 0.0) {
            return '- ' . $currencySpan;
        }

        // Wert über den FinanceMapper korrekt formatieren (Tausenderpunkte, Komma)[cite: 3]
        $formattedValue = FinanceMapper::format($value);

        // Erlöse farblich grün (text-profit) hervorheben[cite: 3]
        if ($isProfit && $value > 0) {
            return '<span class="text-profit">' . $formattedValue . ' ' . $currencySpan . '</span>';
        }

        // Standardausgabe (z.B. für Marktpreise ohne Profit-Hervorhebung)[cite: 3]
        return $formattedValue . ' ' . $currencySpan;
    }
}