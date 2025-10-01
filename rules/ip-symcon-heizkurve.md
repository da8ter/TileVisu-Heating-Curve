<!-- agent:read-first -->
<!-- priority:high -->
<!-- output:follow-exactly -->
<!-- do-not-extend-scope -->

# Rules — IP-Symcon Modul „Heizkurve (linear)“
> Ergänzt `../rules.md`. **Nur Kern-Controls, ±-Buttons. Keine Timer. VM_UPDATE-getrieben.**
> Folgt den Best Practices aus dem IPS-Ökosystem (Ident-Nutzung, SetBuffer/GetBuffer, Kernel-Runlevel, RequestAction).

## 1) Ziel & Nicht-Ziele
**Ziel:** HTML-SDK Tile + PHP-Modul zur Berechnung einer linearen Heizkurve.  
**Nicht-Ziele:** Splines, Dragging, Zeitprogramme, Multi-Heizkreise, Historie.

## 2) SDK & Verhalten
- Darstellung: **HTML-SDK** (Tile). Initialer State über `GetVisualizationTile()`. Laufende Updates via `UpdateVisualizationValue` → `handleMessage(payload)`.
- Eingaben: ±-Buttons rufen `requestAction(ident, delta)` auf; Server: `RequestAction($Ident, $Delta)`.
- Ereignisse: `RegisterMessage($VarAT, VM_UPDATE)`, Handling in `MessageSink`. Kein zyklischer Timer.
- Kernel-Runlevel berücksichtigen (KR_READY/KERNELSTARTED).

## 3) Backend-Properties / form.json
- `MinVorlauf` (float, Default 25)
- `MaxVorlauf` (float, Default 55)
- `MinAT` (float, Default −10)
- `MaxAT` (float, Default +15)
- `Var_Aussentemperatur` (int, VariableID, gelesen)
- `Var_SollVorlauf` (int, VariableID, geschrieben)
**Validierung:** `MinVorlauf < MaxVorlauf`, `MinAT < MaxAT`; Zielvariable numerisch & beschreibbar.
**Änderung:** ApplyChanges → neu berechnen & UI pushen.

## 4) Frontend (Tile) — Kern-Controls
Vier ±-Controls (Schritt 0,5 K/°C): MinVL, MaxVL, MinAT, MaxAT.  
Status: „Außen: X °C · Soll: Y °C“. Optional Mini-SVG (rein informativ).  
A11y: Buttons ≥ 44 px, `aria-label`, Keyboard-Fokus.

## 5) Algorithmus (Linear, fix)
- `AT = MinAT` ⇒ `VL = MaxVorlauf`
- `AT = MaxAT` ⇒ `VL = MinVorlauf`
```
ratio = (AT - MaxAT) / (MinAT - MaxAT)
VL     = MinVorlauf + ratio * (MaxVorlauf - MinVorlauf)
VL     = clamp(VL, MinVorlauf, MaxVorlauf)
VL     = round(VL * 2) / 2   // 0,5 Schritte
```
Clamping: AT außerhalb [MinAT..MaxAT] auf Endwerte. NaN/Null AT → keine Berechnung, Statusmeldung.

## 6) Server-Implementierung
- **Create/ApplyChanges:** Properties registrieren, Messages neu setzen, Validierung, einmalige Berechnung & UI push. Kernelstatus beachten.
- **MessageSink:** Bei `VM_UPDATE` AT lesen → Compute → Zielvariable **nur bei Änderung** schreiben → UI push.
- **RequestAction:** Deltas ±0,5 clampen, validieren, Properties setzen → Compute → schreiben/pushen.
- **State:** `SetBuffer/GetBuffer`, keine Hilfsvariablen im Objektbaum.

## 7) Tests (Akzeptanzkriterien)
- ±-Klick aktualisiert sofort Berechnung & UI.
- Endpunkte: `AT=MinAT → VL=MaxVorlauf`, `AT=MaxAT → VL=MinVorlauf` exakt.
- Clamping & Rundung 0,5 korrekt.
- **Keine** Timer-Nutzung; nur VM_UPDATE, RequestAction, ApplyChanges triggern.
- Fehlerzustände liefern klare UI-/Statusmeldungen.

## 8) DoD (Feature)
- Datei-Set vollständig (`module.json`, `module.php`, `form.json`, README).
- Keine unautorisierten Objektänderungen; Ident-basierte Zugriffe.
- Code-Stil & Lint ok, PR-Checkliste erfüllt.
