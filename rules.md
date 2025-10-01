<!-- agent:read-first -->
<!-- priority:high -->
<!-- output:follow-exactly -->
<!-- do-not-extend-scope -->

# Global Rules — Projekt *IP-Symcon Modul „Heizkurve (linear)“*

## Zweck
Diese Datei legt **globale Leitplanken** fest. Alle spezifischen Rules in `rules/` **ergänzen** dieses Dokument. 
Bei Konflikten gelten die **strengeren** Regeln. Feature-Regeln dürfen das Globale nur dann überschreiben, wenn sie es **explizit** sagen.

## Scope (global)
- Ein IP-Symcon **PHP-Modul** mit **HTML-SDK**-Tile-Frontend für eine **lineare Heizkurve**.
- Frontend: **Kern-Controls** ausschließlich per **±-Buttons** (4 Werte).
- **Ereignisgetrieben**: Berechnung nur bei **VM_UPDATE** der Außentemperaturvariable (und bei Konfig-/Action-Events). **Kein Timer**.

## Nicht-Ziele
Keine Drag-Kurven/Splines, keine Zeitpläne, keine Multi-Heizkreise, kein historisches Logging (außer Status/Fehler).

## Security & Architektur (Kurzfassung)
- Keine Seiteneffekte auf fremde Instanzen. Keine Hidden-Hilfsvariablen im Objektbaum.
- Ident-basierter Zugriff statt Namen. Property-Änderungen nur auf User-Aktion/Config.
- Frontend kommuniziert über `requestAction(ident, value)`; Server antwortet mit `UpdateVisualizationValue` → `handleMessage(payload)`.
- Temporäre Zustände via `SetBuffer/GetBuffer`. Keine Eval/unsicheren HTML-Injections.

## Code-Style & Qualität
- **PHP 8+**, **JS ES6**. Einheitliche Benennungen in **Englisch** (UI-Texte übersetzbar).
- Linting/Format: siehe `rules/coding.md`. Code muss tests/CI bestehen (falls aktiviert).
- PRs erfüllen `rules/reviews.md` (Definition of Done).

## Agenten-Nutzung
- Agenten lesen **zuerst** diese Datei, dann die Feature-Regel `rules/ip-symcon-heizkurve.md` und den Prompt `rules/prompts/vibe.md`.
- **Keine Scope-Erweiterungen**: Nur das bauen, was hier beschrieben ist.
