-- Version 4 : le site web n'est plus affiché nulle part dans l'ERP.

DELETE FROM settings WHERE key = 'company_site';
