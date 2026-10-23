# Changelog

## Unveröffentlicht

### Neu

- Entfeuchter: Panel „Manuelle Konfiguration (Entfeuchter)“ mit Checkboxen für alle Modi, die Lüfterstufe Mittel sowie Ionisator, Pumpe, Lamellen und Füllstandssensor. „Capabilities ermitteln“ belegt sie aus der B5-Antwort vor, danach lassen sie sich von Hand ändern. Ab Werk ist alles aktiviert.
- Die Konfiguration zeigt nur noch die manuelle Konfiguration des gewählten Gerätetyps. Beim Umstellen des Gerätetyps schaltet sie sofort um, schon vor dem Übernehmen.

### Geändert

- „Capabilities ermitteln“ zeigt statt der B5-Rohwerte die vorbelegten Checkboxen mit ✓ oder ✗ und zieht sie im offenen Formular gleich nach. Die Rohwerte stehen im Debug unter `B5`.
- Entfeuchter-Modi nach dem Midea-Protokoll: 1 Soll-Feuchte, 2 Dauerbetrieb, 3 Smart, 4 Trocknen, 6 Luftreinigung, 7 Schimmelschutz, 10 Leise, 11 Auto (vorher Auto, Normal, Schnell, Schlaf). Skripte, die den Modus setzen, müssen angepasst werden.
- Entfeuchter: Auch Soll-Feuchte und Dauerbetrieb hängen jetzt an Checkboxen. Meldet das Gerät in der B5-Antwort für 0x14 den Wert 7, belegt „Capabilities ermitteln“ nur Leise, Trocknen und Auto vor. So bietet es die App bei einem Comfee mit diesem Wert an. Wer „Capabilities ermitteln“ schon vor dem Update ausgeführt hat, sieht Leise und Auto, bis er es erneut ausführt.
- Entfeuchter-Lüfterstufen 40 Niedrig, 60 Mittel, 80 Hoch (vorher 40, 80 und 127). Hoch liegt damit auf 80 statt 127.
- Soll-Feuchte 35 bis 85 % (vorher 30 bis 80). Werte außerhalb werden auch bei Aufrufen aus Skripten auf diesen Bereich begrenzt.
- Entfeuchter: Filteranzeige und Schlafmodus hängen ebenfalls an Checkboxen. „Filteranzeige vorhanden“ belegt „Capabilities ermitteln“ aus der B5-Antwort vor. Den Schlafmodus meldet das Gerät nicht, diese Checkbox setzt nur der Anwender. Beide sind ab Werk angehakt.
- Ionisierung, Pumpe, Lamellen, Tankfüllstand, Filteranzeige und Schlafmodus gibt es nur noch, wenn die zugehörige Checkbox gesetzt ist. Wird sie abgewählt, auch durch „Capabilities ermitteln“, löscht das Modul die Variable, und ihre Verknüpfungen und Archivzuordnung gehen verloren. Meldet das Gerät keine Filteranzeige (etwa der Comfee 0xA1), verschwindet `FilterIndicator` schon beim ersten „Capabilities ermitteln“ nach dem Update.

### Fixes

- B5-Antwort: Die Länge jedes Eintrags kommt aus dem Längenbyte statt fest 4 Bytes. Einträge hinter einem längeren Wert (etwa 0x0225 mit 7 Bytes) gingen vorher verloren oder wurden versetzt gelesen. Einträge ohne Wert werden übersprungen.
- Tests mit PHPUnit und den Symcon-Stubs unter `tests/`: B5-Parser, Entfeuchter-Konfiguration und Soll-Feuchte, an echten Mitschnitten.

## 19.10.2026 - Version 1.0.1

### Fixes

- README: `SmartHome (mp-prod.appsmb.com)` als Standard-App und empfohlene Option ergänzt
- SmartHome-Cloud-Authentifizierung vollständig korrigiert: `pushToken` in Login-`iotData` ergänzt (fehlte, behebt Code 3106); `accessToken` aus `mdata.accessToken` (nicht `tokenPwdInfo.tokenPwd`, behebt Code 40002); Login-`iotData` auf msmart-Referenz reduziert (`format`, `language`, `platformId`, `deviceId` entfernt); `deviceId` in `data`-Sektion ergänzt; `format`/`clientType` als Integer; Doppel-Slash-Bug in `reRoute()` behoben (behebt Code 40404)

## 17.10.2026 - Version 1.0

### Neu

- SmartHome-Cloud-Unterstützung (`mp-prod.appsmb.com`): Automatischer Fallback als Stufe 4 bei Token-Ermittlung; direkte Auswahl über App-Dropdown möglich
- App-Option „SmartHome (mp-prod.appsmb.com)" für neuere EU-Accounts
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

## 26.05.2026 - Version 1.0 Beta

### Neu

- Erstveröffentlichung (Beta)

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
