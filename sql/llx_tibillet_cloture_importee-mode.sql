-- Le mode dans lequel la cloture a ete importee (« ecritures » ou « ventes ») : une
-- cloture importee dans un mode ne l'est jamais dans l'autre. Pour une base deja
-- installee ; Dolibarr ignore l'erreur « colonne deja la » a la reactivation.
-- / The mode a closure was imported in; never imported in the other one.
ALTER TABLE llx_tibillet_cloture_importee ADD COLUMN mode varchar(16) DEFAULT 'ecritures' NOT NULL;
