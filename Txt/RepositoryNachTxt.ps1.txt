###############################################################################
# Projekt nach "Txt" kopieren und an jede Datei ".txt" anhängen
#
# Beispiel:
#   C:\MeinProjekt\Order.php
# wird zu
#   C:\MeinProjekt\Txt\Order.php.txt
#
# Die Originaldateien bleiben unverändert.
###############################################################################

# >>> HIER DEN PFAD ZU DEINEM PROJEKT EINTRAGEN <<<
$Source = "C:\xampp\htdocs\sitebase\transportboss"

# Zielordner wird automatisch als Unterordner "Txt" angelegt.
# Normalerweise NICHT ändern.
$Target = Join-Path $Source "Txt"

# Existiert der Zielordner bereits?
# Dann wird er vollständig gelöscht, damit keine alten Dateien erhalten bleiben.
if (Test-Path $Target) {
    Remove-Item $Target -Recurse -Force
}

# Zielordner neu anlegen.
New-Item -ItemType Directory -Path $Target | Out-Null

# Alle Dateien des Projekts durchlaufen.
# Der Zielordner "Txt" wird dabei ignoriert, damit das Skript sich
# nicht selbst kopiert.
Get-ChildItem -Path $Source -Recurse -File | Where-Object {
    $_.FullName -notlike "$Target*"
} | ForEach-Object {

    # Relativen Pfad innerhalb des Projekts bestimmen.
    # Beispiel:
    # src\Models\Order.php
    $RelativePath = $_.FullName.Substring($Source.Length).TrimStart('\')

    # Ziel-Unterordner erzeugen (falls noch nicht vorhanden).
    $TargetFolder = Join-Path $Target (Split-Path $RelativePath)
    New-Item -ItemType Directory -Path $TargetFolder -Force | Out-Null

    # Datei kopieren und ".txt" an den Dateinamen anhängen.
    # Beispiel:
    # Order.php  ->  Order.php.txt
    $TargetFile = Join-Path $Target ($RelativePath + ".txt")
    Copy-Item $_.FullName $TargetFile
}

Write-Host ""
Write-Host "Fertig!"
Write-Host "Die konvertierten Dateien befinden sich in:"
Write-Host $Target