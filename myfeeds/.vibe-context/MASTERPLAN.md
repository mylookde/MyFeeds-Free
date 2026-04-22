# MASTERPLAN - My Product Picker

## Vision
Affiliate-Produktfeed-Plugin für WordPress/Gutenberg mit intelligenter Keyword-Suche, das sich zu einer Stylight-ähnlichen Shop-Lösung entwickelt.

---

## Phasen

### Phase 1: Core Plugin & Gutenberg Blocks ✅ (Grundlagen abgeschlossen)
- [x] Feed-Import (CSV, XML, JSON)
- [x] Produkt-Rendering im Frontend
- [x] Responsive Grid-Layout
- [x] Basis-Admin-UI
- [ ] **Batch Importer** für große Feeds (bis 100.000 Produkte)
- [ ] **Universal Mapper UI** für flexible Feed-Zuordnung
- [ ] Gutenberg Block Integration

### Phase 2: Freemius Monetarisierung ✅ (Grundlagen abgeschlossen)
- [x] Freemius SDK Integration
- [x] Lizenz-Management
- [x] Admin-Menü mit Lizenz-Seite
- [ ] Premium Feature-Gating
- [ ] Pricing-Tiers definieren

### Phase 3: Supabase Integration (Geplant)
- [ ] Supabase-Verbindung einrichten
- [ ] PostgreSQL mit pgvector für Vektorsuche
- [ ] Produkt-Synchronisation zu Supabase
- [ ] Embedding-Generierung mit OpenAI
- [ ] Semantische Produktsuche

### Phase 4: Stylight-ähnliche Shop-Lösung (Zukunft)
- [ ] Bildsuche (visuell ähnliche Produkte)
- [ ] KI-gestützte Produktempfehlungen
- [ ] Multi-Shop Aggregation
- [ ] Personalisierung

---

## Tech-Stack

| Komponente | Technologie | Status |
|------------|-------------|--------|
| Backend | PHP 8.0+ (WordPress) | Aktiv |
| Frontend | JavaScript (Gutenberg/React) | Teilweise |
| Admin UI | WordPress Admin API | Aktiv |
| Monetarisierung | Freemius SDK | Aktiv |
| Datenbank (lokal) | WordPress Options API | Aktiv |
| Datenbank (cloud) | Supabase (PostgreSQL/pgvector) | Geplant |
| KI/Embeddings | OpenAI API | Geplant |
| **Batch Processing** | **Action Scheduler v3.9.1** | **Aktiv (Dez 2025)** |
| Legacy Batch | WP-Cron (spawn_cron) | ⚠️ Deprecated |

---

## Architektur-Übersicht

```
Product-Picker-WP/
├── my-product-picker.php          # Haupt-Plugin-Datei, Freemius Init, AS Loader
├── includes/
│   ├── class-feed-manager.php     # Core-Logik, Admin UI, process_critical_fields()
│   ├── class-product-picker.php   # Frontend-Rendering
│   ├── class-smart-mapper.php     # Auto-Mapping (wird refaktoriert)
│   ├── class-batch-importer.php   # Batch-Verarbeitung via Action Scheduler
│   ├── class-settings-manager.php # API-Keys, Templates
│   └── class-universal-mapper-ui.php # Mapping-Oberfläche
├── assets/
│   └── style.css                  # Frontend-Styles
├── vendor/
│   ├── freemius/                  # Freemius SDK
│   └── woocommerce/action-scheduler/  # Action Scheduler Library (v3.9.1)
└── .vibe-context/                 # Governance-Struktur
    ├── MASTERPLAN.md
    ├── VIBE_RULES.md
    └── CURRENT_STATE.log
```

---

## Kritische Methoden (Single Source of Truth)

| Methode | Klasse | Zweck |
|---------|--------|-------|
| `process_critical_fields()` | Feed_Manager | Zentrale Datenverarbeitung, Rabatt-Berechnung |
| `as_process_batch()` | Batch_Importer | **NEU:** Idempotenter Batch-Handler für Action Scheduler |
| `schedule_next_batch()` | Batch_Importer | Batch-Scheduling via `as_schedule_single_action()` |
| `complete_import()` | Batch_Importer | Import abschließen, Index-Swap, Cleanup |

---

*Letzte Aktualisierung: Dezember 2025*
