# LEGO Resale Exchange (LRX) v1

This neutral JSON contract is the boundary between an external AI/model and the authoritative resale app.

## Principles
- AI output is a **proposal**, never authoritative inventory until approved.
- LEGO set numbers are strings. Use the exact full catalog identifier, including suffix, e.g. `76058-1`.
- One proposal may represent multiple physical units only when the model is confident they are distinct copies.
- Photos must ultimately attach to the exact physical unit being sold.
- Valuation observations and pricing recommendations are separate concepts.
- Part-out value is an appraisal/pricing-confidence signal; the core strategy remains selling the complete set.

## Minimal manifest

```json
{
  "schema": "lego-resale-exchange-v1",
  "schema_version": "1.0",
  "batch": {
    "batch_id": "BAT-20260812-001",
    "generator": "ChatGPT",
    "created_at": "2026-08-12T13:00:00-04:00",
    "photo_count": 23
  },
  "proposals": [
    {
      "item_type": "set",
      "catalog": {
        "set_num": "76058-1",
        "name": "Spider-Man: Ghost Rider Team-Up"
      },
      "physical_units": 1,
      "identification": {
        "confidence": 0.99,
        "review_required": false
      },
      "condition": {
        "state": "new",
        "sealed_status": "sealed",
        "box_condition": "good",
        "instructions_status": "not_applicable",
        "completeness": "unknown"
      },
      "photos": [
        {"filename": "IMG_0001.jpg", "role": "front"},
        {"filename": "IMG_0002.jpg", "role": "back"},
        {"filename": "IMG_0003.jpg", "role": "seal"}
      ],
      "valuation": {
        "currency": "USD",
        "msrp": 19.99,
        "whole_new": 75.00,
        "whole_used": 43.00,
        "part_out_sold": null,
        "part_out_for_sale": null,
        "source_label": "model research"
      },
      "notes": null
    }
  ]
}
```

## Loose part proposal (planned UI)
Loose parts must include part/design identity when known, exact color, decoration state, condition, quantity and confidence. Stickered and printed states are not interchangeable.


## Complete package transport
For production import, distribute the manifest and its referenced photos together in one `.zip`. The importer matches photos by original filename, stages them, and reports missing references before approval.

For multiple physical units of the same catalog product, use a `units` array with separate condition/photos/valuation blocks per physical unit. Do not rely on one undifferentiated photo list when actual publication photos differ by unit.


## Physical-unit grouping requirement (v1.0-alpha.3.1 onward)

Model-assisted analysis must perform **two explicit passes**:

1. identify the catalog product;
2. determine the number of distinct physical units represented by the photographs.

For newly generated LRX packages, every set proposal should use a `units` array, even when there is only one physical copy. Each unit owns its own condition, photos, valuation, and notes.

Example:

```json
{
  "item_type": "set",
  "catalog": {"set_num": "10246-1", "name": "Detective's Office"},
  "physical_units": 2,
  "units": [
    {
      "unit_label": "A",
      "condition": {"state":"new","sealed_status":"sealed","box_condition":"good"},
      "photos": [
        {"filename":"20260811_150021.jpg","role":"front"},
        {"filename":"20260811_150023.jpg","role":"back"}
      ],
      "valuation": {}
    },
    {
      "unit_label": "B",
      "condition": {"state":"new","sealed_status":"sealed","box_condition":"good"},
      "photos": [
        {"filename":"20260811_150102.jpg","role":"front"},
        {"filename":"20260811_150111.jpg","role":"back"}
      ],
      "valuation": {}
    }
  ]
}
```

Evidence for unit separation can include timestamp breaks, different box wear/corner damage, different stickers or seals, repeated front/back sequences, or multiple boxes visible simultaneously. Photos may never be treated as a generic pool when they represent different physical copies.


## Required validation block — alpha.3.2+

Every set proposal MUST include:

```json
"physical_unit_analysis": {
  "catalog_set": "70815-1",
  "photos_examined": 4,
  "units_detected": 2,
  "confidence": 0.97,
  "grouping_basis": [
    "two distinct front/back photo sequences",
    "different visible box wear details"
  ]
}
```

The importer rejects the proposal if:
- `units` is missing,
- `physical_units` does not equal the number of unit groups,
- `units_detected` does not equal the number of unit groups,
- `photos_examined` does not equal the number of uniquely assigned photos,
- any photo is assigned to more than one physical unit,
- any unit has no photos,
- the analysis catalog ID disagrees with the proposal catalog ID.

This validation does not visually identify the units itself; it prevents incomplete model analysis from silently entering authoritative inventory.


## Sealed-state simplification — alpha.3.9.1

`sealed_unverified` is deprecated.

Use:
- `sealed_status: "sealed"` when the photo evidence supports factory sealed,
- `sealed_status: "opened"` when opened,
- `sealed_status: "unknown"` when the images do not establish either state.

Completeness remains a separate concept (`unknown`, `complete`, `believed_complete`, `incomplete`) and should not be used to encode seal uncertainty. Legacy `sealed_unverified` values are normalized to `unknown` on import.
