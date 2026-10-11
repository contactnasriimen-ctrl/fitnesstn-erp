-- ERP FitnessTN : schéma SQLite. Tous les montants sont en millimes (1 DT = 1000).

CREATE TABLE settings (
  key   TEXT PRIMARY KEY,
  value TEXT
);

CREATE TABLE counters (
  key   TEXT PRIMARY KEY,
  value INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE locations (
  id       INTEGER PRIMARY KEY,
  code     TEXT NOT NULL UNIQUE,
  name     TEXT NOT NULL,
  type     TEXT NOT NULL DEFAULT 'showroom',      -- showroom | depot
  address  TEXT DEFAULT '',
  phone    TEXT DEFAULT '',
  position INTEGER DEFAULT 0,
  active   INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE users (
  id               INTEGER PRIMARY KEY,
  username         TEXT NOT NULL UNIQUE COLLATE NOCASE,
  name             TEXT NOT NULL,
  role             TEXT NOT NULL,                 -- admin | showroom | marketing
  location_id      INTEGER REFERENCES locations(id),
  password_hash    TEXT NOT NULL,
  initial_password INTEGER NOT NULL DEFAULT 0,
  active           INTEGER NOT NULL DEFAULT 1,
  last_login       TEXT,
  created_at       TEXT
);

CREATE TABLE login_attempts (
  id       INTEGER PRIMARY KEY,
  username TEXT,
  ip       TEXT,
  at       INTEGER
);

CREATE TABLE products (
  id              INTEGER PRIMARY KEY,
  woo_id          INTEGER UNIQUE,
  sku             TEXT DEFAULT '',
  barcode         TEXT DEFAULT '',
  name            TEXT NOT NULL,
  family          TEXT DEFAULT 'Accessoires',
  categories      TEXT DEFAULT '',
  brand           TEXT DEFAULT '',
  price           INTEGER NOT NULL DEFAULT 0,     -- prix régulier TTC
  promo_price     INTEGER,                        -- prix promo TTC (NULL = pas de promo)
  cost            INTEGER,                        -- prix d'achat (visible admin)
  warranty_months INTEGER,
  min_stock       INTEGER NOT NULL DEFAULT 0,
  description     TEXT DEFAULT '',
  image           TEXT DEFAULT '',
  image_url       TEXT DEFAULT '',
  web_status      TEXT DEFAULT 'publie',          -- publie | prive | brouillon
  active          INTEGER NOT NULL DEFAULT 1,
  created_at      TEXT,
  updated_at      TEXT
);
CREATE INDEX products_sku ON products(sku);

CREATE TABLE stock (
  product_id  INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  location_id INTEGER NOT NULL REFERENCES locations(id),
  qty         INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (product_id, location_id)
);

CREATE TABLE stock_moves (
  id          INTEGER PRIMARY KEY,
  created_at  TEXT NOT NULL,
  product_id  INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  location_id INTEGER NOT NULL REFERENCES locations(id),
  qty_change  INTEGER NOT NULL,
  qty_after   INTEGER,
  type        TEXT NOT NULL,
  ref         TEXT DEFAULT '',
  note        TEXT DEFAULT '',
  user_id     INTEGER REFERENCES users(id)
);
CREATE INDEX stock_moves_product ON stock_moves(product_id);
CREATE INDEX stock_moves_date ON stock_moves(created_at);

CREATE TABLE clients (
  id          INTEGER PRIMARY KEY,
  name        TEXT NOT NULL,
  phone       TEXT DEFAULT '',
  phone2      TEXT DEFAULT '',
  email       TEXT DEFAULT '',
  gouvernorat TEXT DEFAULT '',
  city        TEXT DEFAULT '',
  address     TEXT DEFAULT '',
  source      TEXT DEFAULT '',
  notes       TEXT DEFAULT '',
  created_at  TEXT,
  updated_at  TEXT
);
CREATE UNIQUE INDEX clients_phone ON clients(phone) WHERE phone <> '';

CREATE TABLE cash_sessions (
  id            INTEGER PRIMARY KEY,
  location_id   INTEGER NOT NULL REFERENCES locations(id),
  opened_by     INTEGER REFERENCES users(id),
  opened_at     TEXT NOT NULL,
  opening_float INTEGER NOT NULL DEFAULT 0,
  status        TEXT NOT NULL DEFAULT 'ouverte',  -- ouverte | fermee
  closed_by     INTEGER REFERENCES users(id),
  closed_at     TEXT,
  expected_cash INTEGER,
  counted_cash  INTEGER,
  difference    INTEGER,
  to_safe       INTEGER,
  envelope      TEXT DEFAULT '',
  count_detail  TEXT DEFAULT '',
  note          TEXT DEFAULT ''
);

CREATE TABLE campaigns (
  id          INTEGER PRIMARY KEY,
  name        TEXT NOT NULL,
  channel     TEXT NOT NULL DEFAULT 'facebook',
  location_id INTEGER REFERENCES locations(id),   -- NULL = tous les showrooms
  product_id  INTEGER REFERENCES products(id) ON DELETE SET NULL,
  objective   TEXT DEFAULT '',
  budget      INTEGER NOT NULL DEFAULT 0,
  spent       INTEGER NOT NULL DEFAULT 0,
  start_date  TEXT,
  end_date    TEXT,
  status      TEXT NOT NULL DEFAULT 'planifiee',
  leads       INTEGER NOT NULL DEFAULT 0,
  messages    INTEGER NOT NULL DEFAULT 0,
  reach       INTEGER NOT NULL DEFAULT 0,
  notes       TEXT DEFAULT '',
  created_by  INTEGER REFERENCES users(id),
  created_at  TEXT,
  updated_at  TEXT
);

CREATE TABLE sales (
  id            INTEGER PRIMARY KEY,
  number        TEXT NOT NULL UNIQUE,
  location_id   INTEGER NOT NULL REFERENCES locations(id),
  session_id    INTEGER REFERENCES cash_sessions(id),
  user_id       INTEGER REFERENCES users(id),
  client_id     INTEGER REFERENCES clients(id),
  created_at    TEXT NOT NULL,
  subtotal      INTEGER NOT NULL DEFAULT 0,       -- somme des lignes
  discount      INTEGER NOT NULL DEFAULT 0,       -- remise globale
  total         INTEGER NOT NULL DEFAULT 0,       -- TTC à payer
  paid          INTEGER NOT NULL DEFAULT 0,
  catalog_total INTEGER NOT NULL DEFAULT 0,       -- valeur au prix catalogue
  status        TEXT NOT NULL DEFAULT 'payee',    -- payee | partielle | annulee
  source        TEXT DEFAULT '',
  campaign_id   INTEGER REFERENCES campaigns(id) ON DELETE SET NULL,
  note          TEXT DEFAULT '',
  cancelled_at  TEXT,
  cancelled_by  INTEGER REFERENCES users(id),
  cancel_reason TEXT DEFAULT ''
);
CREATE INDEX sales_date ON sales(created_at);
CREATE INDEX sales_loc ON sales(location_id);

CREATE TABLE sale_items (
  id         INTEGER PRIMARY KEY,
  sale_id    INTEGER NOT NULL REFERENCES sales(id) ON DELETE CASCADE,
  product_id INTEGER REFERENCES products(id) ON DELETE SET NULL,
  label      TEXT NOT NULL,
  qty        INTEGER NOT NULL DEFAULT 1,
  unit_price INTEGER NOT NULL DEFAULT 0,
  list_price INTEGER NOT NULL DEFAULT 0,
  unit_cost  INTEGER,
  vat_rate   REAL NOT NULL DEFAULT 19,
  total      INTEGER NOT NULL DEFAULT 0,
  warranty_months INTEGER
);
CREATE INDEX sale_items_sale ON sale_items(sale_id);

CREATE TABLE payments (
  id          INTEGER PRIMARY KEY,
  sale_id     INTEGER NOT NULL REFERENCES sales(id) ON DELETE CASCADE,
  session_id  INTEGER REFERENCES cash_sessions(id),
  location_id INTEGER NOT NULL REFERENCES locations(id),
  created_at  TEXT NOT NULL,
  method      TEXT NOT NULL,
  amount      INTEGER NOT NULL,
  reference   TEXT DEFAULT '',
  user_id     INTEGER REFERENCES users(id)
);
CREATE INDEX payments_session ON payments(session_id);

CREATE TABLE expenses (
  id          INTEGER PRIMARY KEY,
  date        TEXT NOT NULL,
  location_id INTEGER REFERENCES locations(id),
  category    TEXT NOT NULL DEFAULT 'divers',
  label       TEXT NOT NULL,
  amount      INTEGER NOT NULL,
  method      TEXT NOT NULL DEFAULT 'especes',
  session_id  INTEGER REFERENCES cash_sessions(id),
  supplier    TEXT DEFAULT '',
  reference   TEXT DEFAULT '',
  note        TEXT DEFAULT '',
  user_id     INTEGER REFERENCES users(id),
  created_at  TEXT
);

CREATE TABLE carriers (
  id          INTEGER PRIMARY KEY,
  name        TEXT NOT NULL,
  type        TEXT NOT NULL DEFAULT 'externe',    -- interne | externe
  phone       TEXT DEFAULT '',
  contact     TEXT DEFAULT '',
  zones       TEXT DEFAULT '',
  default_fee INTEGER NOT NULL DEFAULT 0,
  cod         INTEGER NOT NULL DEFAULT 1,
  notes       TEXT DEFAULT '',
  active      INTEGER NOT NULL DEFAULT 1,
  created_at  TEXT
);

CREATE TABLE shipments (
  id            INTEGER PRIMARY KEY,
  number        TEXT NOT NULL UNIQUE,
  sale_id       INTEGER REFERENCES sales(id) ON DELETE SET NULL,
  client_id     INTEGER REFERENCES clients(id) ON DELETE SET NULL,
  location_id   INTEGER REFERENCES locations(id),
  carrier_id    INTEGER REFERENCES carriers(id) ON DELETE SET NULL,
  recipient     TEXT DEFAULT '',
  phone         TEXT DEFAULT '',
  gouvernorat   TEXT DEFAULT '',
  city          TEXT DEFAULT '',
  address       TEXT DEFAULT '',
  status        TEXT NOT NULL DEFAULT 'a_preparer',
  tracking      TEXT DEFAULT '',
  fee           INTEGER NOT NULL DEFAULT 0,       -- coût payé au transporteur
  cod_amount    INTEGER NOT NULL DEFAULT 0,       -- montant à encaisser chez le client
  cod_collected INTEGER NOT NULL DEFAULT 0,
  planned_date  TEXT,
  delivered_at  TEXT,
  note          TEXT DEFAULT '',
  user_id       INTEGER REFERENCES users(id),
  created_at    TEXT,
  updated_at    TEXT
);

CREATE TABLE boards (
  id          INTEGER PRIMARY KEY,
  key         TEXT NOT NULL UNIQUE,
  name        TEXT NOT NULL,
  kind        TEXT NOT NULL,                      -- marketing | showroom | direction
  location_id INTEGER REFERENCES locations(id),
  position    INTEGER DEFAULT 0
);

CREATE TABLE board_columns (
  id       INTEGER PRIMARY KEY,
  board_id INTEGER NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
  name     TEXT NOT NULL,
  position INTEGER NOT NULL DEFAULT 0,
  is_done  INTEGER NOT NULL DEFAULT 0,
  color    TEXT DEFAULT '#64748b'
);

CREATE TABLE tasks (
  id          INTEGER PRIMARY KEY,
  board_id    INTEGER NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
  column_id   INTEGER NOT NULL REFERENCES board_columns(id),
  title       TEXT NOT NULL,
  description TEXT DEFAULT '',
  assignee_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  priority    TEXT NOT NULL DEFAULT 'normale',
  due_date    TEXT,
  labels      TEXT DEFAULT '',
  position    INTEGER NOT NULL DEFAULT 0,
  created_by  INTEGER REFERENCES users(id),
  created_at  TEXT,
  updated_at  TEXT,
  done_at     TEXT
);
CREATE INDEX tasks_board ON tasks(board_id, column_id, position);

CREATE TABLE audit_log (
  id         INTEGER PRIMARY KEY,
  created_at TEXT NOT NULL,
  user_id    INTEGER,
  action     TEXT NOT NULL,
  entity     TEXT DEFAULT '',
  entity_id  INTEGER,
  details    TEXT DEFAULT ''
);
