# TileVisu Heizkurve

Symcon-Kachel (HTML-SDK), die eine Heizkurve mit Plateaus zeichnet, aus der Außentemperatur den Soll-Vorlauf berechnet und in eine Zielvariable schreibt. Die Kurve lässt sich in der Kachel per ± verstellen. Öffentliches Repo `da8ter/TileVisu-Heating-Curve`. Bedienung und Formel: `README.md`.

Betriebsdaten dieses Rechners (Zweige, Testsystem) stehen in `CLAUDE.local.md` (nicht eingecheckt).

## Aufbau

- **`TilVisu Heating Curve/`**: einziges Modul, Klasse `TilVisuHeatingCurve`, Präfix `TVHC`, `IPSModuleStrict`, ab Symcon 8.1. Die Schreibweise „TilVisu“ (ohne e) ist `name` in `module.json` und damit Klassenname: nur gemeinsam ändern.
- Im Wurzelordner liegt zusätzlich eine `module.json` (gleicher Inhalt wie die im Modulordner, aus einem frühen GitHub-Upload).
- **Laufzeitwerte der Kurve** in den Attributen `RT_*`, nicht in den Eigenschaften: die ±-Knöpfe schreiben dorthin. `ApplyChanges` übernimmt eine Kurven-Eigenschaft nur, wenn sie sich seit der letzten Übernahme geändert hat (Stand im Attribut `RT_Source`), sonst gingen die ±-Werte bei jedem Kernelstart, Reload und Übernehmen verloren.
- **Steuerlogik vor Kachel-Filter:** jede Aktualisierung der Außentemperatur rechnet und setzt den Soll-Vorlauf durch, auch ohne neuen Wert. Gespart wird nur bei der Kachel: ein unveränderter Zustand geht nicht noch einmal hinaus (Puffer `UpdateHashes`).
- **Kachel:** Anfangszustand steht inline im Kacheldokument (`JSON_HEX_TAG`), kein Init-Takt. `module.html` hat keinen eigenen `message`-Listener (das HTML-SDK stellt jede Nachricht schon zu) und zeichnet nur Zustände mit numerischen Kurvenwerten.
- Abos und Referenzen nur für zugeordnete Variablen (ID > 0); abgemeldet wird über `GetMessageList`.

## Prüfen

```bash
php -l "TilVisu Heating Curve/module.php"
php tests/module_test.php     # SDK-Attrappe, Gegenprobe gegen einen älteren Commit, node --check der Kachel (ohne node übersprungen)
git diff --check
```

## Regeln

- **Commits:** deutsche Botschaft, ein Thema je Commit, **ohne** Co-Authored-By-Zeile; Prüfungen vorher.
- **Nie** `git checkout`/`git restore` auf Dateien: die Arbeitskopie kann nicht committete Arbeit enthalten.
- **Push und Release nur auf Zuruf.** Release: `version`, `build` und `date` in `library.json` hochsetzen (`date` ist ein Unix-Zeitstempel).
- **Öffentliches Repo:** keine IP-Adressen, Ports, Instanz-IDs, Token, Pfade unter `/Users/`, keine Personendaten – auch nicht in Tests und Kommentaren.
- **Symcon-Standards:** `strict_types`, `IPSModuleStrict` mit vollen Typen, Darstellungen statt Variablenprofilen, Texte über `locale.json`, Nutzertexte sagen „Symcon“.
- Neue Attribute brauchen im laufenden System ein Neuladen des Moduls, `IPS_ApplyChanges` allein reicht nicht.

## Wissen

Gemeinsames Symcon-Plattformwissen (Lebenszyklus, Timer, Kachel-Nachrichten): https://github.com/da8ter/SymDo-Family-Organizer/tree/SymDo-Beta/docs/plattform – lokal `../List/docs/plattform/`. Die Begründungen der Kachel-Umbauten stehen ausführlich in den Commit-Botschaften (`git log`). Symcon-Fragen am offiziellen Handbuch prüfen.
