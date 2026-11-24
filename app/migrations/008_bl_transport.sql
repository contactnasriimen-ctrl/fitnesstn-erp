-- Version 8 : bons de livraison (documents de type « bl »), modalité de paiement et transporteur
-- (nom, matricule du véhicule, CIN) sur les factures, devis et bons de livraison.

-- Modalités de paiement proposées dans les documents (liste modifiable).
CREATE TABLE IF NOT EXISTS payment_modes (
  id         INTEGER PRIMARY KEY,
  label      TEXT NOT NULL UNIQUE COLLATE NOCASE,
  position   INTEGER NOT NULL DEFAULT 0,
  created_at TEXT
);
INSERT OR IGNORE INTO payment_modes (label, position, created_at) VALUES
  ('Comptant à la livraison', 1, datetime('now', 'localtime')),
  ('Chèque', 2, datetime('now', 'localtime')),
  ('Virement', 3, datetime('now', 'localtime')),
  ('Traite', 4, datetime('now', 'localtime'));

-- Transporteurs imprimés sur les documents : chauffeur et véhicule.
CREATE TABLE IF NOT EXISTS transporters (
  id         INTEGER PRIMARY KEY,
  name       TEXT NOT NULL,
  plate      TEXT NOT NULL DEFAULT '',               -- matricule du véhicule (ex. 245 TU 7788)
  cin        TEXT NOT NULL DEFAULT '',               -- carte d'identité nationale du chauffeur
  created_at TEXT,
  updated_at TEXT
);

-- Copies figées sur le document : renommer ou supprimer un transporteur ne change pas les documents déjà établis.
ALTER TABLE documents ADD COLUMN payment_mode TEXT NOT NULL DEFAULT '';
ALTER TABLE documents ADD COLUMN transporter_id INTEGER REFERENCES transporters(id) ON DELETE SET NULL;
ALTER TABLE documents ADD COLUMN transporter_name TEXT NOT NULL DEFAULT '';
ALTER TABLE documents ADD COLUMN transporter_plate TEXT NOT NULL DEFAULT '';
ALTER TABLE documents ADD COLUMN transporter_cin TEXT NOT NULL DEFAULT '';
CREATE INDEX IF NOT EXISTS documents_source ON documents(source_id);

-- Modalité proposée par défaut sur un nouveau document.
INSERT INTO settings(key, value) VALUES ('invoice_payment_mode', 'Comptant à la livraison') ON CONFLICT(key) DO NOTHING;
INSERT INTO settings(key, value) VALUES ('bl_prefix', 'BL-') ON CONFLICT(key) DO NOTHING;
-- L'ancienne condition par défaut devient la modalité « Comptant à la livraison » : on ne l'imprime pas deux fois.
UPDATE settings SET value = '' WHERE key = 'invoice_terms' AND value = 'Paiement comptant à la livraison';
