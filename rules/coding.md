# Coding & Style Rules

## Sprache & Benennung
- Code: **Englisch** (CamelCase für PHP-Methoden, snake_case vermeiden; Konstanten UPPER_CASE).
- UI-Texte: lokalisierbar (DE/EN).

## PHP
- PHP 8+. Strict Types wo möglich. Keine @-Error-Suppression.
- Fehlerbehandlung via Exceptions mit klaren Messages.
- Ident-basierte Objektzugriffe. Keine Namen-Suche.

## JavaScript
- ES6+, keine Frameworks. Module-Pattern, keine globalen Leaks.
- Keine inline-eval. DOM-Zugriffe minimal & sicher.

## Struktur
- Properties nur über Symcon-Mechanismen lesen/schreiben.
- Kurz & klar: kleine Funktionen, eine Verantwortung.

## Commits
- Präfixe: `feat:`, `fix:`, `docs:`, `refactor:`, `test:`.
- PRs referenzieren Rules/Issue & enthalten kurze Demo/Screenshots.
