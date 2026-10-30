# Changelog

## 23.10.2026 - Version 1.0.3

### Neu

- Entfeuchter: Panel „Manuelle Konfiguration (Entfeuchter)" mit Checkboxen für Modi, Lüfterstufe Mittel sowie Ionisator, Pumpe, Lamellen, Füllstandssensor, Filteranzeige und Schlafmodus. „Capabilities ermitteln" belegt sie aus der B5-Antwort vor, danach lassen sie sich von Hand ändern. Ab Werk ist alles aktiviert.
- Die Konfiguration zeigt beim Entfeuchter nur noch die Entfeuchter-Konfiguration, bei der Klimaanlage nur die Klimaanlagen-Konfiguration. Beim Umstellen des Gerätetyps schaltet sie sofort um.

### Fixes

- B5-Antwort: Die Länge jedes Eintrags kommt aus dem Längenbyte statt fest 4 Bytes (betrifft Entfeuchter und Klimaanlage). Einträge hinter einem längeren Wert wurden vorher falsch gelesen.
- Entfeuchter-Modi nach dem Midea-Protokoll: 1 Soll-Feuchte, 2 Dauerbetrieb, 3 Smart, 4 Trocknen, 6 Luftreinigung, 7 Schimmelschutz, 10 Leise, 11 Auto (vorher Auto/Normal/Schnell/Schlaf). **Skripte, die den Modus setzen, müssen angepasst werden.**
- Entfeuchter-Lüfterstufen 40 Niedrig, 60 Mittel, 80 Hoch (vorher 40/80/127). **Hoch liegt damit auf 80 statt 127.**
- Soll-Feuchte 35 bis 85 % (vorher 30 bis 80).
- „Capabilities ermitteln" zeigt statt B5-Rohwerten die vorbelegten Checkboxen mit ✓ oder ✗.
- Ionisierung, Pumpe, Lamellen, Tankfüllstand, Filteranzeige und Schlafmodus gibt es nur noch, wenn die zugehörige Checkbox gesetzt ist. Wird sie abgewählt, löscht das Modul die Variable.

## 19.10.2026 - Version 1.0.2

### Neu

- App-Option „SmartHome (mp-prod.appsmb.com)" für neuere Accounts, die mit den bisherigen App-Optionen keinen Token erhalten; wird als Standard vorausgewählt

### Fixes

- SmartHome-Cloud-Authentifizierung vollständig korrigiert (Token-Ermittlung funktioniert jetzt zuverlässig)

## 17.10.2026 - Version 1.0.1

### Neu

- EU-Cloud-Server (`mapp-eu.appsmb.com`) für europäische Accounts
- App-Optionen „NetHome Plus (EU)" und „Midea Air (EU)" in der Konfiguration
- Stromverbrauchsmessung: Antworttyp 0xB5 (16-Bit Big-Endian an b[19..20], Einheit 0,01 W)
- Außentemperatur-Variable deterministisch registriert (immer vorhanden)

### Fixes

- Token-Ermittlung für EU-Accounts: Automatischer Fallback auf Asia-Server (`mapp.appsmb.com`), wenn der EU-Server `getToken` verweigert (Fehler 9999)
- Klasse erbt von `IPSModuleStrict` statt `IPSModule`
- `RequestAction` mit korrekten Type Hints (`string $Ident, mixed $Value`)
- Innenraumfeuchtigkeit nutzt korrekte Property `$humidity` (statt `$indoorHumidity`)
- Spendenblock in der Konfigurationsmaske auf Deutsch

## 28.06.2025 - Version 1.0 Build 1 Beta

### Neu

- Manuelle Konfiguration (Klimaanlage): Checkboxen für verfügbare Modi (Auto, Kühlen, Trocknen, Heizen, Lüften) — nicht unterstützte Modi werden aus der Auswahl entfernt
- Lüfterstufen manuell konfigurierbar: Checkboxen für Stufe „Leise (20)" und „Mittel (60)" — unabhängig von B5-Ergebnis
- Capabilities als Grundlage für Variablen: Nach „Capabilities ermitteln" (B5) werden Checkboxen automatisch vorbelegt; Checkboxen sind danach alleinige Quelle für die Variablenregistrierung
- Capabilities automatisch nach Token-Ermittlung: Nach erfolgreichem „2. Token ermitteln" werden Capabilities direkt im Anschluss automatisch ermittelt
- Übersetzungen
- Debugging: Alle Diagnosemeldungen werden per SendDebug in das Debug-Fenster der Instanz geschrieben (Kategorien: Update, Token, Cloud, B5, ApplianceID, Variables, sendV2, sendV3); echte Fehler weiterhin per LogMessage(KL_ERROR) ins Systemlog

### Fixes

- Polling-Intervall: Untergrenze von 10 Sekunden entfernt — beliebiger Wert konfigurierbar, 0 deaktiviert den Timer
- V2-Betrieb ohne ApplianceID: Leere ApplianceID führt nicht mehr zu einem Fatal Error — V2-Geräte funktionieren auch ohne eingetragene ID

## 26.05.2026 - Version 1.0 Beta

### Neu

- Erstveröffentlichung (Beta)