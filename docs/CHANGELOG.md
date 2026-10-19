# Changelog

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
