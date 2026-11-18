-- Version 6 : X calcule ses réponses dans l'ERP, sans service extérieur ni clé.
DELETE FROM settings WHERE key = 'x_api_key';
