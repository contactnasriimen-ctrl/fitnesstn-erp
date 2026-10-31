-- Version 3 : paiements de factures rattachés au showroom et, pour les espèces, à la session de caisse ouverte.

ALTER TABLE document_payments ADD COLUMN location_id INTEGER REFERENCES locations(id);
ALTER TABLE document_payments ADD COLUMN session_id INTEGER REFERENCES cash_sessions(id);
CREATE INDEX IF NOT EXISTS document_payments_session ON document_payments(session_id);
UPDATE document_payments SET location_id = (SELECT location_id FROM documents WHERE documents.id = document_payments.document_id);
