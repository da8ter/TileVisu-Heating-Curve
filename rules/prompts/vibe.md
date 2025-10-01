<!-- agent:read-first -->
<!-- priority:high -->
<!-- output:follow-exactly -->
<!-- do-not-extend-scope -->

# Prompt — Vibe Coding Agent (IP-Symcon HTML-SDK Modul „Heizkurve (linear)“)

## Auftrag
Implementiere ein IP-Symcon PHP-Modul mit HTML-SDK-**Tile** für eine **lineare Heizkurve** mit **vier Kern-Controls** (±-Buttons).  
Berechnung der Soll-Vorlauftemperatur erfolgt **ereignisgetrieben** bei **VM_UPDATE** der konfigurierten Außentemperaturvariable.

## Deliverables
- `module.json`, `module.php`, `form.json`, `README.md`
- Optionale `locale.json` (DE/EN)

## Backend (form.json)
Properties: `MinVorlauf`, `MaxVorlauf`, `MinAT`, `MaxAT`, `Var_Aussentemperatur`, `Var_SollVorlauf` (+ Validierung).  
Bei Konfig-Änderung: neu berechnen & UI push.

## Frontend (Tile)
- Vier ±-Controls: MinVL, MaxVL, MinAT, MaxAT (Schritt 0,5).
- Statuszeile: „Außen: X °C · Soll: Y °C“.
- `handleMessage(payload)` für Updates; `requestAction(ident, delta)` für Eingaben.

## Server
- `RegisterMessage($VarAT, VM_UPDATE)`, `MessageSink` verarbeitet Updates.
- `RequestAction` setzt Properties (clamp/validate) → Recompute → schreibt Zielvariable (nur bei Änderung) → UI push via `UpdateVisualizationValue`.
- Kernel-Runlevel beachten, State via `SetBuffer/GetBuffer`.
- Keine Timer.

## Algorithmus
```
ratio = (AT - MaxAT) / (MinAT - MaxAT)
VL     = MinVorlauf + ratio * (MaxVorlauf - MinVorlauf)
VL     = clamp(VL, MinVorlauf, MaxVorlauf)
VL     = round(VL * 2) / 2
```

## Regeln
- **Keine Scope-Erweiterung.** Keine fremden Instanzen anlegen/ändern.
- Ident-basierte Zugriffe, sprechende Fehlermeldungen.
- A11y: Buttons ≥ 44 px, Keyboard erreichbar.
