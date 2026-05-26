[![Version](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Version](https://img.shields.io/badge/Symcon%20Version-8.0%20%3E-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)

# Midea

Steuert eine Midea Klimaanlage (0xAC) oder einen Midea Entfeuchter (0xA1) direkt per LAN.  
Ältere Geräte werden per Protokoll V2 ohne Token angesprochen, neuere Geräte per Protokoll V3 mit Token und Key.

## Inhaltsverzeichnis

- [Midea](#midea)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [1. Voraussetzungen](#1-voraussetzungen)
  - [2. Konfiguration](#2-konfiguration)
    - [Grundeinstellungen](#grundeinstellungen)
    - [Protokoll V3 (Token / Key)](#protokoll-v3-token--key)
    - [Token automatisch ermitteln (Cloud-Login)](#token-automatisch-ermitteln-cloud-login)
  - [3. Token automatisch ermitteln](#3-token-automatisch-ermitteln)
  - [4. Variablen](#4-variablen)
    - [Klimaanlage – Basisvariablen](#klimaanlage--basisvariablen)
    - [Klimaanlage – Optionale Variablen](#klimaanlage--optionale-variablen)
    - [Entfeuchter](#entfeuchter)
  - [5. Aktionsschaltflächen](#5-aktionsschaltflächen)
  - [6. Spenden](#6-spenden)
  - [7. Lizenz](#7-lizenz)

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
| Aktualisierungsintervall | Abfrageintervall in Sekunden (Minimum: 10 s) |

### Protokoll V3 (Token / Key)

Nur für neuere Geräte mit V3-Firmware erforderlich. Bei älteren Geräten (V2) alle Felder leer lassen.

| Einstellung | Beschreibung |
|---|---|
| Token (Hex) | Authentifizierungstoken, hexadezimal |
| Key (Hex) | Sitzungsschlüssel, hexadezimal |
| Appliance ID | Numerische Geräte-ID aus der Midea-Cloud |
| Seriennummer | Nur zur Anzeige, wird automatisch befüllt |

> **Tipp:** Ob ein Gerät V2 oder V3 nutzt lässt sich einfach testen: Token-Felder leer lassen und „Jetzt aktualisieren" klicken. Kommt eine Antwort → V2. Keine Antwort → V3 mit Token erforderlich.

### Token automatisch ermitteln (Cloud-Login)

Die Cloud-Zugangsdaten werden ausschließlich einmalig zur Token-Ermittlung genutzt. Danach kommuniziert das Modul rein lokal.

| Einstellung | Beschreibung |
|---|---|
| App | `NetHome Plus`, `Midea Air`, `Ariston Clima` oder `MSmartHome` |
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

## 4. Variablen

### Klimaanlage – Basisvariablen

| Ident | Bezeichnung | Typ | Steuerbar |
|---|---|---|---|
| `Running` | Betrieb | Boolean | ✅ |
| `Mode` | Modus | Integer | ✅ (1=Auto, 2=Kühlen, 3=Trocknen, 4=Heizen, 5=Lüften) |
| `TargetTemperature` | Soll-Temperatur | Float | ✅ (16,0 – 31,0 °C) |
| `IndoorTemperature` | Innentemperatur | Float | ❌ |
| `OutdoorTemperature` | Außentemperatur | Float | ❌ (nur wenn Gerät den Wert liefert) |
| `ErrorCode` | Fehlercode | Integer | ❌ |

### Klimaanlage – Optionale Variablen

Werden nur angelegt wenn das Gerät die jeweilige Funktion unterstützt (nach „Capabilities ermitteln").

| Ident | Bezeichnung | Typ | Steuerbar |
|---|---|---|---|
| `FanSpeed` | Lüfterstufe | Integer | ✅ |
| `VerticalSwing` | Vertikale Lamellen | Integer | ✅ |
| `HorizontalSwing` | Horizontale Lamellen | Integer | ✅ |
| `EcoMode` | Eco-Modus | Boolean | ✅ |
| `Turbo` | Turbo | Boolean | ✅ |
| `Purifier` | Luftreiniger | Boolean | ✅ |
| `Dryer` | Trockner | Boolean | ✅ |
| `FrostProtect` | Frostschutz | Boolean | ✅ |
| `ShowScreen` | Display | Boolean | ✅ |
| `IndoorHumidity` | Innenfeuchte | Float | ❌ |

### Entfeuchter

| Ident | Bezeichnung | Typ | Steuerbar |
|---|---|---|---|
| `Running` | Betrieb | Boolean | ✅ |
| `Mode` | Modus | Integer | ✅ (1=Auto, 2=Normal, 3=Schnell, 4=Schlaf, 6=Trocknen, 7=Reinigen) |
| `FanSpeed` | Lüfterstufe | Integer | ✅ |
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
| Capabilities ermitteln | Fragt das Gerät nach unterstützten Funktionen (B5-Befehl) und entfernt nicht unterstützte Variablen automatisch |
| 1. ApplianceID ermitteln | Ruft die Geräteliste aus der Midea-Cloud ab |
| 2. Token ermitteln | Holt Token und Key aus der Cloud, testet und speichert sie |

## 6. Spenden

Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=EK4JRP87XLSHW" target="_blank"><img src="https://www.paypalobjects.com/de_DE/DE/i/btn/btn_donate_LG.gif" border="0" /></a> <a href="https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share" target="_blank">Amazon Wunschzettel</a>

## 7. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
