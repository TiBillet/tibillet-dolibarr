-- Les clotures TiBillet deja importees dans le grand livre.
-- Une ligne par cloture journaliere (J). Sert de curseur (numero) et de garde contre
-- les doublons (cloture_uuid). pieces = les piece_num ecrits, separes par des virgules.
-- mode = « ecritures » (grand livre) ou « ventes » (factures, pieces vide).
-- / TiBillet closures already imported into the general ledger.

CREATE TABLE llx_tibillet_cloture_importee (
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	cloture_uuid varchar(36) NOT NULL,
	numero integer NOT NULL,
	date_comptable date NOT NULL,
	date_import datetime NOT NULL,
	pieces varchar(255) NOT NULL,
	mode varchar(16) DEFAULT 'ecritures' NOT NULL
) ENGINE=innodb;
