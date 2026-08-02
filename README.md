# PenguinPVDash

> Home Assistant ➜ PHP-Server: PV-/Energiedaten im Minutentakt mit Tageswerten & moderner Web-UI

![status](https://img.shields.io/badge/status-active-4caf50)
![license](https://img.shields.io/badge/license-MIT-blue)

## ✨ Features
- **Home Assistant Integration (HACS)** mit echten **Entity-Selectoren**
- **Mehrere Server-Instanzen** parallel anlegen, getrennt konfigurieren und jederzeit umbenennen
- Sendet **Leistungen** (PV, Verbrauch, Einspeisung, Netzbezug, Batterie IN/OUT, SOC)
- Optional: **Tageszähler** (kWh) für PV, Einspeisung, Batterie IN/OUT, **Verbrauch**, **Netzbezug**
- **PHP-Server** (SQLite) mit Flow-Diagramm, dynamischem Batterie-Icon & **30-Tage-Tabelle**
- Optionale **HMAC-Signatur** (API-Key) für sichere Übertragung


---

## 🧩 Installation über HACS (Custom Repository)
1. **HACS → Integrations** öffnen  
2. Rechts oben **⋯ → Custom repositories**  
3. URL: `https://github.com/Borderlane-HA/PenguinPVDash`  
4. **Category**: `Integration` → **Add**  
5. In HACS nach **PenguinPVDash** suchen → **Install**  
6. **Home Assistant neu starten**


### Manuell (ohne HACS)
- Ordner `custom_components/penguin_pvdash` nach `/config/custom_components/` kopieren  
- HA neu starten → *Einstellungen → Geräte & Dienste → Integration hinzufügen → „PenguinPVDash“*

---

## ⚙️ Konfiguration (Integration)
Unter *Einrichten/Optionen* stehen diese Felder zur Verfügung:

**Instanz & Verbindung**
- **Instanzname** – frei wählbarer Name, z. B. `Familie`, `Öffentlich` oder `Testserver`
- **Server URL** – z. B. `https://dein.server.tld/api/ingest.php`  
- **API Key** *(optional)* – für HMAC-Signatur  
- **Geräte-ID** – frei (z. B. `home`)  
- **Intervall** – in Minuten (Standard: **1**)  
- **Leistungseinheit** – `kW` oder `W` (Skalierung automatisch)

**Leistungen (optional, Entity-Selector)**
- **PV-Leistung**
- **Batterie IN (Leistung)**
- **Batterie OUT (Leistung)**
- **Einspeisung (Leistung)**
- **Hausverbrauch (Leistung)**
- **Netzbezug (Leistung)**
- **Batterie SOC (%)**

**Tageszähler (kWh, optional – reset täglich auf 0)**
- **PV gesamt (kWh)**
- **Einspeisung gesamt (kWh)**
- **Batterie IN gesamt (kWh)**
- **Batterie OUT gesamt (kWh)**
- **Hausverbrauch gesamt (kWh)**
- **Netzbezug gesamt (kWh)**

> Werden Tageszähler **nicht** gesetzt, berechnet der Server kWh aus den Leistungen per Integration (Trapezregel, inkl. Mitternachtssplit).

### Mehrere Server verwenden
Über **Einstellungen → Geräte & Dienste → PenguinPVDash → Eintrag hinzufügen** können mehrere Server als getrennte Instanzen angelegt werden. Jede Instanz besitzt eine eigene URL, einen eigenen API-Key, ein eigenes Intervall und eine eigene Sensorzuordnung. Über das Zahnrad kann jede Instanz später vollständig bearbeitet und umbenannt werden.

---

## 🖥️ Server installieren
**Voraussetzungen:** PHP 8+, PDO SQLite.

1. Inhalt aus `/SERVER/` auf den Webserver kopieren  
2. Schreibrechte für `server/data/` sicherstellen (SQLite-DB wird darin angelegt)  
3. Seite aufrufen: `https://dein.server.tld/` → UI mit Flussdiagramm & 30-Tage-Tabelle

### `server/inc/config.php` 

- **false/true :: true if API Key set; device Name and API Key same in Integration**
- $PVDASH_REQUIRE_AUTH = true;
- **// Device Name and API Key (Standard: home)**
- $PVDASH_API_KEYS = [ "home" => "MYAPIKEY" ];
- **Language German (de) or english (en)**
- $lang_from_config = 'de';


## 🖼 Screenshots

**Server UI:**

<img src="https://github.com/Borderlane-HA/PenguinPVDash/blob/main/SCREENSHOTS/Screen1.png"/><br>
<img src="https://github.com/Borderlane-HA/PenguinPVDash/blob/main/SCREENSHOTS/Screen2.png"/><br>

**Integration (HA):**

<img src="https://github.com/Borderlane-HA/PenguinPVDash/blob/main/SCREENSHOTS/Screenshot2a.png" width="425"/><br>
<img src="https://github.com/Borderlane-HA/PenguinPVDash/blob/main/SCREENSHOTS/Screenshot2b.png" width="425"/><br>
<img src="https://github.com/Borderlane-HA/PenguinPVDash/blob/main/SCREENSHOTS/Screenshot2c.png" width="425"/><br>
<img src="https://github.com/Borderlane-HA/PenguinPVDash/blob/main/SCREENSHOTS/Screenshot2c.png" width="425"/><br> 


