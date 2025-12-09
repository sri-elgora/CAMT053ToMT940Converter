# Konverter Kontoauszüge im CAMT053-Format nach MT940

- Konvertiert XML Kontoauszüge in das MT940 Format
- UTF8 wird nach ISO-8859-1 konvertiert
- Konvertiert alle XML-Dateien im Verzeichnis, der Dateiname wird beibehalten und die Dateeindung auf STA geändert
- erfolgreich konvertierte XML-Datei werden in das Unterverzeichnis "save" verschoben
- fehlerhafte Dateien werden in das Unterverzeichnis "error" verschoben

Benutzung:
php CAMT053ToMT940Converter.php "Verzeichnis"
