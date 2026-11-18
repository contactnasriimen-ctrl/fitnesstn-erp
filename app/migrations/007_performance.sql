-- Version 7 : index pour accélérer les listes et les calculs (clients, ventes, livraisons, paiements, réparations, X).
CREATE INDEX IF NOT EXISTS sales_client ON sales(client_id);
CREATE INDEX IF NOT EXISTS sales_status_date ON sales(status, created_at);
CREATE INDEX IF NOT EXISTS sale_items_product ON sale_items(product_id);
CREATE INDEX IF NOT EXISTS payments_sale ON payments(sale_id);
CREATE INDEX IF NOT EXISTS payments_date ON payments(created_at);
CREATE INDEX IF NOT EXISTS shipments_sale ON shipments(sale_id);
CREATE INDEX IF NOT EXISTS shipments_client ON shipments(client_id);
CREATE INDEX IF NOT EXISTS shipments_carrier ON shipments(carrier_id);
CREATE INDEX IF NOT EXISTS shipments_status ON shipments(status, holder);
CREATE INDEX IF NOT EXISTS repairs_client ON repairs(client_id);
CREATE INDEX IF NOT EXISTS expenses_date ON expenses(date);
CREATE INDEX IF NOT EXISTS clients_created ON clients(created_at);
CREATE INDEX IF NOT EXISTS audit_log_date ON audit_log(created_at);
ANALYZE;
