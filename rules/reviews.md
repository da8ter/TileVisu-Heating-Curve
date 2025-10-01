# Reviews & Definition of Done

## PR-Checkliste
- [ ] `rules.md` & Feature-Rules gelesen
- [ ] `form.json` validiert (Grenzen, Pflichtfelder)
- [ ] `RequestAction` nur bekannte Idents, Deltas ±0.5, clamp/validate
- [ ] `MessageSink` reagiert auf VM_UPDATE; **kein Timer**
- [ ] Algorithmus: Endpunkte, Clamping, 0,5er Rundung korrekt
- [ ] UI-Update via `UpdateVisualizationValue` (kein Reload nötig)
- [ ] Keine Hidden-Objekte im Objektbaum; Ident-basiert
- [ ] Kernel-Runlevel beachtet; SetBuffer/GetBuffer genutzt
- [ ] README mit Setup & Beispiel vorhanden
- [ ] Lint/Stil grün

## Tests (manuell)
- ± auf jedem Control → sofortiger Sollwert-Change
- AT=MinAT → VL=MaxVL; AT=MaxAT → VL=MinVL
- AT außerhalb Range → korrektes Clamping
- Zielvariable wird nur bei Wertänderung aktualisiert
