-- Les ventes TiBillet deja recopiees dans Dolibarr (mode « articles et ventes »).
-- Une ligne par vente. Garde contre les doublons (vente_uuid) et curseur dans une
-- cloture (numero_vente). fk_facture : la facture ou l'avoir cree, NULL pour une vente
-- sans facture (recharge seule, vidage de carte).
-- / TiBillet sales already copied into Dolibarr ("items and sales" mode).

CREATE TABLE llx_tibillet_vente_importee (
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	vente_uuid varchar(36) NOT NULL,
	numero_vente integer NOT NULL,
	cloture_uuid varchar(36) NOT NULL,
	fk_facture integer DEFAULT NULL,
	date_import datetime NOT NULL
) ENGINE=innodb;
