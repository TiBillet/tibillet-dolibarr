<?php
/* Copyright (C) 2026 Cooperative TiBillet
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/reglages.class.php
 * \ingroup tibillet
 * \brief   Prepare Dolibarr pour recevoir les ventes de TiBillet (mode « articles et
 *          ventes ») : banques, codes de paiement, comptes de TVA, client de passage.
 *          / Prepares Dolibarr for TiBillet's sales.
 *
 * Le module regle lui-meme ce qui manque et le dit ($faits). Ce qu'il ne doit pas
 * changer a la place du lieu (une option du lieu, un droit) est seulement signale
 * ($manques) : l'import ne demarre pas tant qu'il reste un manque.
 * Spec : depot Lespass, TECH_DOC/SESSIONS/COMPTABILITE/CHANTIER-06-connecteurs-
 * comptables/CHANTIER-06-D-ventes.md (D11, D20-D22, Q8, Q10).
 * / The module sets what is missing and says so; what belongs to the venue is only
 * reported, and blocks the import.
 */

dol_include_once('/tibillet/class/importtibillet.class.php');
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

class ReglagesTiBillet extends ImportTiBillet
{
	/** ref_ext du client de passage cree par le module / Walk-in customer ref_ext */
	const REF_EXT_CLIENT_DE_PASSAGE = 'tibillet:client-de-passage';

	/**
	 * Le code de paiement Dolibarr de chaque moyen TiBillet (D22). Tout autre moyen
	 * (monnaie locale, jetons, Stripe federe…) prend le code « TIB », cree par le module.
	 * / Dolibarr payment code of each TiBillet method; anything else is "TIB".
	 */
	const CODE_PAR_MOYEN = array(
		'CA' => 'LIQ',
		'CC' => 'CB',
		'CH' => 'CHQ',
		'TR' => 'VIR',
		'SN' => 'VAD',
		'SP' => 'VAD',
		'SR' => 'VAD',
	);

	/** Le code des monnaies TiBillet / TiBillet currencies' payment code */
	const CODE_DES_MONNAIES = 'TIB';

	/** @var string[] Ce que le module a regle / What the module set */
	public $faits = array();

	/** @var string[] Ce qui manque encore, a regler par le lieu / Still missing */
	public $manques = array();

	/** @var array<string,int> id de code de paiement deja lu / Payment code ids read */
	private $id_par_code = array();

	/**
	 * Verifie Dolibarr avant un import des ventes, et regle ce que le module peut
	 * regler (comptes generaux par defaut, codes de paiement, client de passage).
	 * / Checks Dolibarr before a sales import and sets what the module can set.
	 *
	 * @param User $user Auteur
	 * @return int 0 si rien ne manque, -1 sinon (voir $this->manques)
	 */
	public function preparer($user)
	{
		global $langs;
		$langs->loadLangs(array('tibillet@tibillet'));

		// Les modules de Dolibarr. La page du module les active au passage en mode
		// « articles et ventes » ; une tache planifiee ne le fait pas.
		// / Dolibarr modules: the module page enables them, a cron job does not.
		foreach (array('facture' => 'Factures', 'societe' => 'Tiers', 'banque' => 'Banques') as $module => $nom) {
			if (!isModEnabled($module)) {
				$this->manques[] = $langs->transnoentities('TiBilletModuleInactif', $nom);
			}
		}

		// Les comptes generaux de Dolibarr : poses s'ils sont vides et que le compte
		// existe dans le plan actif.
		// / Dolibarr's general accounts: set when empty and present in the chart.
		$this->poserUnCompteGeneral('ACCOUNTING_ACCOUNT_CUSTOMER', array('411000', '411'), $user);
		$this->poserUnCompteGeneral('ACCOUNTING_VAT_SOLD_ACCOUNT', array('445711', '44571'), $user);
		$this->poserUnCompteGeneral('ACCOUNTING_ACCOUNT_TRANSFER_CASH', array('580000', '58'), $user);
		// Le journal de banque refuse d'ecrire sans compte fournisseur general, meme
		// pour des encaissements, et sans compte des salaires ou des notes de frais si
		// ces modules sont actifs (accountancy/journal/bankjournal.php).
		// / The bank journal needs the general supplier account (and salaries, expense
		// reports if enabled) to write anything.
		$this->poserUnCompteGeneral('ACCOUNTING_ACCOUNT_SUPPLIER', array('401000', '401'), $user);
		if (isModEnabled('salaries')) {
			$this->poserUnCompteGeneral('SALARIES_ACCOUNTING_ACCOUNT_PAYMENT', array('421000', '421'), $user);
		}
		if (isModEnabled('expensereport')) {
			$this->poserUnCompteGeneral('ACCOUNTING_ACCOUNT_EXPENSEREPORT', array('421000', '421'), $user);
		}

		// Les options du lieu qui changeraient les dates ou le stock : signalees.
		// / Venue options that would change dates or stock: reported.
		foreach (array('FAC_FORCE_DATE_VALIDATION', 'INVOICE_CHECK_POSTERIOR_DATE', 'STOCK_CALCULATE_ON_BILL') as $option) {
			if (getDolGlobalString($option)) {
				$this->manques[] = $langs->transnoentities('TiBilletOptionAEteindre', $option);
			}
		}

		// Un compte pose sur un mode de paiement (Dictionnaires → Modes de paiement)
		// remplace, dans le journal de banque, le compte de la banque
		// (accountancy/journal/bankjournal.php) : le resultat ne serait plus celui du FEC.
		// / An account set on a payment mode replaces the bank's in the bank journal.
		$codes_utilises = array_unique(array_merge(array_values(self::CODE_PAR_MOYEN), array('LIQ', self::CODE_DES_MONNAIES)));
		$liste_des_codes = "'".implode("','", array_map(array($this->db, 'escape'), $codes_utilises))."'";
		$sql = "SELECT code, accountancy_code FROM ".$this->db->prefix()."c_paiement";
		$sql .= " WHERE code IN (".$liste_des_codes.") AND entity IN (".getEntity('c_paiement').")";
		$sql .= " AND accountancy_code IS NOT NULL AND accountancy_code <> ''";
		$resql = $this->db->query($sql);
		while ($resql && ($code_avec_compte = $this->db->fetch_object($resql))) {
			$this->manques[] = $langs->transnoentities('TiBilletCompteSurModeDePaiement', $code_avec_compte->code, $code_avec_compte->accountancy_code);
		}

		// Le droit de l'utilisateur qui lance l'import (le cron, ou la personne).
		// / The right of the user running the import.
		if (!$user->hasRight('facture', 'creer')) {
			$this->manques[] = $langs->transnoentities('TiBilletDroitFactureManquant', $user->login);
		}

		// Le journal de banque, pour les banques creees par le module.
		// / The bank journal, for the banks the module creates.
		if ($this->idDuJournalDeBanque() <= 0) {
			$this->manques[] = $langs->transnoentities('TiBilletJournalBanqueManquant');
		}

		if (isModEnabled('societe') && $this->tiersDePassage($user) < 0) {
			$this->manques[] = $this->error;
		}

		return empty($this->manques) ? 0 : -1;
	}

	/**
	 * La banque Dolibarr d'un compte de tresorerie TiBillet (D11) : celle que le lieu a
	 * choisie (constante TIBILLET_BANQUE_<numero>), sinon une banque qui porte deja ce
	 * compte, sinon une banque creee par le module. Le compte 53x est une caisse.
	 * / The Dolibarr bank of a TiBillet treasury account: chosen, found, or created.
	 *
	 * @param string $numero_tibillet Le numero TiBillet (ex. 530000)
	 * @param User   $user            Auteur
	 * @return int rowid de llx_bank_account, ou -1 (voir $this->error)
	 */
	public function banquePourLeCompte($numero_tibillet, $user)
	{
		global $conf, $langs, $mysoc;

		$nom_du_lien = 'TIBILLET_BANQUE_'.preg_replace('/[^0-9A-Za-z]/', '', (string) $numero_tibillet);
		$id_choisi = getDolGlobalInt($nom_du_lien);
		if ($id_choisi > 0) {
			$banque_choisie = new Account($this->db);
			if ($banque_choisie->fetch($id_choisi) > 0) {
				return $id_choisi;
			}
		}

		$numero_dolibarr = $this->numeroDeCompteDolibarr($numero_tibillet);
		$sql = "SELECT rowid FROM ".$this->db->prefix()."bank_account";
		$sql .= " WHERE account_number = '".$this->db->escape($numero_dolibarr)."'";
		$sql .= " AND clos = 0 AND entity IN (".getEntity('bank_account').")";
		$sql .= " ORDER BY rowid ASC";
		$resql = $this->db->query($sql);
		$ligne = $resql ? $this->db->fetch_object($resql) : null;
		if ($ligne) {
			dolibarr_set_const($this->db, $nom_du_lien, (int) $ligne->rowid, 'chaine', 0, '', $conf->entity);
			return (int) $ligne->rowid;
		}

		$compte_comptable = new AccountingAccount($this->db);
		$libelle_du_compte = ($compte_comptable->fetch(0, $numero_dolibarr, 1) > 0) ? $compte_comptable->label : $numero_tibillet;

		$banque = new Account($this->db);
		$banque->ref = dol_trunc('TIB'.$numero_dolibarr, 12, 'right', 'UTF-8', 1);
		// Le libelle d'une banque fait 30 caracteres au plus : le numero d'abord, il
		// reste lisible meme coupe. / Bank labels are 30 chars max: number first.
		$banque->label = dol_trunc('TiBillet '.$numero_tibillet.' '.$libelle_du_compte, 30, 'right', 'UTF-8', 1);
		$banque->type = (strpos((string) $numero_tibillet, '53') === 0) ? Account::TYPE_CASH : Account::TYPE_CURRENT;
		$banque->courant = $banque->type;
		$banque->country_id = $mysoc->country_id;
		$banque->currency_code = $conf->currency;
		$banque->date_solde = dol_now();
		$banque->account_number = $numero_dolibarr;
		$banque->fk_accountancy_journal = $this->idDuJournalDeBanque();
		$banque->rappro = ($banque->type == Account::TYPE_CASH) ? 0 : 1;
		$id_banque = $banque->create($user);
		if ($id_banque <= 0) {
			$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $banque->error.' '.implode(' ', (array) $banque->errors));
			return -1;
		}
		dolibarr_set_const($this->db, $nom_du_lien, $id_banque, 'chaine', 0, '', $conf->entity);
		$this->faits[] = $langs->transnoentities('TiBilletBanqueCreee', $banque->label);
		return $id_banque;
	}

	/**
	 * L'id du code de paiement Dolibarr d'un moyen TiBillet (D22). Une banque de
	 * caisse n'accepte que les especes : toujours LIQ. Le code VAD est active et le
	 * code TIB cree au premier besoin.
	 * / The Dolibarr payment code id of a TiBillet method; a cash bank only takes LIQ.
	 *
	 * @param string|null $moyen_tibillet         Le code TiBillet (CA, CC, LE…) ou null
	 * @param bool        $banque_est_une_caisse  Vrai pour une banque de type caisse
	 * @return int id de llx_c_paiement, ou -1
	 */
	public function idDuCodeDePaiement($moyen_tibillet, $banque_est_une_caisse)
	{
		return $this->idDuCode($this->codeDePaiement($moyen_tibillet, $banque_est_une_caisse));
	}

	/**
	 * Le code de paiement Dolibarr (LIQ, CB, VAD, TIB…) d'un moyen TiBillet.
	 * / The Dolibarr payment code of a TiBillet method.
	 */
	public function codeDePaiement($moyen_tibillet, $banque_est_une_caisse)
	{
		if ($banque_est_une_caisse) {
			return 'LIQ';
		}
		$codes = self::CODE_PAR_MOYEN;
		return isset($codes[(string) $moyen_tibillet]) ? $codes[(string) $moyen_tibillet] : self::CODE_DES_MONNAIES;
	}

	/**
	 * Pose le compte de TVA de TiBillet sur le taux actif de Dolibarr (pays de la
	 * societe), seulement s'il est vide ; active le taux s'il est inactif (D21). Un
	 * compte deja pose par le lieu n'est jamais modifie.
	 * / Sets TiBillet's VAT account on Dolibarr's rate, only when empty.
	 *
	 * @param string $taux            Le taux (ex. « 20.00 »)
	 * @param string $numero_tibillet Le compte de TVA TiBillet (ex. 445711)
	 * @return int 0 si OK, -1 sinon
	 */
	public function poserLeCompteDeTva($taux, $numero_tibillet)
	{
		global $langs, $mysoc;

		$cle = $taux.'|'.$numero_tibillet;
		if (isset($this->id_par_code['tva:'.$cle])) {
			return 0;
		}
		$this->id_par_code['tva:'.$cle] = 1;

		$sql = "SELECT rowid, active, accountancy_code_sell FROM ".$this->db->prefix()."c_tva";
		$sql .= " WHERE fk_pays = ".((int) $mysoc->country_id);
		$sql .= " AND taux = ".((float) $taux)." AND recuperableonly = 0";
		$sql .= " AND entity IN (".getEntity('c_tva').")";
		$sql .= " ORDER BY (code IS NULL OR code = '') DESC, active DESC, rowid ASC";
		$resql = $this->db->query($sql);
		$ligne = $resql ? $this->db->fetch_object($resql) : null;
		if (!$ligne) {
			$this->manques[] = $langs->transnoentities('TiBilletTauxAbsent', $taux);
			return -1;
		}

		$numero_dolibarr = $this->numeroDeCompteDolibarr($numero_tibillet);
		if (!(int) $ligne->active) {
			$this->db->query("UPDATE ".$this->db->prefix()."c_tva SET active = 1 WHERE rowid = ".((int) $ligne->rowid));
			$this->faits[] = $langs->transnoentities('TiBilletTauxActive', $taux);
		}
		if ((string) $ligne->accountancy_code_sell === '') {
			$sql = "UPDATE ".$this->db->prefix()."c_tva SET accountancy_code_sell = '".$this->db->escape($numero_dolibarr)."'";
			$sql .= " WHERE rowid = ".((int) $ligne->rowid);
			$this->db->query($sql);
			$this->faits[] = $langs->transnoentities('TiBilletCompteDeTvaPose', $numero_dolibarr, $taux);
		}
		return 0;
	}

	/**
	 * Le client de passage (D6) : celui que le lieu a choisi (constante
	 * TIBILLET_TIERS_DE_PASSAGE), sinon celui du module, cree s'il manque.
	 * / The walk-in customer: chosen, or the module's own, created if missing.
	 *
	 * @param User $user Auteur
	 * @return int rowid du tiers, ou -1
	 */
	public function tiersDePassage($user)
	{
		global $conf, $langs;

		$id_choisi = getDolGlobalInt('TIBILLET_TIERS_DE_PASSAGE');
		if ($id_choisi > 0) {
			$tiers_choisi = new Societe($this->db);
			if ($tiers_choisi->fetch($id_choisi) > 0) {
				return $id_choisi;
			}
		}

		$sql = "SELECT rowid FROM ".$this->db->prefix()."societe";
		$sql .= " WHERE ref_ext = '".$this->db->escape(self::REF_EXT_CLIENT_DE_PASSAGE)."'";
		$sql .= " AND entity IN (".getEntity('societe').") ORDER BY rowid ASC";
		$resql = $this->db->query($sql);
		$ligne = $resql ? $this->db->fetch_object($resql) : null;
		if ($ligne) {
			$id_du_tiers = (int) $ligne->rowid;
		} else {
			$tiers = new Societe($this->db);
			$tiers->name = $langs->transnoentities('TiBilletClientDePassage');
			$tiers->client = 1;
			$tiers->code_client = -1;
			$tiers->ref_ext = self::REF_EXT_CLIENT_DE_PASSAGE;
			$id_du_tiers = $tiers->create($user);
			if ($id_du_tiers <= 0) {
				$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $tiers->error.' '.implode(' ', (array) $tiers->errors));
				return -1;
			}
			$this->faits[] = $langs->transnoentities('TiBilletClientDePassageCree');
		}
		dolibarr_set_const($this->db, 'TIBILLET_TIERS_DE_PASSAGE', $id_du_tiers, 'chaine', 0, '', $conf->entity);
		return $id_du_tiers;
	}

	/**
	 * Le rowid d'un compte TiBillet dans le plan actif de Dolibarr, ou 0.
	 * / The rowid of a TiBillet account in Dolibarr's active chart, or 0.
	 */
	public function idDuCompteComptable($numero_tibillet)
	{
		$compte = new AccountingAccount($this->db);
		if ($compte->fetch(0, $this->numeroDeCompteDolibarr($numero_tibillet), 1) > 0) {
			return (int) $compte->id;
		}
		return 0;
	}

	// ------------------------------------------------------------------
	// Petits outils / Small helpers
	// ------------------------------------------------------------------

	/**
	 * Pose un compte general de Dolibarr s'il est vide : le premier des numeros
	 * TiBillet proposes qui existe dans le plan actif. Sinon, un manque.
	 * / Sets a Dolibarr general account if empty, from the first existing number.
	 */
	private function poserUnCompteGeneral($constante, $numeros_proposes, $user)
	{
		global $conf, $langs;

		if (getDolGlobalString($constante) !== '') {
			return;
		}
		foreach ($numeros_proposes as $numero) {
			$numero_dolibarr = $this->numeroDeCompteDolibarr($numero);
			$compte = new AccountingAccount($this->db);
			if ($compte->fetch(0, $numero_dolibarr, 1) > 0) {
				dolibarr_set_const($this->db, $constante, $numero_dolibarr, 'chaine', 0, '', $conf->entity);
				$this->faits[] = $langs->transnoentities('TiBilletCompteGeneralPose', $constante, $numero_dolibarr);
				return;
			}
		}
		$this->manques[] = $langs->transnoentities('TiBilletCompteGeneralManquant', $constante);
	}

	/** L'id du journal de banque (code BQ), ou 0 / Bank journal id, or 0 */
	private function idDuJournalDeBanque()
	{
		$journal = new AccountingJournal($this->db);
		return ($journal->fetch(0, 'BQ') > 0) ? (int) $journal->id : 0;
	}

	/**
	 * L'id d'un code de paiement de llx_c_paiement : active s'il est inactif, cree
	 * s'il manque (le code TIB). Garde en memoire pour le passage.
	 * / A payment code id: enabled if inactive, created if missing.
	 */
	private function idDuCode($code)
	{
		global $langs;

		if (isset($this->id_par_code[$code])) {
			return $this->id_par_code[$code];
		}
		$sql = "SELECT id, active FROM ".$this->db->prefix()."c_paiement";
		$sql .= " WHERE code = '".$this->db->escape($code)."' AND entity IN (".getEntity('c_paiement').")";
		$resql = $this->db->query($sql);
		$ligne = $resql ? $this->db->fetch_object($resql) : null;
		if ($ligne) {
			if (!(int) $ligne->active) {
				$this->db->query("UPDATE ".$this->db->prefix()."c_paiement SET active = 1 WHERE id = ".((int) $ligne->id));
				$this->faits[] = $langs->transnoentities('TiBilletCodeDePaiementActive', $code);
			}
			$this->id_par_code[$code] = (int) $ligne->id;
			return (int) $ligne->id;
		}

		// Le code TIB : « type 2 » = pour les clients et les fournisseurs.
		// / The TIB code: type 2 = customers and suppliers.
		$sql = "INSERT INTO ".$this->db->prefix()."c_paiement (entity, code, libelle, type, active, position)";
		$sql .= " VALUES (".((int) $this->entity).", '".$this->db->escape($code)."', '".$this->db->escape($langs->transnoentities('TiBilletLibelleDuCodeTib'))."', 2, 1, 0)";
		if (!$this->db->query($sql)) {
			$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $this->db->lasterror());
			return -1;
		}
		$id_cree = (int) $this->db->last_insert_id($this->db->prefix().'c_paiement', 'id');
		$this->faits[] = $langs->transnoentities('TiBilletCodeDePaiementCree', $code);
		$this->id_par_code[$code] = $id_cree;
		return $id_cree;
	}
}
