# LEGO Resale Architecture v1

## Product tiers

### Core
No AI required. Local catalog, manual unit creation, unit photos, valuations, pricing, listing generation, sales history.

### Model-Assisted
The user analyzes photos in ChatGPT or another multimodal model and imports an LRX v1 manifest. AI output is reviewed as proposals.

### Integrated AI / BYO Key (future)
Optional user-supplied model/API credentials automate the same proposal-generation step inside the app.

## Layers
1. **Catalog identity** — local indexed LEGO catalog (Rebrickable bulk data).
2. **Recognition/judgment** — AI or user proposes identity/condition.
3. **Physical inventory** — authoritative unique units and exact photos.
4. **Valuation** — time-stamped observations from market sources.
5. **Pricing** — deterministic recommendations derived from valuation + condition.
6. **Listings and sales** — what was offered and what actually realized.

## Physical inventory
A catalog set can have many physical copies. Each copy gets an immutable unit ID and a display alias:
- `76058-1-A`
- `76058-1-B`
- `76058-1-C`

The letter count is a human convention only; available quantity is derived by counting available units.

## Loose parts
Identity includes:
- part/design number
- exact color
- decoration state: plain / printed / stickered / sticker residue / unknown
- condition
- quantity
- confidence

Color and decoration materially affect both identity and value.


## Local catalog composition layer (alpha.2)
The authoritative identity catalog now includes Rebrickable inventory relationships:
- catalog set → inventory/version
- inventory → exact part number + exact color + quantity + spare state
- inventory → minifigure + quantity
- inventory → nested set + quantity

This is the foundation for future loose-part candidate matching. Multiple observed distinctive part/color combinations can be intersected against catalog inventories to rank likely source sets. AI remains the perception/judgment layer; the local catalog is the validation/narrowing layer.


## LRX package commit semantics (alpha.3)
A complete LRX package contains the neutral manifest plus the actual source photos. The importer stages package contents and validates filename mappings before approval.

Approval is transactional. For a set proposal it creates the physical unit, copies the exact item's photos to the immutable unit folder, creates photo metadata, stores proposed condition, and writes the included valuation as a historical observation. The AI proposal itself remains preserved as provenance.

Multiple physical copies cannot share a generic photo pool at commit time; they require explicit per-unit photo grouping.


## Required physical-unit analysis pass
Catalog identification and physical-unit counting are separate analysis tasks. LRX generation must explicitly group photos by distinct physical copy before import. Quantity is derived from approved unit records, not inferred from the number of photos.


## Import validation enforcement — alpha.3.2
LRX imports now require a physical_unit_analysis block and explicit unit/photo partitioning. The importer verifies counts and one-to-one photo assignment before staging and again before commit.


## Visual review gate — alpha.3.3
The proposal-review stage must display the actual staged source images grouped by proposed physical unit before approval. Filename-only review is insufficient for unit-level inventory. The staged image endpoint only serves files registered to an import batch and contained beneath the private import storage root.


## Valuation interaction — alpha.3.4
The UI no longer expects the user to remember to save a valuation before recalculating. Manual valuation edits use a save-and-reprice action. BrickLink POV enrichment also writes a new valuation observation before recalculating, preserving valuation history rather than mutating old observations.


## Public vs internal listing data — alpha.3.8
Internal inventory notes and AI-review instructions are private operational data and must never flow directly into customer-facing listing text. Draft listings are regenerable projections of current structured inventory/pricing state.
