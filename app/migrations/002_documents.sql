-- Version 2 : devis et factures. Montants en millimes, prix des lignes TTC (comme en caisse).

CREATE TABLE IF NOT EXISTS documents (
  id             INTEGER PRIMARY KEY,
  type           TEXT NOT NULL,                   -- devis | facture
  number         TEXT NOT NULL UNIQUE,
  location_id    INTEGER REFERENCES locations(id),
  client_id      INTEGER REFERENCES clients(id) ON DELETE SET NULL,
  client_name    TEXT DEFAULT '',                 -- copie figée des coordonnées au moment du document
  client_phone   TEXT DEFAULT '',
  client_email   TEXT DEFAULT '',
  client_address TEXT DEFAULT '',
  client_mf      TEXT DEFAULT '',
  date           TEXT NOT NULL,
  due_date       TEXT,                            -- validité (devis) / échéance (facture)
  status         TEXT NOT NULL,                   -- devis : brouillon|envoye|accepte|refuse|facture ; facture : emise|partielle|payee|annulee
  subtotal       INTEGER NOT NULL DEFAULT 0,      -- somme des lignes TTC
  discount       INTEGER NOT NULL DEFAULT 0,      -- remise globale TTC
  total_ht       INTEGER NOT NULL DEFAULT 0,
  total_vat      INTEGER NOT NULL DEFAULT 0,
  total          INTEGER NOT NULL DEFAULT 0,      -- TTC hors timbre
  timbre         INTEGER NOT NULL DEFAULT 0,
  net            INTEGER NOT NULL DEFAULT 0,      -- net à payer (TTC + timbre)
  paid           INTEGER NOT NULL DEFAULT 0,
  terms          TEXT DEFAULT '',
  transport      TEXT DEFAULT '',
  warranty       TEXT DEFAULT '',
  notes          TEXT DEFAULT '',                 -- imprimée sur le document
  internal_note  TEXT DEFAULT '',
  source_id      INTEGER REFERENCES documents(id) ON DELETE SET NULL,
  sale_id        INTEGER REFERENCES sales(id) ON DELETE SET NULL,
  stock_applied  INTEGER NOT NULL DEFAULT 0,
  created_by     INTEGER REFERENCES users(id),
  created_at     TEXT,
  updated_at     TEXT,
  sent_at        TEXT,
  closed_at      TEXT,
  cancelled_at   TEXT,
  cancelled_by   INTEGER REFERENCES users(id),
  cancel_reason  TEXT DEFAULT ''
);
CREATE INDEX IF NOT EXISTS documents_type_date ON documents(type, date);
CREATE INDEX IF NOT EXISTS documents_client ON documents(client_id);
CREATE INDEX IF NOT EXISTS documents_sale ON documents(sale_id);

CREATE TABLE IF NOT EXISTS document_items (
  id              INTEGER PRIMARY KEY,
  document_id     INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
  position        INTEGER NOT NULL DEFAULT 0,
  product_id      INTEGER REFERENCES products(id) ON DELETE SET NULL,
  label           TEXT NOT NULL,
  description     TEXT DEFAULT '',
  qty             INTEGER NOT NULL DEFAULT 1,
  unit_price      INTEGER NOT NULL DEFAULT 0,     -- P.U. TTC
  discount_pct    REAL NOT NULL DEFAULT 0,
  vat_rate        REAL NOT NULL DEFAULT 19,
  total           INTEGER NOT NULL DEFAULT 0,     -- total ligne TTC, remise déduite
  warranty_months INTEGER
);
CREATE INDEX IF NOT EXISTS document_items_doc ON document_items(document_id);

CREATE TABLE IF NOT EXISTS document_payments (
  id          INTEGER PRIMARY KEY,
  document_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
  date        TEXT NOT NULL,
  method      TEXT NOT NULL,
  amount      INTEGER NOT NULL,
  reference   TEXT DEFAULT '',
  user_id     INTEGER REFERENCES users(id),
  created_at  TEXT
);
CREATE INDEX IF NOT EXISTS document_payments_doc ON document_payments(document_id);

ALTER TABLE clients ADD COLUMN mf TEXT DEFAULT '';
