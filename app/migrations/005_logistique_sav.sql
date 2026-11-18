-- Version 5 : livraisons détaillées (trajet, marchandise, où elle se trouve, historique),
-- réparations & SAV, imports des fichiers Excel (références anti-doublon).

ALTER TABLE shipments ADD COLUMN holder TEXT NOT NULL DEFAULT 'depart';   -- depart | transporteur | technicien | client | retour
ALTER TABLE shipments ADD COLUMN holder_note TEXT DEFAULT '';             -- précision : « chez TPS Gafsa », « chez Omar »…
ALTER TABLE shipments ADD COLUMN origin_note TEXT DEFAULT '';             -- départ hors showroom : fournisseur, dépôt Mégrine…
ALTER TABLE shipments ADD COLUMN technician TEXT DEFAULT '';              -- installateur / technicien
ALTER TABLE shipments ADD COLUMN technician_fee INTEGER NOT NULL DEFAULT 0; -- prime du technicien
ALTER TABLE shipments ADD COLUMN client_fee INTEGER NOT NULL DEFAULT 0;   -- frais de transport payés par le client
ALTER TABLE shipments ADD COLUMN import_ref TEXT;
CREATE UNIQUE INDEX IF NOT EXISTS shipments_import ON shipments(import_ref) WHERE import_ref IS NOT NULL;
UPDATE shipments SET holder = CASE status WHEN 'en_cours' THEN 'transporteur' WHEN 'livree' THEN 'client' WHEN 'retour' THEN 'retour' ELSE 'depart' END;

-- Marchandise transportée
CREATE TABLE IF NOT EXISTS shipment_items (
  id          INTEGER PRIMARY KEY,
  shipment_id INTEGER NOT NULL REFERENCES shipments(id) ON DELETE CASCADE,
  product_id  INTEGER REFERENCES products(id) ON DELETE SET NULL,
  label       TEXT NOT NULL,
  qty         INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS shipment_items_ship ON shipment_items(shipment_id);
INSERT INTO shipment_items (shipment_id, product_id, label, qty)
  SELECT sh.id, si.product_id, si.label, si.qty FROM shipments sh JOIN sale_items si ON si.sale_id = sh.sale_id;

-- Historique d'une livraison (statut, lieu de la marchandise, argent reçu…)
CREATE TABLE IF NOT EXISTS shipment_events (
  id          INTEGER PRIMARY KEY,
  shipment_id INTEGER NOT NULL REFERENCES shipments(id) ON DELETE CASCADE,
  at          TEXT NOT NULL,
  kind        TEXT NOT NULL DEFAULT 'info',                 -- info | ok | err
  text        TEXT NOT NULL,
  user_id     INTEGER REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS shipment_events_ship ON shipment_events(shipment_id);
INSERT INTO shipment_events (shipment_id, at, kind, text, user_id)
  SELECT id, COALESCE(created_at, updated_at, datetime('now', 'localtime')), 'info', 'Livraison créée', user_id FROM shipments;

-- Textes de départ sans le mot « démonstration »
UPDATE tasks SET title = 'Vidéo de présentation du tapis BT 500+ en showroom' WHERE title = 'Vidéo démonstration du tapis BT 500+ en showroom';
UPDATE carriers SET notes = 'Camion FitnessTN : livraison + installation + mise en service.' WHERE notes = 'Camion FitnessTN : livraison + installation + démonstration.';

-- Ventes, dépenses : lignes venant d'un fichier Excel
ALTER TABLE sales ADD COLUMN import_ref TEXT;
CREATE UNIQUE INDEX IF NOT EXISTS sales_import ON sales(import_ref) WHERE import_ref IS NOT NULL;
ALTER TABLE expenses ADD COLUMN import_ref TEXT;
CREATE UNIQUE INDEX IF NOT EXISTS expenses_import ON expenses(import_ref) WHERE import_ref IS NOT NULL;

-- Réparations & service après-vente
CREATE TABLE IF NOT EXISTS repairs (
  id           INTEGER PRIMARY KEY,
  number       TEXT NOT NULL UNIQUE,                        -- SAV-26-00001
  kind         TEXT NOT NULL DEFAULT 'reparation',          -- reparation (payante) | sav (garantie)
  location_id  INTEGER REFERENCES locations(id),
  client_id    INTEGER REFERENCES clients(id) ON DELETE SET NULL,
  client_name  TEXT DEFAULT '',
  phone        TEXT DEFAULT '',
  phone2       TEXT DEFAULT '',
  gouvernorat  TEXT DEFAULT '',
  address      TEXT DEFAULT '',
  product_id   INTEGER REFERENCES products(id) ON DELETE SET NULL,
  product      TEXT DEFAULT '',                             -- machine concernée (texte libre)
  issue        TEXT DEFAULT '',                             -- panne décrite par le client
  technician   TEXT DEFAULT '',                             -- réparateur
  status       TEXT NOT NULL DEFAULT 'nouveau',             -- nouveau | en_cours | repare | annule
  place        TEXT NOT NULL DEFAULT 'domicile',            -- domicile (chez le client) | atelier
  amount       INTEGER NOT NULL DEFAULT 0,                  -- montant facturé (diagnostic, déplacement, réparation), millimes
  paid         INTEGER NOT NULL DEFAULT 0,                  -- montant encaissé, millimes
  appointment  TEXT,                                        -- rendez-vous (aaaa-mm-jj hh:mm)
  received_at  TEXT,                                        -- date de la demande
  done_at      TEXT,                                        -- date de la réparation
  note         TEXT DEFAULT '',
  import_ref   TEXT,
  user_id      INTEGER REFERENCES users(id),
  created_at   TEXT,
  updated_at   TEXT
);
CREATE INDEX IF NOT EXISTS repairs_status ON repairs(status);
CREATE INDEX IF NOT EXISTS repairs_phone ON repairs(phone);
CREATE UNIQUE INDEX IF NOT EXISTS repairs_import ON repairs(import_ref) WHERE import_ref IS NOT NULL;
