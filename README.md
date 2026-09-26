# TileVisu Heizkurve

[![Version](https://img.shields.io/badge/Symcon-5.0+-blue.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)

Interaktive HTML-Kachel für IP-Symcon zur Visualisierung und Anpassung einer Heizkurve mit Plateau-Unterstützung.

![alt text](https://github.com/da8ter/images/blob/main/heizkurve.png?raw=true)

## Inhaltsverzeichnis
- [Funktionen](#funktionen)
- [Voraussetzungen](#voraussetzungen)
- [Installation](#installation)
- [Konfiguration](#konfiguration)
- [Verwendung](#verwendung)
- [Technische Details](#technische-details)
- [Support](#support)

## Funktionen

### Visualisierung
- **Interaktiver Heizkurven-Chart** mit Canvas-basiertem Rendering
- **Echtzeit-Marker** zeigen aktuelle Außentemperatur (blau) und Soll-Vorlauftemperatur (orange)
- **Farbverlauf-kodierte Kurve** von warm (orange) bis kalt (rot)
- **Plateau-Unterstützung** für flache Abschnitte an Temperaturextremen
- **Responsives Design** mit Anpassung an Dunkel-/Hell-Themes über CSS-Variablen
- **Dynamische Achsenskalierung** mit automatischer Tick-Generierung

### Funktionalität
- **Ereignisgesteuerte Updates** über VM_UPDATE - keine Polling-Timer
- **Automatische Berechnung** der Soll-Vorlauftemperatur basierend auf Außentemperatur
- **Interaktive Bedienelemente** mit ±1°C Buttons für alle Parameter
- **Echtzeit-Visualisierungs-Updates** ohne Seitenneuladung
- **Validierung** stellt sicher, dass Min < Max für alle Temperaturbereiche

## Voraussetzungen

- IP-Symcon **7.1 oder höher**
- Zwei Variablen:
  - Außentemperatur (lesbar)
  - Soll-Vorlauftemperatur (schreibbar)

## Installation

### Über Module Store (empfohlen)
1. IP-Symcon Konsole öffnen
2. Navigiere zu **Module Store**
3. Suche nach **"TileVisu Heating Curve"**
4. Klicke auf **Installieren**

### Manuelle Installation über Module Control
 https://github.com/da8ter/TileVisu-Heating-Curve.git

## Konfiguration

### Instanz erstellen
1. In IP-Symcon Konsole: **Instanz hinzufügen** → **TileVisu Heating Curve**
2. Modul im Instanz-Konfigurationsformular konfigurieren

### Temperatur-Skalen
| Parameter | Beschreibung | Standard | Einheit |
|-----------|--------------|----------|---------|
| **VL Scale Min** | Minimale Vorlauftemperatur für Y-Achse | 20 | °C |
| **VL Scale Max** | Maximale Vorlauftemperatur für Y-Achse | 50 | °C |
| **Min AT** | Minimale Außentemperatur (X-Achse rechts) | -10 | °C |
| **Max AT** | Maximale Außentemperatur (X-Achse links) | +15 | °C |

### Heizkurven-Parameter
| Parameter | Beschreibung | Standard | Einheit |
|-----------|--------------|----------|---------|
| **Min Vorlauf** | Vorlauftemperatur bei warmer Außentemperatur | 25 | °C |
| **Max Vorlauf** | Vorlauftemperatur bei kalter Außentemperatur | 55 | °C |
| **Start AT** | Außentemperatur bei der die Steigung beginnt (warmes Ende) | +10 | °C |
| **End AT** | Außentemperatur bei der die Steigung endet (kaltes Ende) | -5 | °C |

### Variablen
| Einstellung | Beschreibung | Erforderlich |
|-------------|--------------|--------------|
| **Außentemperatur** | Variable zum Lesen der aktuellen Außentemperatur | Ja |
| **Soll-Vorlauf** | Variable zum Schreiben der berechneten Vorlauftemperatur | Ja |

### Validierungsregeln
- ✅ MinVorlauf < MaxVorlauf
- ✅ MinAT < MaxAT  
- ✅ MinAT ≤ EndAT ≤ StartAT ≤ MaxAT
- ✅ Beide Variablen müssen ausgewählt sein

## Verwendung

### Kachel-Anzeige
Die HTML-Kachel zeigt:
- **Heizkurve** mit Kontrollpunkten (Akzentfarbe)
- **Blauer Marker** auf der X-Achse zeigt aktuelle Außentemperatur
- **Oranger Marker** auf der Kurve zeigt Soll-Vorlauftemperatur  
- **Gestrichelte Linie** verbindet Marker mit X-Achse
- **Interaktive Labels** zeigen aktuelle Werte

### Parameter anpassen
Verwende die **± Buttons** in der Kachel zum Anpassen von:
- **Min Vorlauf** / **Max Vorlauf** - Vorlauftemperatur-Grenzen
- **Start** / **Ende** - Plateau-Übergangspunkte

Änderungen werden sofort angewendet und berechnen automatisch die Zieltemperatur neu.

## Technische Details

### Heizkurven-Berechnung
Das Modul verwendet eine **stückweise lineare Funktion** mit drei Abschnitten:

1. **Warmes Plateau**: AT ≥ StartAT → VL = MinVorlauf
2. **Steigung**: EndAT < AT < StartAT → Lineare Interpolation
3. **Kaltes Plateau**: AT ≤ EndAT → VL = MaxVorlauf

```
VL = MaxVorlauf + (AT - EndAT) / (StartAT - EndAT) × (MinVorlauf - MaxVorlauf)
```

### Ereignisbehandlung
- Registriert `VM_UPDATE` Nachricht für Außentemperatur-Variable
- Triggert automatische Neuberechnung bei Temperaturänderungen
- Aktualisiert Soll-Vorlauftemperatur nur bei Wertänderung (>0,001°C Differenz)
- Sendet Visualisierungs-Updates über `UpdateVisualizationValue()`

### PHP-Funktionen

#### Öffentlich
```php
RequestAction(string $Ident, float $Value)
```
Behandelt ± Button-Aktionen für Parameter: `MinVL`, `MaxVL`, `MinAT`, `MaxAT`, `StartAT`, `EndAT`

#### Privat
```php
CalculateVorlauf(float $at, float $minVL, float $maxVL, float $minAT, float $maxAT, ?float $startAT, ?float $endAT): float
```
Berechnet Soll-Vorlauftemperatur basierend auf aktueller Außentemperatur und Kurvenparametern.

```php
RecalculateAndPush(bool $configValid): void
```
Berechnet Vorlauftemperatur neu und aktualisiert sowohl Zielvariable als auch Visualisierung.


## Support

### Spenden
Wenn du dieses Modul nützlich findest, unterstütze gerne den Entwickler:
- [PayPal](https://paypal.me/sspkbw25)
- [Amazon Wunschliste](https://www.amazon.de/hz/wishlist/ls/2LE6P493HMWT0)

**Symcon Forum**: [Link zum Forum-Thread falls vorhanden]
