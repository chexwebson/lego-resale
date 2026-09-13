#!/usr/bin/env python3
"""
Regenerate LEGO Resale Batch 001 for LRX v1 / Alpha 3.2 validation.

Input:
    LRX_BAT_20260812_001_COMPLETE_REV2_SEALED.zip

Output:
    LRX_BAT_20260812_001_ALPHA3_2_CORRECTED.zip

Corrections:
- 70815-1 Super Secret Police Dropship -> 2 physical units
- 10246-1 Detective's Office -> 2 physical units
- Other three catalog products remain 1 physical unit each
- Every proposal receives:
    physical_units
    physical_unit_analysis
    units[]
- Every source photo is assigned exactly once inside its catalog proposal.
"""

from pathlib import Path
import zipfile
import json
import shutil
import copy
import sys

DEFAULT_INPUT = "LRX_BAT_20260812_001_COMPLETE_REV2_SEALED.zip"
DEFAULT_OUTPUT = "LRX_BAT_20260812_001_ALPHA3_2_CORRECTED.zip"

GROUPS = {
    "70815-1": [
        {
            "unit_label": "A",
            "photos": [
                {"filename": "20260811_145302.jpg", "role": "front"},
                {"filename": "20260811_145316.jpg", "role": "back"},
            ],
        },
        {
            "unit_label": "B",
            "photos": [
                {"filename": "20260811_145358.jpg", "role": "front"},
                {"filename": "20260811_145407.jpg", "role": "back"},
            ],
        },
    ],
    "75019-1": [
        {
            "unit_label": "A",
            "photos": [
                {"filename": "20260811_145425.jpg", "role": "front"},
                {"filename": "20260811_145432.jpg", "role": "back"},
                {"filename": "20260811_145442.jpg", "role": "damage"},
            ],
        },
    ],
    "75096-1": [
        {
            "unit_label": "A",
            "photos": [
                {"filename": "20260811_145540.jpg", "role": "front"},
                {"filename": "20260811_145548.jpg", "role": "back"},
            ],
        },
    ],
    "10246-1": [
        {
            "unit_label": "A",
            "photos": [
                {"filename": "20260811_150021.jpg", "role": "front"},
                {"filename": "20260811_150023.jpg", "role": "back"},
                {"filename": "20260811_150036.jpg", "role": "side"},
                {"filename": "20260811_150040.jpg", "role": "side"},
                {"filename": "20260811_150044.jpg", "role": "damage"},
            ],
        },
        {
            "unit_label": "B",
            "photos": [
                {"filename": "20260811_150102.jpg", "role": "front"},
                {"filename": "20260811_150111.jpg", "role": "back"},
                {"filename": "20260811_150114.jpg", "role": "seal"},
                {"filename": "20260811_150117.jpg", "role": "seal"},
            ],
        },
    ],
    "10243-1": [
        {
            "unit_label": "A",
            "photos": [
                {"filename": "20260811_150149.jpg", "role": "front"},
                {"filename": "20260811_150215.jpg", "role": "back"},
                {"filename": "20260811_150216.jpg", "role": "side"},
                {"filename": "20260811_150218.jpg", "role": "damage"},
                {"filename": "20260811_150221.jpg", "role": "side"},
            ],
        },
    ],
}

ANALYSIS = {
    "70815-1": {
        "confidence": 0.99,
        "grouping_basis": [
            "two distinct front/back photo sequences",
            "visible box/edge details differ between the two sequences",
            "photos 145302/145316 form one physical copy and 145358/145407 form another",
        ],
    },
    "75019-1": {
        "confidence": 0.98,
        "grouping_basis": [
            "single continuous front/back/damage sequence",
            "no repeated front/back sequence or second-box evidence",
        ],
    },
    "75096-1": {
        "confidence": 0.99,
        "grouping_basis": [
            "single front/back sequence",
            "no evidence of a second physical box",
        ],
    },
    "10246-1": {
        "confidence": 0.99,
        "grouping_basis": [
            "two distinct photo sequences separated by timestamp break",
            "first sequence contains photos 150021 through 150044",
            "second sequence contains photos 150102 through 150117",
            "box presentation and wear details support two distinct physical copies",
        ],
    },
    "10243-1": {
        "confidence": 0.98,
        "grouping_basis": [
            "single continuous front/back/side/damage sequence",
            "no second front/back sequence or distinct second-box evidence",
        ],
    },
}

def basename(path):
    return Path(path).name

def locate_manifest(extract_dir):
    for p in extract_dir.rglob("*.json"):
        try:
            d = json.loads(p.read_text(encoding="utf-8"))
        except Exception:
            continue
        if d.get("schema") == "lego-resale-exchange-v1":
            return p, d
    raise RuntimeError("No lego-resale-exchange-v1 manifest found in source ZIP.")

def validate_proposal(p):
    set_num = p["catalog"]["set_num"]
    units = p.get("units", [])
    analysis = p.get("physical_unit_analysis", {})
    if p.get("physical_units") != len(units):
        raise RuntimeError(f"{set_num}: physical_units mismatch")
    if analysis.get("units_detected") != len(units):
        raise RuntimeError(f"{set_num}: units_detected mismatch")

    assigned = []
    for u in units:
        if not u.get("photos"):
            raise RuntimeError(f"{set_num}: unit {u.get('unit_label')} has no photos")
        assigned.extend(basename(x["filename"]) for x in u["photos"])

    if len(assigned) != len(set(assigned)):
        raise RuntimeError(f"{set_num}: a photo is assigned more than once")
    if analysis.get("photos_examined") != len(assigned):
        raise RuntimeError(f"{set_num}: photos_examined mismatch")
    if analysis.get("catalog_set") != set_num:
        raise RuntimeError(f"{set_num}: catalog_set mismatch")
    return assigned

def regenerate(input_zip, output_zip):
    input_zip = Path(input_zip)
    output_zip = Path(output_zip)
    work = output_zip.parent / "_batch001_regenerate_work"
    if work.exists():
        shutil.rmtree(work)
    work.mkdir(parents=True)

    with zipfile.ZipFile(input_zip, "r") as z:
        z.extractall(work)

    manifest_path, manifest = locate_manifest(work)

    seen_sets = set()
    all_assigned = []

    for p in manifest.get("proposals", []):
        set_num = p.get("catalog", {}).get("set_num")
        if set_num not in GROUPS:
            raise RuntimeError(f"Unexpected proposal in Batch 001: {set_num}")
        seen_sets.add(set_num)

        original_condition = copy.deepcopy(p.get("condition", {}))
        original_valuation = copy.deepcopy(p.get("valuation", {}))
        original_notes = p.get("notes")

        units = []
        for unit_def in GROUPS[set_num]:
            units.append({
                "unit_label": unit_def["unit_label"],
                "condition": copy.deepcopy(original_condition),
                "photos": copy.deepcopy(unit_def["photos"]),
                "valuation": copy.deepcopy(original_valuation),
                "notes": original_notes,
            })

        photo_count = sum(len(u["photos"]) for u in units)
        p["physical_units"] = len(units)
        p["physical_unit_analysis"] = {
            "catalog_set": set_num,
            "photos_examined": photo_count,
            "units_detected": len(units),
            "confidence": ANALYSIS[set_num]["confidence"],
            "grouping_basis": ANALYSIS[set_num]["grouping_basis"],
        }
        p["units"] = units

        # Remove the old generic physical-unit photo pool. Alpha 3.2 should use units[].
        p.pop("photos", None)

        # Keep proposal-level values for provenance/backward readability, but unit-level
        # condition/valuation are now authoritative for physical-unit creation.
        all_assigned.extend(validate_proposal(p))

    if seen_sets != set(GROUPS):
        raise RuntimeError(f"Expected sets {sorted(GROUPS)}, found {sorted(seen_sets)}")

    if len(all_assigned) != 23:
        raise RuntimeError(f"Expected 23 assigned photos, got {len(all_assigned)}")
    if len(set(all_assigned)) != 23:
        raise RuntimeError("A source photo is assigned to more than one catalog proposal.")

    # Confirm every referenced image is physically present somewhere in the package.
    present_images = {
        p.name for p in work.rglob("*")
        if p.is_file() and p.suffix.lower() in {".jpg", ".jpeg", ".png", ".webp"}
    }
    missing = sorted(set(all_assigned) - present_images)
    if missing:
        raise RuntimeError("Package is missing referenced photos: " + ", ".join(missing))

    manifest["batch"]["revision"] = "alpha3.2-physical-unit-correction"
    manifest["batch"]["revision_note"] = (
        "Batch 001 re-audited for physical-unit count. "
        "70815-1 and 10246-1 each contain two distinct physical copies. "
        "Batch contains 5 catalog products / 7 physical units / 23 uniquely assigned photos."
    )
    manifest["batch"]["catalog_products"] = 5
    manifest["batch"]["physical_units"] = 7

    manifest_path.write_text(
        json.dumps(manifest, indent=2, ensure_ascii=False),
        encoding="utf-8"
    )

    report = [
        "# Batch 001 Alpha 3.2 Regeneration",
        "",
        "Validated physical-unit grouping:",
        "",
        "- 70815-1 Super Secret Police Dropship: 2 units",
        "- 75019-1 AT-TE: 1 unit",
        "- 75096-1 Sith Infiltrator: 1 unit",
        "- 10246-1 Detective's Office: 2 units",
        "- 10243-1 Parisian Restaurant: 1 unit",
        "",
        "**Total: 5 catalog products, 7 physical units, 23 photos.**",
        "",
        "Every photo is assigned exactly once.",
    ]
    (work / "PHYSICAL_UNIT_VALIDATION.md").write_text("\n".join(report), encoding="utf-8")

    if output_zip.exists():
        output_zip.unlink()

    with zipfile.ZipFile(output_zip, "w", zipfile.ZIP_DEFLATED) as z:
        for p in work.rglob("*"):
            if p.is_file():
                z.write(p, p.relative_to(work))

    shutil.rmtree(work)
    return output_zip

if __name__ == "__main__":
    input_path = Path(sys.argv[1]) if len(sys.argv) > 1 else Path(DEFAULT_INPUT)
    output_path = Path(sys.argv[2]) if len(sys.argv) > 2 else Path(DEFAULT_OUTPUT)
    result = regenerate(input_path, output_path)
    print(f"Created: {result}")
