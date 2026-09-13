# LEGO Resale v1.0 Alpha.3

© 2026 Chris Donaldson. Licensed under the [MIT License](LICENSE) — free to use, modify, and reuse.

## Privacy note for anyone running their own instance

This repo contains only application code. Real inventory, customer records,
sale history, and photos live in a local SQLite database and a local
`storage/` folder that are excluded from git via `.gitignore` — they are
never committed, and no real personal or transaction data has ever been
part of this repository's history. If you fork or run your own instance,
your own data stays local the same way; just make sure any new
data-bearing files you add stay covered by `.gitignore` too.

Alpha.3 completes the first end-to-end Model-Assisted Import path.

## New in alpha.3
- Import a single complete **LRX v1 ZIP package**, not a bare JSON file.
- Server finds and validates the LRX manifest inside the ZIP.
- Referenced photos are staged by original filename.
- Proposal preview shows photo-match counts and condition/valuation data.
- Approving a proposal now performs one transaction that:
  1. creates the unique physical inventory unit,
  2. assigns the exact catalog ID,
  3. stores condition fields,
  4. copies the exact physical-unit photos into `storage/photos/<unit UUID>/`,
  5. creates `unit_photos` records,
  6. creates a time-stamped valuation observation from the LRX valuation block.
- The first photo becomes the primary photo.
- If a proposal represents multiple physical copies, alpha.3 refuses to commit unless the LRX package explicitly groups photos per physical unit.
- AI data remains a proposal until approval.

## Upgrade from alpha.2.1
1. Back up the site.
2. Preserve `storage/lego_resale.sqlite` and `storage/photos/`.
3. Replace application files.
4. Run `UPGRADE_alpha3.php`.
5. Delete/rename the upgrade script.
6. Use **Model-Assisted Import → Upload LRX package**.

## Important
Old prototype JSON/ZIP formats are not silently accepted. v1 alpha.3 expects `lego-resale-exchange-v1`.

## Catalog
The alpha.2 full local Rebrickable catalog, set composition, exact part/color data, and updater remain intact.

## Accounts (alpha.3.13+)
Every page requires sign-in. There is no self-registration screen — accounts are created from the server via CLI:
```
php create_user.php <username> <password> owner        # full access, incl. money and customers
php create_user.php <username> <password> contributor   # inventory/photos only, no $ figures
```
Running it again for an existing username updates that user's password/role. Sign in at `login.php`.


## Alpha.3.1 analysis rule
Future LRX packages should explicitly use a `units` array for every catalog proposal, including quantity one. This makes physical-unit grouping reviewable and prevents multiple copies of the same set from being collapsed into one photo pool.


## Alpha.3.2
The LRX importer now rejects packages that do not explicitly validate physical-unit grouping. This closes the failure mode discovered with 70815-1 and 10246-1, where multiple physical copies were initially collapsed into one proposal.


## Alpha.3.3 — visual proposal review
Model-Assisted Import now shows the actual staged photos before approval, grouped by proposed physical unit. Thumbnails are served securely through PHP and can be clicked for a larger view. Missing referenced files are shown explicitly. This makes human review of unit grouping and condition possible before authoritative inventory is created.


## Alpha.3.3.1 — browser cache fix
The HTML now loads versioned `app.js` and `styles.css` URLs. This prevents a browser from continuing to execute an older proposal renderer after an application upgrade. The staged-photo gallery code from Alpha 3.3 is unchanged; this patch ensures the browser actually loads it.


## Alpha.3.4 — valuation/POV workflow
- Restores BrickLink Part Out Value retrieval in the v1 rewrite.
- Exact catalog IDs such as `75019-1` are translated to BrickLink `itemNo=75019&itemSeq=1`.
- `Fetch BrickLink POV + save` retrieves sold-based and current-for-sale POV, creates a new time-stamped valuation observation, and immediately recalculates Ask / Target / Minimum.
- `Save valuation + recalculate` saves any manually edited MSRP/market/POV fields before pricing.
- The unit screen now displays the timestamp of the latest saved valuation, reducing ambiguity about whether edits persisted.
- The existing pricing engine continues to treat sold-based POV as a support/confidence signal rather than a recommendation to part out the set.

Planned UI enhancement: batch-level **Accept/Approve All Valid** after individual proposal review is proven stable.


## Alpha.3.5 — inventory valuation dashboard
The Inventory tab now summarizes active physical inventory using each unit's latest valuation/pricing records:
- Active physical units
- Total original MSRP
- Total current market value
- Total current asking price

Totals include units with status `available`, `listed`, or `reserved`; sold/archived units are excluded. Each metric also shows data coverage (for example, 6/7 units currently have a saved Ask) so a partial total is not mistaken for complete portfolio value.


## Alpha.3.6 — sold vs unsold inventory dashboard
The Inventory tab now has two valuation summary groups.

**Unsold inventory**:
- active units
- total original MSRP
- total current market value
- total current Ask

**Sold inventory**:
- units sold
- original MSRP of sold units
- last saved market value
- total listed price
- gross sold total
- net proceeds after recorded fees and shipping

This keeps projected inventory value separate from realized sales.


## Alpha.3.7 — automatic POV enrichment queue
- LRX approvals and manual unit creation automatically queue the exact catalog set.
- POV is cached per unique set for 7 days, so duplicate physical copies share one external lookup.
- One unique set is processed about every 10 seconds while the app is open.
- Queue state persists in SQLite; closing the browser does not lose work.
- Successful POV results are written as new valuation observations for all active copies of that set, preserving MSRP/whole-market values, then Ask/Target/Minimum are recalculated automatically.
- Failures retry with backoff up to 3 attempts.
- Inventory shows queue status, recent POV results, Queue missing/stale POV, and Force refresh all POV.


## Alpha.3.7.1 hotfix
Corrects a JavaScript packaging error in the Alpha 3.7 POV queue worker that prevented `app.js` from parsing and left the UI stuck on `Loading…`. No queue logic or database model was changed.


## Alpha.3.8 — public listing cleanup + regeneration
- Internal notes, AI review comments, seal-verification instructions, confidence language, and workflow directions are not copied into public Marketplace drafts.
- Public text is derived from structured fields only.
- Existing draft listings can be regenerated in place after condition or pricing changes.
- Regeneration refreshes title, description, and Ask price.
- Non-draft historical listings are never overwritten.

## Alpha.3.9
- Treats a positively identified sealed unit publicly as **Factory Sealed**; "unverified" is not a public condition.
- Unknown remains the state when photos do not establish sealed/opened.
- Existing photo roles are visible and editable in the unit gallery.
- Facebook draft adds Copy title, Copy description, Share photos, and Open Facebook controls.
- Share photos uses the mobile Web Share API where supported; Facebook still requires the user to complete/confirm the Marketplace post.


## Alpha 3.9.1 — sealed-state correction
- Removes `sealed_unverified` from the active completeness UI.
- Legacy database values are normalized to `unknown`.
- Incoming legacy LRX values are normalized on import.
- Seal state is represented only by `sealed / opened / unknown`.
- Completeness remains independent: `unknown / complete / believed_complete / incomplete`.

## Alpha 3.9.2
- Fixes `j.photos is not iterable` in Facebook Share Photos.
- Uses the current physical unit's `x.photos` array.
- Uses `photo_api.php?action=view` to retrieve the exact stored unit photos.
- Adds clear errors for no linked photos or failed photo retrieval.

## Alpha 3.9.3
- Fixes `+` characters appearing where spaces belong in copied/shared Facebook text.
- Normalizes URL-encoded title/description text before clipboard/share-sheet use.
- Preserves original source photo filenames when sharing.
- Duplicate filenames are only suffixed when necessary within one share payload.

## Alpha 3.11 — eBay assisted listing
- Separate eBay draft per physical unit; no selling API required.
- 80-character title editor/counter, concise description, item specifics, eBay-specific price/shipping, Product Research/sold-search links, research notes, and one-photo-at-a-time posting workflow.

## Alpha 3.11.3 — photo delete hotfix
- Fixes Delete failing because `photo_api.php` called an unavailable `body_json()` helper.
- The photo endpoint now validates POST, parses the JSON request body locally, and returns explicit JSON errors.
- Existing deletion behavior remains: remove DB record, remove stored file, renumber remaining photos, promote the first remaining photo to primary.

## Alpha 3.11.4 — verified photo delete fix
- Keeps the corrected local JSON parsing in `photo_api.php`.
- Restores the missing Delete control in the exact-unit gallery.
- Delete removes the database row and stored image, renumbers remaining photos, and promotes the first remaining image to primary.

## Alpha 3.12 — inventory-to-sale lifecycle
- Header version is populated from the server's `APP_VERSION`, reducing stale/mismatched version labels.
- Manual/mobile photo capture now completes end-to-end, with exact-unit attachment, correct ordering/primary behavior, editable roles, and optional AI review/export marking.
- Mark Sold workflow snapshots Ask / Target / Minimum / market reference into the sale record before moving the exact physical unit to sold.
- Customer records and set/unit interests are supported.
- Listing performance snapshots record clicks, saves, shares, and inquiries over time.
- Sold reporting compares projected Ask/Target against actual realized sale revenue.
- Bundled database includes the real $320 sale of 10243-1-A Parisian Restaurant and Rob West / Race Ready Diecast's interest in 10246-1 without inventing an offer price or exact A/B unit preference.

## Alpha 3.12.1 — editable customer records
- Adds an Edit button to each customer card.
- Existing customer name, organization, email, phone, Marketplace profile/handle, and notes can be updated in one form.
- Updates preserve the existing customer ID, linked interests, and linked sale history.
- The same customer form is reused for new customer creation so all supported contact fields can be entered at creation time.

## Alpha 3.13 — accounts/roles scaffold, Mark Sold redesign, portfolio dashboard
- **Accounts**: every endpoint now requires sign-in (`login.php` / `logout.php`, session-based). Two roles: `owner` (full access, including money and customer data) and `contributor` (create/edit units, upload photos, edit condition — no valuations, pricing, listings, customers, or Mark Sold). Owner-only actions are enforced server-side, and `unit_get`/`units` strip money/customer fields from the API response entirely for contributors, not just in the UI. Accounts are provisioned with the CLI-only `create_user.php` (no self-registration screen by design).
- **Validation fixes**: `mark_sold` and `interest_add` previously coerced a non-numeric price to `$0` instead of rejecting it. Both now require a real number server-side (`require_numeric`/`optional_numeric` in `config.php`), and Mark Sold additionally rejects a sold price of zero or less. `unit_update` now validates `status`/`condition_state`/`sealed_status`/`box_condition`/`instructions_status`/`completeness` against an explicit allow-list instead of writing any string sent to it.
- **Mark Sold redesign**: replaced the chained `prompt()`/`confirm()` flow with a proper dialog — sold price/platform/fees/shipping/date, a buyer picker (search existing customers or fill in a new buyer inline), and a free-text "buyer notes / interests" field. A new buyer is created automatically from the inline fields; the notes are stored on the sale and appended (timestamped) to the customer's own notes so they're visible and searchable from the Customers tab. "Add customer interest" got the same dialog treatment, replacing its prompt chain.
- **Customer search**: the Customers tab has a search box that filters across name, organization, email, phone, and all notes (including interest notes) — the mechanism that makes the free-text buyer notes above actually useful later.
- **Portfolio dashboard**: the Inventory tab's "Unsold inventory" / "Sold inventory" blocks are replaced with a unified portfolio view — total units, total MSRP invested, and total portfolio value (remaining market value + realized net) up front, then a Remaining-vs-Sold comparison. The previous granular breakdown is still there, collapsed under "Full breakdown" as a drill-down.
