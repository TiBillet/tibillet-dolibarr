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
 * changer a la place du lieu (une option du lieu, un droit, une banque) est seulement
 * signale ($manques) : l'import ne demarre pas tant qu'il reste un manque.
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
	 * Le code de paiement Dolibarr de chaque moyen TiBillet (D22). Chaque type de
	 * monnaie TiBillet a son code, cree par le module : monnaie locale en euros (TIBL),
	 * monnaie cadeau (TIBG), FED (TIBF). Le FED n'est pas VAD : ce sont des jetons
	 * depenses chez le lieu, pas un paiement par carte. Tout autre moyen prend « TIB ».
	 * / Dolibarr payment code of each TiBillet method; one code per currency type,
	 * anything else is "TIB".
	 */
	const CODE_PAR_MOYEN = array(
		'CA' => 'LIQ',
		'CC' => 'CB',
		'CH' => 'CHQ',
		'TR' => 'VIR',
		'SN' => 'VAD',
		'SP' => 'VAD',
		'SR' => 'VAD',
		'LE' => 'TIBL',
		'LG' => 'TIBG',
		'SF' => 'TIBF',
	);

	/** Le code des autres moyens TiBillet / Code of any other TiBillet method */
	const CODE_DES_AUTRES_MOYENS = 'TIB';

	/**
	 * Le libelle (cle de traduction) des codes que le module cree.
	 * / Label (translation key) of the codes the module creates.
	 */
	const LIBELLE_PAR_CODE_CREE = array(
		'TIBL' => 'TiBilletLibelleDuCodeTibl',
		'TIBG' => 'TiBilletLibelleDuCodeTibg',
		'TIBF' => 'TiBilletLibelleDuCodeTibf',
		'TIB' => 'TiBilletLibelleDuCodeTib',
	);

	/** @var string[] Ce que le module a regle / What the module set */
	public $faits = array();

	/** @var string[] Ce qui manque encore, a regler par le lieu / Still missing */
	public $manques = array();

	/** @var array<string,int> id de code de paiement deja lu / Payment code ids read */
	private $id_par_code = array();

	/**
	 * Verifie Dolibarr avant un import des ventes, et regle ce que le module peut
	 * regler (client de passage). Chaque compte de tresorerie de TiBillet doit avoir
	 * sa banque (verifierLesBanques). Rien de la comptabilite de Dolibarr : ni comptes
	 * generaux, ni journaux.
	 * / Checks Dolibarr before a sales import and sets what the module can set; never
	 * Dolibarr's accounting.
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
		foreach (array('facture' => 'Factures', 'societe' => 'Tiers') as $module => $nom) {
			if (!isModEnabled($module)) {
				$this->manques[] = $langs->transnoentities('TiBilletModuleInactif', $nom);
			}
		}

		// Les options du lieu qui changeraient les dates ou le stock : signalees.
		// / Venue options that would change dates or stock: reported.
		foreach (array('FAC_FORCE_DATE_VALIDATION', 'INVOICE_CHECK_POSTERIOR_DATE', 'STOCK_CALCULATE_ON_BILL') as $option) {
			if (getDolGlobalString($option)) {
				$this->manques[] = $langs->transnoentities('TiBilletOptionAEteindre', $option);
			}
		}

		// Le droit de l'utilisateur qui lance l'import (le cron, ou la personne).
		// / The right of the user running the import.
		if (!$user->hasRight('facture', 'creer')) {
			$this->manques[] = $langs->transnoentities('TiBilletDroitFactureManquant', $user->login);
		}

		if (isModEnabled('societe') && $this->tiersDePassage($user) < 0) {
			$this->manques[] = $this->error;
		}

		$this->verifierLesBanques();

		return empty($this->manques) ? 0 : -1;
	}

	/**
	 * Chaque compte de tresorerie de TiBillet a-t-il sa banque dans Dolibarr ? Le
	 * module n'en cree jamais : un compte sans banque est un manque, que le lieu regle
	 * en creant la banque ou en la reliant sur la page du module. Appele par les trois
	 * boutons (tester, catalogue, ventes) et par la tache de nuit.
	 * / Does each TiBillet treasury account have its bank? The module never creates
	 * one: a missing bank is reported.
	 *
	 * @return int 0 si chaque compte a sa banque, -1 sinon (voir $this->manques)
	 */
	public function verifierLesBanques()
	{
		global $langs;
		$langs->loadLangs(array('tibillet@tibillet'));

		if (!isModEnabled('banque')) {
			$this->manques[] = $langs->transnoentities('TiBilletModuleInactif', 'Banques');
			return -1;
		}
		$comptes_de_tresorerie = $this->comptesDeTresorerie();
		if ($comptes_de_tresorerie === null) {
			$this->manques[] = $this->error;
			return -1;
		}
		$il_manque_une_banque = false;
		foreach ($comptes_de_tresorerie as $numero_tibillet => $libelle_tibillet) {
			if ($this->banquePourLeCompte($numero_tibillet) <= 0) {
				$this->manques[] = $langs->transnoentities('TiBilletCompteSansBanque', $numero_tibillet.' « '.$libelle_tibillet.' »');
				$il_manque_une_banque = true;
			}
		}
		return $il_manque_une_banque ? -1 : 0;
	}

	/**
	 * Les comptes de tresorerie de TiBillet : ceux ou l'argent d'une vente arrive
	 * (caisse, CB, Stripe, monnaies, avances des clients), marques `treasury` dans le
	 * plan de TiBillet.
	 * / TiBillet's treasury accounts, flagged `treasury` in its chart.
	 *
	 * @return array<string,string>|null numero TiBillet => libelle, ou null (voir $this->error)
	 */
	public function comptesDeTresorerie()
	{
		$reponse = $this->appelerTiBillet('/api/v2/accounts/');
		if ($reponse['code'] != 200 || !isset($reponse['donnees']['results']) || !is_array($reponse['donnees']['results'])) {
			$this->error = $this->phraseDuRefus($reponse);
			return null;
		}
		$comptes_de_tresorerie = array();
		foreach ($reponse['donnees']['results'] as $compte_tibillet) {
			if (!empty($compte_tibillet['treasury'])) {
				$comptes_de_tresorerie[(string) $compte_tibillet['accountNumber']] = (string) $compte_tibillet['name'];
			}
		}
		return $comptes_de_tresorerie;
	}

	/**
	 * La banque Dolibarr d'un compte de tresorerie TiBillet (D11) : celle que le lieu a
	 * choisie sur la page du module (constante TIBILLET_BANQUE_<numero>), sinon la
	 * banque ouverte qui porte le meme numero de compte. Les zeros de droite ne
	 * comptent pas : 530000, 5300 et 53 sont le meme compte. Une banque de caisse
	 * n'accepte que les especes : jamais retenue pour un autre compte que 53x. Le
	 * module ne cree jamais de banque.
	 * / The Dolibarr bank of a TiBillet treasury account: chosen, or the open bank with
	 * the same account number (trailing zeros ignored). Never created.
	 *
	 * @param string $numero_tibillet Le numero TiBillet (ex. 530000)
	 * @return int rowid de llx_bank_account, ou -1 (voir $this->error)
	 */
	public function banquePourLeCompte($numero_tibillet)
	{
		global $langs;
		$langs->loadLangs(array('tibillet@tibillet'));

		$nom_du_lien = 'TIBILLET_BANQUE_'.preg_replace('/[^0-9A-Za-z]/', '', (string) $numero_tibillet);
		$id_choisi = getDolGlobalInt($nom_du_lien);
		if ($id_choisi > 0) {
			$banque_choisie = new Account($this->db);
			if ($banque_choisie->fetch($id_choisi) > 0) {
				return $id_choisi;
			}
		}

		$numero_sans_zeros = rtrim(trim((string) $numero_tibillet), '0');
		$sql = "SELECT rowid FROM ".$this->db->prefix()."bank_account";
		$sql .= " WHERE TRIM(TRAILING '0' FROM TRIM(account_number)) = '".$this->db->escape($numero_sans_zeros)."'";
		$sql .= " AND clos = 0 AND entity IN (".getEntity('bank_account').")";
		if (strpos((string) $numero_tibillet, '53') !== 0) {
			$sql .= " AND courant <> ".((int) Account::TYPE_CASH);
		}
		$sql .= " ORDER BY rowid ASC";
		$resql = $this->db->query($sql);
		$ligne = ($resql && $numero_sans_zeros !== '') ? $this->db->fetch_object($resql) : null;
		if ($ligne) {
			return (int) $ligne->rowid;
		}

		$this->error = $langs->transnoentities('TiBilletCompteSansBanque', $numero_tibillet);
		return -1;
	}

	/**
	 * L'id du code de paiement Dolibarr d'un moyen TiBillet (D22). Une banque de
	 * caisse n'accepte que les especes : toujours LIQ. Le code VAD est active et les
	 * codes TIBL, TIBG, TIBF, TIB crees au premier besoin.
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
	 * Le code de paiement Dolibarr (LIQ, CB, VAD, TIBL…) d'un moyen TiBillet.
	 * / The Dolibarr payment code of a TiBillet method.
	 */
	public function codeDePaiement($moyen_tibillet, $banque_est_une_caisse)
	{
		if ($banque_est_une_caisse) {
			return 'LIQ';
		}
		$codes = self::CODE_PAR_MOYEN;
		return isset($codes[(string) $moyen_tibillet]) ? $codes[(string) $moyen_tibillet] : self::CODE_DES_AUTRES_MOYENS;
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

	// ------------------------------------------------------------------
	// Petits outils / Small helpers
	// ------------------------------------------------------------------

	/**
	 * L'id d'un code de paiement de llx_c_paiement : active s'il est inactif, cree
	 * s'il manque (les codes TIB…). Garde en memoire pour le passage.
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

		// Un code du module (TIBL, TIBG, TIBF, TIB) : « type 2 » = pour les clients et
		// les fournisseurs.
		// / A module code: type 2 = customers and suppliers.
		$cle_du_libelle = self::LIBELLE_PAR_CODE_CREE[$code] ?? 'TiBilletLibelleDuCodeTib';
		$sql = "INSERT INTO ".$this->db->prefix()."c_paiement (entity, code, libelle, type, active, position)";
		$sql .= " VALUES (".((int) $this->entity).", '".$this->db->escape($code)."', '".$this->db->escape($langs->transnoentities($cle_du_libelle))."', 2, 1, 0)";
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
