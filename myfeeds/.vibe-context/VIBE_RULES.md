# VIBE_RULES - Verbindliche Entwicklungsregeln

> **Diese Regeln sind absolut bindend für jede Code-Änderung.**

---

## 1. Immutability-Schutz

**Regel:** Ändere oder lösche niemals Funktionen, die nicht explizit im Prompt benannt wurden.

**Umsetzung:**
- Vor jeder Änderung: Liste aller betroffenen Funktionen erstellen
- Nur explizit genannte Funktionen modifizieren
- Unbenannte Funktionen bleiben unangetastet
- Bei Unsicherheit: Nachfragen vor Änderung

---

## 2. Kausalitäts-Prüfung

**Regel:** Bevor du Code änderst, analysiere alle Abhängigkeiten. Wenn eine Änderung bestehende Funktionen gefährdet oder Logik rückgängig macht, stoppe und frage nach.

**Checkliste vor jeder Änderung:**
- [ ] Welche anderen Dateien/Funktionen rufen diese Funktion auf?
- [ ] Welche Funktionen werden von dieser Funktion aufgerufen?
- [ ] Gibt es Hooks/Filter, die davon abhängen?
- [ ] Könnte diese Änderung einen bereits behobenen Bug wieder einführen?

**Bekannte kritische Abhängigkeiten:**
| Funktion | Abhängig von | Aufgerufen durch |
|----------|--------------|------------------|
| `process_critical_fields()` | - | Batch_Importer, Feed_Manager |
| `render_products()` | `process_critical_fields()` | Shortcode, Block |

---

## 3. Code-Qualität

**Standards:**
- **PHP:** PSR-12 Coding Standard
- **JavaScript:** ESLint mit WordPress-Preset
- **Dokumentation:** PHPDoc für alle öffentlichen Methoden

**Architektur-Prinzipien:**
- Modulare Architektur (eine Klasse = eine Verantwortung)
- Keine "Spaghetti-Funktionen" (max. 50 Zeilen pro Methode)
- DRY (Don't Repeat Yourself) - zentrale Methoden nutzen
- Dependency Injection wo möglich

**Beispiel für korrekte Struktur:**
```php
// RICHTIG: Zentrale Methode nutzen
$processed_product = Feed_Manager::process_critical_fields($raw_product);

// FALSCH: Logik duplizieren
$discount = ($product['old_price'] - $product['price']) / $product['old_price'] * 100;
```

---

## 4. Archivierung

**Regel:** Lege nur dann `archive/`-Dateien an, wenn es explizit befohlen wird.

**Umsetzung:**
- Keine automatischen Backups erstellen
- Keine `_backup`, `_old`, `archive/` Dateien ohne expliziten Befehl
- Git-History ist das primäre Backup-System

---

## 5. Kontext-Pflicht

**Regel:** Rufe vor jeder Implementierung den Inhalt dieses Ordners auf.

**Workflow bei jeder neuen Aufgabe:**
1. `.vibe-context/MASTERPLAN.md` lesen - Wo stehen wir?
2. `.vibe-context/VIBE_RULES.md` lesen - Was sind die Regeln?
3. `.vibe-context/CURRENT_STATE.log` lesen - Aktueller Status
4. Abhängigkeiten analysieren
5. Implementierung planen
6. `CURRENT_STATE.log` nach Abschluss aktualisieren

---

## 6. Lingua Franca (MANDATORY)

**Regel:** All UI strings must be English (US).

**Umsetzung:**
- All button labels, status messages, error messages, and descriptions in English
- No German text in production code (admin UI, frontend, error messages)
- Use `__()` and `_e()` for translatable strings (still in English)
- Exception: Code comments may be in German for internal documentation

---

## 7. Non-Blocking AJAX (MANDATORY)

**Regel:** Non-blocking AJAX polling is mandatory for progress feedback.

**Umsetzung:**
- AJAX calls must return `202 Accepted` immediately for long-running jobs
- UI must show progress panel IMMEDIATELY on button click (before AJAX response)
- Status polling must start IMMEDIATELY, not after job completes
- Background jobs use WordPress shutdown hook or WP-Cron
- Never block UI thread waiting for server response

**Pattern:**
```javascript
// 1. Show panel IMMEDIATELY
showStatusPanel();
setInitialStatus('Starting...');
startStatusPolling();

// 2. Fire AJAX (non-blocking)
$.post(ajaxurl, {...}, function(response) {
    // Response arrives while polling is already running
});
```

---

## 8. Archiving Policy (FINAL – NON-NEGOTIABLE)

**Regel:** Es existiert genau EIN zentrales Archiv für das gesamte Projekt.

### Zentraler Archiv-Ort (verbindlich)

```
/wordpress-plugin/Archiv/
```

**Begründung:**
- Archiv ist projektweit, nicht plugin-intern
- Archiv darf nicht Teil des deploybaren Plugins sein
- Archiv ist historische Referenz, kein Runtime-Code
- Vorbereitung für: mehrere Plugins, Monorepo-Struktur, saubere Releases (Freemius/Git)

### Verbotene Strukturen

❌ **Innerhalb von Plugin-Ordnern darf es KEINEN `/archive/` Ordner geben.**

Beispiele für verbotene Pfade:
- `/wordpress-plugin/Product-Picker-WP/archive/` ❌
- `/wordpress-plugin/*/archive/` ❌

### Archiv-Dateien Regeln

1. **Read-Only:** Archiv-Dateien sind unveränderlich
2. **Niemals überschreiben:** Bestehende Archive dürfen nicht ersetzt werden
3. **Niemals löschen:** Archiv-Dateien bleiben permanent erhalten
4. **Niemals automatisch erstellen:** Archives entstehen NUR auf expliziten User-Request
5. **Explicit Request Only:** Eine neue Archiv-Datei wird ausschließlich erstellt, wenn der Nutzer es explizit anfordert (z.B. "Bitte archiviere...")

### Naming Convention

Jede Archiv-Datei/Ordner muss enthalten:
- **Datum:** `YYYY-MM-DD` Format
- **Zweck-Tag:** Kurze Beschreibung (z.B. `quick-sync-object-ids`)
- **Optional:** Uhrzeit oder Build-ID

**Beispiele:**
```
/wordpress-plugin/Archiv/2025-12-04-quick-sync-object-ids/
/wordpress-plugin/Archiv/Product-Picker-WP-2025-11-03-FREEMIUS-NAMES-UPDATED/
```

### Build & Deployment

- Archiv-Dateien sind **NICHT** Teil des Plugin-Builds
- Archiv-Dateien werden **NICHT** deployed
- Archiv-Dateien sind **NICHT** aktiver Code

### Bei Verstoß

- If an archive file with the same name exists: **ABORT** and choose a new unique name
- Never assume an archive can be "updated" – create a new one with a different name instead
- Plugin-interne `/archive/` Ordner sofort zum zentralen Archiv verschieben

---

## Verstöße und Konsequenzen

Bei Verstoß gegen diese Regeln:
1. **Sofortiger Stopp** der aktuellen Änderung
2. **Rollback** falls bereits Code geändert wurde
3. **Nachfrage** beim Benutzer zur Klärung

---

## Changelog der Regeln

| Datum | Änderung |
|-------|----------|
| Dez 2025 | Initiale Erstellung der VIBE_RULES |
| Dez 2025 | **REGEL 6 hinzugefügt:** All UI strings must be English (US) |
| Dez 2025 | **REGEL 7 hinzugefügt:** Non-blocking AJAX polling is mandatory |
| Dez 2025 | **REGEL 8 hinzugefügt:** Archiving Policy – archives are immutable, explicit request only |
| Dez 2025 | **REGEL 8 KORRIGIERT:** Zentrales Archiv unter `/wordpress-plugin/Archiv/`, plugin-interne archive/ verboten |

---

*Diese Regeln gelten für die gesamte Lebensdauer des Projekts.*
