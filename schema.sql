
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS app_meta (
  meta_key TEXT PRIMARY KEY,
  meta_value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS themes (
  theme_id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  parent_id INTEGER NULL
);

CREATE TABLE IF NOT EXISTS colors (
  color_id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  rgb TEXT,
  is_trans INTEGER NOT NULL DEFAULT 0,
  external_bricklink_id TEXT NULL
);

CREATE TABLE IF NOT EXISTS part_categories (
  category_id INTEGER PRIMARY KEY,
  name TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS parts (
  part_num TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  part_cat_id INTEGER NULL,
  part_material TEXT NULL,
  FOREIGN KEY(part_cat_id) REFERENCES part_categories(category_id)
);

CREATE TABLE IF NOT EXISTS catalog_sets (
  set_num TEXT PRIMARY KEY,                  -- exact identifier, e.g. 76058-1
  base_set_num TEXT NOT NULL,                -- e.g. 76058
  variant TEXT NULL,                         -- e.g. 1
  name TEXT NOT NULL,
  year INTEGER NULL,
  theme_id INTEGER NULL,
  num_parts INTEGER NULL,
  image_url TEXT NULL,
  source TEXT NOT NULL DEFAULT 'manual',
  source_updated_at TEXT NULL,
  FOREIGN KEY(theme_id) REFERENCES themes(theme_id)
);
CREATE INDEX IF NOT EXISTS idx_catalog_sets_base ON catalog_sets(base_set_num);
CREATE INDEX IF NOT EXISTS idx_catalog_sets_name ON catalog_sets(name);
CREATE INDEX IF NOT EXISTS idx_catalog_sets_theme ON catalog_sets(theme_id);

CREATE TABLE IF NOT EXISTS set_inventory_parts (
  set_num TEXT NOT NULL,
  part_num TEXT NOT NULL,
  color_id INTEGER NOT NULL,
  quantity INTEGER NOT NULL,
  is_spare INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY(set_num, part_num, color_id, is_spare),
  FOREIGN KEY(set_num) REFERENCES catalog_sets(set_num) ON DELETE CASCADE,
  FOREIGN KEY(part_num) REFERENCES parts(part_num),
  FOREIGN KEY(color_id) REFERENCES colors(color_id)
);

CREATE TABLE IF NOT EXISTS inventory_units (
  unit_id TEXT PRIMARY KEY,                  -- immutable machine ID
  item_type TEXT NOT NULL DEFAULT 'set',     -- set, loose_lot, accessory, minifigure, merchandise, unknown
  catalog_set_num TEXT NULL,
  display_code TEXT NOT NULL UNIQUE,         -- e.g. 76058-1-A
  status TEXT NOT NULL DEFAULT 'available',  -- available, listed, reserved, sold, archived
  condition_state TEXT NOT NULL DEFAULT 'unknown', -- new, used_excellent, used_good, used_worn, damaged, unknown
  sealed_status TEXT NOT NULL DEFAULT 'unknown',   -- sealed, opened, unknown
  box_condition TEXT NOT NULL DEFAULT 'unknown',
  instructions_status TEXT NOT NULL DEFAULT 'unknown',
  completeness TEXT NOT NULL DEFAULT 'unknown',
  storage_location TEXT NULL,
  notes TEXT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  FOREIGN KEY(catalog_set_num) REFERENCES catalog_sets(set_num)
);
CREATE INDEX IF NOT EXISTS idx_units_catalog ON inventory_units(catalog_set_num);
CREATE INDEX IF NOT EXISTS idx_units_status ON inventory_units(status);

CREATE TABLE IF NOT EXISTS unit_photos (
  photo_id TEXT PRIMARY KEY,
  unit_id TEXT NOT NULL,
  filename TEXT NOT NULL,
  stored_filename TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'unknown',      -- front, back, side, seal, damage, contents...
  is_primary INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL,
  FOREIGN KEY(unit_id) REFERENCES inventory_units(unit_id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_photos_unit ON unit_photos(unit_id);

CREATE TABLE IF NOT EXISTS loose_lots (
  unit_id TEXT PRIMARY KEY,
  estimated_piece_count INTEGER NULL,
  appraisal_confidence REAL NULL,
  FOREIGN KEY(unit_id) REFERENCES inventory_units(unit_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS loose_part_observations (
  observation_id TEXT PRIMARY KEY,
  unit_id TEXT NOT NULL,
  part_num TEXT NULL,
  color_id INTEGER NULL,
  decoration_state TEXT NOT NULL DEFAULT 'unknown', -- plain, printed, stickered, sticker_residue, unknown
  condition_state TEXT NOT NULL DEFAULT 'unknown',  -- new, used_excellent, used_good, used_worn, damaged, unknown
  quantity INTEGER NOT NULL DEFAULT 1,
  confidence REAL NULL,
  notes TEXT NULL,
  FOREIGN KEY(unit_id) REFERENCES inventory_units(unit_id) ON DELETE CASCADE,
  FOREIGN KEY(part_num) REFERENCES parts(part_num),
  FOREIGN KEY(color_id) REFERENCES colors(color_id)
);

CREATE TABLE IF NOT EXISTS valuations (
  valuation_id TEXT PRIMARY KEY,
  unit_id TEXT NOT NULL,
  observed_at TEXT NOT NULL,
  currency TEXT NOT NULL DEFAULT 'USD',
  source_label TEXT NULL,
  msrp REAL NULL,
  whole_new REAL NULL,
  whole_used REAL NULL,
  part_out_sold REAL NULL,
  part_out_for_sale REAL NULL,
  notes TEXT NULL,
  FOREIGN KEY(unit_id) REFERENCES inventory_units(unit_id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_valuations_unit_date ON valuations(unit_id, observed_at DESC);

CREATE TABLE IF NOT EXISTS pricing_recommendations (
  pricing_id TEXT PRIMARY KEY,
  unit_id TEXT NOT NULL,
  valuation_id TEXT NULL,
  calculated_at TEXT NOT NULL,
  market_reference REAL NULL,
  pov_ratio REAL NULL,
  ask_price REAL NULL,
  target_price REAL NULL,
  minimum_price REAL NULL,
  strategy TEXT NOT NULL DEFAULT 'sell_complete',
  explanation_json TEXT NULL,
  FOREIGN KEY(unit_id) REFERENCES inventory_units(unit_id) ON DELETE CASCADE,
  FOREIGN KEY(valuation_id) REFERENCES valuations(valuation_id)
);

CREATE TABLE IF NOT EXISTS listings (
  listing_id TEXT PRIMARY KEY,
  unit_id TEXT NOT NULL,
  platform TEXT NOT NULL DEFAULT 'facebook_marketplace',
  status TEXT NOT NULL DEFAULT 'draft',
  title TEXT NOT NULL,
  description TEXT NOT NULL,
  asking_price REAL NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  FOREIGN KEY(unit_id) REFERENCES inventory_units(unit_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sales (
  sale_id TEXT PRIMARY KEY,
  unit_id TEXT NOT NULL UNIQUE,
  platform TEXT NULL,
  listed_price REAL NULL,
  sold_price REAL NOT NULL,
  listed_at TEXT NULL,
  sold_at TEXT NOT NULL,
  fees REAL NULL DEFAULT 0,
  shipping REAL NULL DEFAULT 0,
  notes TEXT NULL,
  FOREIGN KEY(unit_id) REFERENCES inventory_units(unit_id)
);

CREATE TABLE IF NOT EXISTS import_batches (
  import_id TEXT PRIMARY KEY,
  external_batch_id TEXT NULL,
  schema_version TEXT NOT NULL,
  generator TEXT NULL,
  source_type TEXT NOT NULL DEFAULT 'model_assisted',
  filename TEXT NULL,
  status TEXT NOT NULL DEFAULT 'preview',
  created_at TEXT NOT NULL,
  committed_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS import_proposals (
  proposal_id TEXT PRIMARY KEY,
  import_id TEXT NOT NULL,
  item_type TEXT NOT NULL DEFAULT 'set',
  proposed_set_num TEXT NULL,
  proposed_name TEXT NULL,
  confidence REAL NULL,
  review_required INTEGER NOT NULL DEFAULT 1,
  payload_json TEXT NOT NULL,
  decision TEXT NOT NULL DEFAULT 'pending',  -- pending, approved, rejected
  committed_unit_id TEXT NULL,
  FOREIGN KEY(import_id) REFERENCES import_batches(import_id) ON DELETE CASCADE,
  FOREIGN KEY(committed_unit_id) REFERENCES inventory_units(unit_id)
);


-- v1.0 alpha.2 catalog composition layer
CREATE TABLE IF NOT EXISTS catalog_inventories (
  inventory_id INTEGER PRIMARY KEY,
  version INTEGER NOT NULL,
  set_num TEXT NOT NULL,
  ref_type TEXT NOT NULL DEFAULT 'set' -- set, minifig, or other Rebrickable inventory owner
);
CREATE INDEX IF NOT EXISTS idx_catalog_inventories_set ON catalog_inventories(set_num, version DESC);

CREATE TABLE IF NOT EXISTS catalog_minifigs (
  fig_num TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  num_parts INTEGER NULL,
  image_url TEXT NULL
);

CREATE TABLE IF NOT EXISTS catalog_inventory_parts (
  inventory_id INTEGER NOT NULL,
  part_num TEXT NOT NULL,
  color_id INTEGER NOT NULL,
  quantity INTEGER NOT NULL,
  is_spare INTEGER NOT NULL DEFAULT 0,
  image_url TEXT NULL,
  PRIMARY KEY(inventory_id, part_num, color_id, is_spare),
  FOREIGN KEY(inventory_id) REFERENCES catalog_inventories(inventory_id) ON DELETE CASCADE,
  FOREIGN KEY(part_num) REFERENCES parts(part_num),
  FOREIGN KEY(color_id) REFERENCES colors(color_id)
);
CREATE INDEX IF NOT EXISTS idx_cip_part_color ON catalog_inventory_parts(part_num, color_id);
CREATE INDEX IF NOT EXISTS idx_cip_inventory ON catalog_inventory_parts(inventory_id);

CREATE TABLE IF NOT EXISTS catalog_inventory_minifigs (
  inventory_id INTEGER NOT NULL,
  fig_num TEXT NOT NULL,
  quantity INTEGER NOT NULL,
  PRIMARY KEY(inventory_id, fig_num),
  FOREIGN KEY(inventory_id) REFERENCES catalog_inventories(inventory_id) ON DELETE CASCADE,
  FOREIGN KEY(fig_num) REFERENCES catalog_minifigs(fig_num)
);
CREATE INDEX IF NOT EXISTS idx_cim_fig ON catalog_inventory_minifigs(fig_num);

CREATE TABLE IF NOT EXISTS catalog_inventory_sets (
  inventory_id INTEGER NOT NULL,
  set_num TEXT NOT NULL,
  quantity INTEGER NOT NULL,
  PRIMARY KEY(inventory_id, set_num),
  FOREIGN KEY(inventory_id) REFERENCES catalog_inventories(inventory_id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_cis_set ON catalog_inventory_sets(set_num);


-- v1.0 alpha.3 LRX package staging
CREATE TABLE IF NOT EXISTS import_files (
  import_file_id TEXT PRIMARY KEY,
  import_id TEXT NOT NULL,
  original_filename TEXT NOT NULL,
  staged_path TEXT NOT NULL,
  file_type TEXT NOT NULL DEFAULT 'photo',
  created_at TEXT NOT NULL,
  FOREIGN KEY(import_id) REFERENCES import_batches(import_id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_import_files_import ON import_files(import_id);
CREATE INDEX IF NOT EXISTS idx_import_files_name ON import_files(import_id, original_filename);

CREATE TABLE IF NOT EXISTS pov_cache (set_num TEXT PRIMARY KEY,condition_code TEXT NOT NULL DEFAULT 'N',sold_average REAL,current_average REAL,sold_items INTEGER,sold_lots INTEGER,current_items INTEGER,current_lots INTEGER,checked_at TEXT NOT NULL,source_url TEXT,last_error TEXT);
CREATE TABLE IF NOT EXISTS pov_queue (queue_id TEXT PRIMARY KEY,set_num TEXT NOT NULL UNIQUE,status TEXT NOT NULL DEFAULT 'pending',attempts INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,next_attempt_at TEXT,last_error TEXT);
CREATE INDEX IF NOT EXISTS idx_pov_queue_status_next ON pov_queue(status,next_attempt_at);

-- v1.0 alpha.3.11 eBay assisted drafts
CREATE TABLE IF NOT EXISTS ebay_drafts (
 unit_id TEXT PRIMARY KEY, title TEXT, description TEXT, suggested_price REAL,
 shipping_mode TEXT NOT NULL DEFAULT 'buyer_pays', estimated_shipping REAL,
 research_query TEXT, research_notes TEXT, item_specifics_json TEXT, updated_at TEXT NOT NULL,
 FOREIGN KEY(unit_id) REFERENCES inventory_units(unit_id) ON DELETE CASCADE
);


-- v1.0 alpha.3.12 customer/sales/listing-performance lifecycle
CREATE TABLE IF NOT EXISTS customers (
  customer_id TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  organization TEXT NULL,
  email TEXT NULL,
  phone TEXT NULL,
  marketplace_profile TEXT NULL,
  notes TEXT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS customer_interests (
  interest_id TEXT PRIMARY KEY,
  customer_id TEXT NOT NULL,
  catalog_set_num TEXT NULL,
  unit_id TEXT NULL,
  status TEXT NOT NULL DEFAULT 'interested',
  target_price REAL NULL,
  notes TEXT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  FOREIGN KEY(customer_id) REFERENCES customers(customer_id) ON DELETE CASCADE,
  FOREIGN KEY(catalog_set_num) REFERENCES catalog_sets(set_num),
  FOREIGN KEY(unit_id) REFERENCES inventory_units(unit_id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_customer_interests_set ON customer_interests(catalog_set_num);
CREATE TABLE IF NOT EXISTS listing_metrics (
  metric_id TEXT PRIMARY KEY,
  listing_id TEXT NOT NULL,
  observed_at TEXT NOT NULL,
  clicks INTEGER NOT NULL DEFAULT 0,
  saves INTEGER NOT NULL DEFAULT 0,
  shares INTEGER NOT NULL DEFAULT 0,
  inquiries INTEGER NOT NULL DEFAULT 0,
  notes TEXT NULL,
  FOREIGN KEY(listing_id) REFERENCES listings(listing_id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_listing_metrics_listing_date ON listing_metrics(listing_id, observed_at DESC);
-- Existing installs receive unit_photos.ai_review_requested and sales projection/customer columns through config.php migration.

-- v1.0 alpha.3.13 accounts/roles scaffold
CREATE TABLE IF NOT EXISTS users (
  user_id TEXT PRIMARY KEY,
  username TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  display_name TEXT NULL,
  role TEXT NOT NULL DEFAULT 'contributor',
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
-- role is 'owner' (full access incl. money/customers) or 'contributor' (inventory/photos only).
