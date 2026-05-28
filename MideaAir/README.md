[![Version](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Version](https://img.shields.io/badge/Symcon%20Version-8.0%20%3E-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)

# Midea

Steuert eine Midea Klimaanlage (0xAC) oder einen Midea Entfeuchter (0xA1) direkt per LAN.  
Ältere Geräte werden per Protokoll V2 ohne Token angesprochen, neuere Geräte per Protokoll V3 mit Token und Key.  
Die Cloud wird ausschließlich zur einmaligen Token-Ermittlung genutzt – danach kommuniziert das Modul vollständig lokal.

## Inhaltsverzeichnis

- [Midea](#midea)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [1. Voraussetzungen](#1-voraussetzungen)
  - [2. Konfiguration](#2-konfiguration)
    - [Grundeinstellungen](#grundeinstellungen)
    - [Protokoll V3 (Token / Key)](#protokoll-v3-token--key)
    - [Manuelle Konfiguration (Klimaanlage)](#manuelle-konfiguration-klimaanlage)
    - [Token automatisch ermitteln (Cloud-Login)](#token-automatisch-ermitteln-cloud-login)
  - [3. Token automatisch ermitteln](#3-token-automatisch-ermitteln)
  - [4. Variablen](#4-variablen)
    - [Klimaanlage – Basisvariablen](#klimaanlage--basisvariablen)
    - [Klimaanlage – Optionale Variablen](#klimaanlage--optionale-variablen)
    - [Entfeuchter](#entfeuchter)
  - [5. Aktionsschaltflächen](#5-aktionsschaltflächen)
  - [6. Debugging](#6-debugging)
  - [7. Spenden](#7-spenden)
  - [8. Lizenz](#8-lizenz)

## 1. Voraussetzungen

* mindestens IPS Version 8.0
* PHP-Erweiterung **GMP** (für präzise Berechnung großer Geräte-IDs)
* Midea Klimaanlage oder Midea Entfeuchter im lokalen Netzwerk
* Feste IP-Adresse des Geräts (DHCP-Reservierung im Router empfohlen)

## 2. Konfiguration

### Grundeinstellungen

| Einstellung | Beschreibung |
|---|---|
| Gerätetyp | `Klimaanlage (0xAC)` oder `Entfeuchter (0xA1)` |
| IP-Adresse | Lokale IP-Adresse des Geräts |
| TCP-Port | Standard: `6444` |
| Aktualisierungsintervall | Abfrageintervall in Sekunden (0 = deaktiviert) |

### Protokoll V3 (Token / Key)

Nur für neuere Geräte mit V3-Firmware erforderlich. Bei älteren Geräten (V2) alle Felder leer lassen.

| Einstellung | Beschreibung |
|---|---|
| Token (Hex) | Authentifizierungstoken, hexadezimal |
| Key (Hex) | Sitzungsschlüssel, hexadezimal |
| Appliance ID | Numerische Geräte-ID aus der Midea-Cloud (bei V2 nicht erforderlich) |
| Seriennummer | Nur zur Anzeige, wird automatisch befüllt |

> **Tipp:** Ob ein Gerät V2 oder V3 nutzt lässt sich einfach testen: Token-Felder leer lassen und „Jetzt aktualisieren" klicken. Kommt eine Antwort → V2. Keine Antwort → V3 mit Token erforderlich.

### Manuelle Konfiguration (Klimaanlage)

Da nicht alle Geräteeigenschaften per B5-Abfrage zuverlässig erkannt werden, können sie hier manuell gesteuert werden. Nach dem ersten **„Capabilities ermitteln"** werden die Checkboxen automatisch aus dem B5-Ergebnis vorbelegt und können danach manuell angepasst werden.

**Verfügbare Modi**

Nicht unterstützte Betriebsmodi können hier deaktiviert werden. Der Modus-Auswahl in IPS enthält dann nur die aktivierten Modi.

| Checkbox | Beschreibung |
|---|---|
| Auto (1) | Automatik-Modus |
| Kühlen (2) | Kühlbetrieb |
| Trocknen (3) | Entfeuchtungsbetrieb |
| Heizen (4) | Heizbetrieb |
| Lüften (5) | Nur Lüfterbetrieb ohne Wärmetausch |

**Lüfterstufen**

| Checkbox | Beschreibung |
|---|---|
| Leise (20) verfügbar | Lüfterstufe „Leise" einblenden (Standard: deaktiviert) |
| Mittel (60) verfügbar | Lüfterstufe „Mittel" einblenden (Standard: aktiviert) |

**Zusatzfunktionen**

| Checkbox | Variable | Beschreibung |
|---|---|---|
| Vertikale Lamellen | `VerticalSwing` | Lamellensteuerung vertikal |
| Horizontale Lamellen | `HorizontalSwing` | Lamellensteuerung horizontal |
| Eco-Modus | `EcoMode` | Energiespar-Modus |
| Turbo | `Turbo` | Turbo-/Boost-Modus |
| Luftreiniger | `Purifier` | Ionisations-/Luftreinigungsfunktion |
| Trockner | `Dryer` | No-Frost-Lüfter-Funktion |
| Frostschutz | `FrostProtect` | 8°C-Heizbetrieb |
| Display-Steuerung | `ShowScreen` | Display ein/aus |
| Innenfeuchte-Sensor | `IndoorHumidity` | Luftfeuchtigkeit innen |
| Leistungsmessung | `CurrentPower` | Stromverbrauch in Watt |

> **Hinweis:** Solange noch keine Capabilities ermittelt wurden, werden alle optionalen Variablen angezeigt. Nach dem ersten „Capabilities ermitteln" steuern ausschließlich die Checkboxen, welche Variablen registriert werden.

### Token automatisch ermitteln (Cloud-Login)

Die Cloud-Zugangsdaten werden ausschließlich einmalig zur Token-Ermittlung genutzt. Danach kommuniziert das Modul rein lokal.

| Einstellung | Beschreibung |
|---|---|
| App | `NetHome Plus`, `Midea Air` oder `Ariston Clima` |
| E-Mail-Adresse | Benutzername des Midea-Cloud-Accounts |
| Passwort | Passwort des Midea-Cloud-Accounts |

## 3. Token automatisch ermitteln

**Schritt 1 – ApplianceID ermitteln**

1. Cloud-Zugangsdaten eintragen
2. Schaltfläche **„1. ApplianceID ermitteln"** klicken
3. Bei einem einzigen Gerät im Account wird die ApplianceID automatisch gesetzt
4. Bei mehreren Geräten die korrekte ID manuell in das Feld **Appliance ID** eintragen

**Schritt 2 – Token ermitteln**

1. Schaltfläche **„2. Token ermitteln"** klicken
2. Das Modul fragt die Cloud ab und testet den Token direkt gegen das Gerät
3. Bei Erfolg werden Token und Key automatisch gespeichert
4. Im Anschluss wird **„Capabilities ermitteln"** automatisch ausgeführt und die Checkboxen werden vorbelegt

## 4. Variablen

### Klimaanlage – Basisvariablen

Immer vorhanden, unabhängig von Capabilities und Checkboxen.

| Ident | Bezeichnung | Typ | Steuerbar |
|---|---|---|---|
| `Running` | Betrieb | Boolean | ✅ |
| `Mode` | Modus | Integer | ✅ (nur aktivierte Modi, siehe Manuelle Konfiguration) |
| `TargetTemperature` | Soll-Temperatur | Float | ✅ (16,0 – 31,0 °C) |
| `IndoorTemperature` | Innentemperatur | Float | ❌ |
| `OutdoorTemperature` | Außentemperatur | Float | ❌ (wird automatisch angelegt sobald Gerät den Wert liefert) |
| `ErrorCode` | Fehlercode | Integer | ❌ |

### Klimaanlage – Optionale Variablen

Werden nur angelegt wenn die jeweilige Checkbox in der Konfiguration aktiviert ist.  
Nach **„Capabilities ermitteln"** werden die Checkboxen automatisch aus dem B5-Ergebnis vorbelegt.

| Ident | Bezeichnung | Typ | Steuerbar |
|---|---|---|---|
| `FanSpeed` | Lüfterstufe | Integer | ✅ (20=Leise¹, 40=Niedrig, 60=Mittel¹, 80=Hoch, 102=Auto, 127=Voll) |
| `VerticalSwing` | Vertikale Lamellen | Integer | ✅ (0=Aus, 1–3=Stufe) |
| `HorizontalSwing` | Horizontale Lamellen | Integer | ✅ (0=Aus, 1–3=Stufe) |
| `EcoMode` | Eco-Modus | Boolean | ✅ |
| `Turbo` | Turbo | Boolean | ✅ |
| `Purifier` | Luftreiniger | Boolean | ✅ |
| `Dryer` | Trockner | Boolean | ✅ |
| `FrostProtect` | Frostschutz | Boolean | ✅ |
| `ShowScreen` | Display | Boolean | ✅ |
| `IndoorHumidity` | Innenfeuchte | Float | ❌ |
| `CurrentPower` | Leistung | Float | ❌ |

¹ Leise (20) und Mittel (60) sind per Checkbox manuell aktivierbar.

### Entfeuchter

| Ident | Bezeichnung | Typ | Steuerbar |
|---|---|---|---|
| `Running` | Betrieb | Boolean | ✅ |
| `Mode` | Modus | Integer | ✅ (1=Auto, 2=Normal, 3=Schnell, 4=Schlaf, 6=Trocknen, 7=Reinigen) |
| `FanSpeed` | Lüfterstufe | Integer | ✅ (40=Leise, 80=Mittel, 127=Hoch) |
| `TargetHumidity` | Soll-Feuchte | Integer | ✅ (30 – 80 %) |
| `CurrentHumidity` | Ist-Feuchte | Float | ❌ |
| `IndoorTemperature` | Raumtemperatur | Float | ❌ |
| `IonMode` | Ionisierung | Boolean | ✅ |
| `Pump` | Pumpe | Boolean | ✅ |
| `SleepMode` | Schlafmodus | Boolean | ✅ |
| `VerticalSwing` | Lüftungslamellen | Boolean | ✅ |
| `TankFull` | Tank voll | Boolean | ❌ |
| `TankLevel` | Tankfüllstand | Integer | ❌ |
| `FilterIndicator` | Filter reinigen | Boolean | ❌ |
| `Defrosting` | Abtauen | Boolean | ❌ |
| `ErrorCode` | Fehlercode | Integer | ❌ |

## 5. Aktionsschaltflächen

| Schaltfläche | Beschreibung |
|---|---|
| Jetzt aktualisieren | Liest sofort den aktuellen Gerätestatus aus |
| Capabilities ermitteln | Fragt das Gerät nach unterstützten Funktionen (B5-Befehl), belegt Checkboxen vor und registriert Variablen neu |
| 1. ApplianceID ermitteln | Ruft die Geräteliste aus der Midea-Cloud ab |
| 2. Token ermitteln | Holt Token und Key aus der Cloud, testet sie direkt gegen das Gerät, speichert sie und führt anschließend automatisch „Capabilities ermitteln" aus |

## 6. Debugging

Das Modul schreibt detaillierte Diagnosemeldungen in das IPS-Debug-Fenster der Instanz (SendDebug). Die Meldungen sind nach Kategorien gegliedert:

| Kategorie | Inhalt |
|---|---|
| `Update` | Polling-Zyklus: Start, gesendeter Befehl, Antwortgröße, Status |
| `Token` | Token-Ermittlung, UDP-IDs, Handshake-Test |
| `Cloud` | Meldungen aus der Cloud-API (Authentifizierung, Geräteliste) |
| `B5` | Rohdaten und erkannte Capabilities der B5-Abfrage |
| `ApplianceID` | Gerätelisten-Abfrage und automatische ID-Zuweisung |
| `Variables` | Anlegen und Löschen optionaler Variablen |
| `sendV2` / `sendV3` | Netzwerkfehler auf LAN-Ebene |

Echte Fehler werden zusätzlich ins IPS-Systemlog (KL_ERROR) geschrieben.

## 7. Spenden

Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=EK4JRP87XLSHW" target="_blank"><img src="https://www.paypalobjects.com/de_DE/DE/i/btn/btn_donate_LG.gif" border="0" /></a> <a href="https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share" target="_blank">Amazon Wunschzettel</a>

## 8. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
