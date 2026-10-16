<?php
/* Copyright (C) 2026 Cooperative TiBillet
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/ventes.class.php
 * \ingroup tibillet
 * \brief   Recopie les ventes des clotures de TiBillet dans Dolibarr (mode « articles
 *          et ventes ») : factures payees, avoirs, virements internes, paiements
 *          divers. / Copies TiBillet's closure sales into Dolibarr.
 *
 * TiBillet fait TOUT le calcul (route /api/v2/closures/<uuid>/sales/) : lignes au
 * tarif, comptes, TVA, paiements par compte de tresorerie, virements, paiements
 * divers. Ce module recopie, sans decider. Compte par compte, le resultat est celui
 * du FEC de la cloture.
 *
 * FLUX D'UN PASSAGE (importerLesVentes) :
 * 1. Garde du mode et de la configuration ; comptes manquants crees (comme le mode
 *    ecritures) ; reglages de Dolibarr verifies (ReglagesTiBillet::preparer).
 * 2. Les clotures a importer (meme curseur que le mode ecritures : une cloture n'est
 *    importee que dans un mode).
 * 3. Pour chaque cloture, page apres page, chaque vente dans UNE transaction
 *    (importerUneVente). Une vente refusee arrete TOUT le passage : les numeros de
 *    facture suivent l'ordre des ventes. Le passage va jusqu'a la derniere page ;
 *    seule la limite facultative TIBILLET_VENTES_PAR_PASSAGE le coupe, et le passage
 *    suivant reprend a la vente suivante.
 * 4. Cloture terminee : notee en mode « ventes ».
 * Spec : depot Lespass, TECH_DOC/SESSIONS/COMPTABILITE/CHANTIER-06-connecteurs-
 * comptables/CHANTIER-06-D-ventes.md.
 * / A run: guards, accounts, settings; closures; each sale in one transaction; a
 * refused sale stops the run; only the optional per-run limit cuts it.
 */

dol_include_once('/tibillet/class/importtibillet.class.php');
dol_include_once('/tibillet/class/reglages.class.php');
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/paymentvarious.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/price.lib.php';

class VentesTiBillet extends ImportTiBillet
{
	/** Ventes au plus par page demandee a TiBillet / Sales per requested page */
	const VENTES_PAR_PAGE = 500;

	/** @var ReglagesTiBillet */
	public $reglages;

	/** @var array<string,int> Les compteurs du passage / Run counters */
	private $compteurs = array();

	/** @var string[] Les phrases du passage (lignes libres…) / Run sentences */
	private $phrases_du_passage = array();

	/** @var bool Vrai si le dernier echec vient d'une reference deja prise (D24) */
	private $reference_deja_prise = false;

	/**
	 * Importe les ventes des clotures de TiBillet (le flux en tete du fichier).
	 * / Imports TiBillet's closure sales.
	 *
	 * @return int 0 si tout va bien (ou rien a faire, ou limite atteinte), -1 sinon
	 */
	public function importerLesVentes()
	{
		global $user, $langs, $conf;
		$langs->loadLangs(array('tibillet@tibillet'));

		if (self::modeChoisi() !== self::MODE_VENTES) {
			$this->output = $langs->transnoentities('TiBilletModeEcrituresPasDeVentes');
			return 0;
		}
		if (!$this->moduleEstConfigure()) {
			$this->output = $langs->transnoentities('TiBilletNonConfigure');
			return 0;
		}

		$this->compteurs = array('clotures' => 0, 'factures' => 0, 'avoirs' => 0, 'virements' => 0, 'paiements_divers' => 0, 'ventes' => 0);
		$this->phrases_du_passage = array();

		// 1. Comptes et reglages / Accounts and settings
		if ($this->creerLesComptesManquants($user) < 0) {
			return $this->finirLePassage(-1);
		}
		$this->reglages = new ReglagesTiBillet($this->db);
		if ($this->reglages->preparer($user) < 0) {
			$this->error = $langs->transnoentities('TiBilletReglagesManquants', implode(' ; ', $this->reglages->manques));
			return $this->finirLePassage(-1);
		}

		// Un PDF par paiement ralentirait le passage : coupe EN MEMOIRE seulement, le
		// reglage du lieu n'est pas change (D18).
		// / No PDF per payment, in memory only.
		$conf->global->MAIN_DISABLE_PDF_AUTOUPDATE = 1;

		// Par defaut, pas de limite : le passage suit la pagination de TiBillet jusqu'au
		// bout. TIBILLET_VENTES_PAR_PASSAGE ne sert qu'au serveur qui coupe PHP trop tot
		// (max_execution_time) : le passage s'arrete alors proprement, et dit pourquoi.
		// / No limit by default; the optional constant caps a run on a server that kills
		// PHP too early.
		$limite = getDolGlobalInt('TIBILLET_VENTES_PAR_PASSAGE');

		// 2. Les clotures / Closures
		$reponse = $this->appelerTiBillet('/api/v2/closures/?after_number='.((int) $this->plusGrandNumeroImporte()));
		if ($reponse['code'] != 200 || !isset($reponse['donnees']['results']) || !is_array($reponse['donnees']['results'])) {
			$this->error = $this->phraseDuRefus($reponse);
			return $this->finirLePassage(-1);
		}

		// 3. Une cloture apres l'autre / One closure after the other
		foreach ($reponse['donnees']['results'] as $cloture) {
			if ($this->clotureDejaImportee($cloture['uuid'])) {
				continue;
			}
			$date_de_la_j = $this->dateDolibarr($cloture['accountingDate'] ?? '');
			if ($date_de_la_j === null) {
				$this->error = $langs->transnoentities('TiBilletReponseIllisible');
				return $this->finirLePassage(-1);
			}

			$apres_le_numero = $this->derniereVenteImporteeDeLaCloture($cloture['uuid']);
			while (true) {
				$taille_de_la_page = self::VENTES_PAR_PAGE;
				if ($limite > 0) {
					$reste_a_importer = $limite - $this->compteurs['ventes'];
					if ($reste_a_importer <= 0) {
						$this->phrases_du_passage[] = $langs->transnoentities('TiBilletLimiteAtteinte', $limite);
						return $this->finirLePassage(0);
					}
					$taille_de_la_page = min(self::VENTES_PAR_PAGE, $reste_a_importer);
				}
				$chemin = '/api/v2/closures/'.rawurlencode($cloture['uuid']).'/sales/?after_number='.((int) $apres_le_numero).'&limit='.((int) $taille_de_la_page);
				$page = $this->appelerTiBillet($chemin);
				if ($page['code'] != 200 || !isset($page['donnees']['results']) || !is_array($page['donnees']['results'])) {
					$this->error = $this->phraseDuRefus($page);
					return $this->finirLePassage(-1);
				}
				foreach ($page['donnees']['results'] as $vente) {
					if ($this->importerUneVente($vente, $cloture['uuid'], $date_de_la_j, $user) < 0) {
						return $this->finirLePassage(-1);
					}
					$apres_le_numero = (int) $vente['orderNumber'];
				}
				if (empty($page['donnees']['nextAfterNumber'])) {
					break;
				}
				$apres_le_numero = (int) $page['donnees']['nextAfterNumber'];
			}

			// 4. Cloture terminee / Closure done
			if ($this->noterLaCloture($cloture['uuid'], $cloture, $date_de_la_j, array(), null, self::MODE_VENTES) < 0) {
				return $this->finirLePassage(-1);
			}
			$this->compteurs['clotures']++;
		}
		return $this->finirLePassage(0);
	}

	// ------------------------------------------------------------------
	// Une vente / One sale
	// ------------------------------------------------------------------

	/**
	 * Recopie une vente dans UNE transaction : tiers, facture ou avoir (lignes,
	 * validation, controle au centime, paiements), virements internes, paiements
	 * divers, puis la ligne de la table du module. Deja importee : rien. Si un humain
	 * a pris le numero de facture au meme instant, la vente est rejouee une fois (D24).
	 * / Copies one sale in ONE transaction; replayed once on a taken reference.
	 *
	 * @param array  $vente        La vente publiee par TiBillet
	 * @param string $cloture_uuid L'uuid de sa cloture
	 * @param int    $date_de_la_j La date comptable de la J, a midi
	 * @param User   $user         Auteur
	 * @return int 1 si recopiee, 0 si deja la, -1 si refusee (voir $this->error)
	 */
	private function importerUneVente($vente, $cloture_uuid, $date_de_la_j, $user)
	{
		global $langs;

		if (empty($vente['identifier']) || !isset($vente['orderNumber'])) {
			$this->error = $langs->transnoentities('TiBilletReponseIllisible');
			return -1;
		}
		if ($this->venteDejaImportee($vente['identifier'])) {
			return 0;
		}

		for ($essai = 1; $essai <= 2; $essai++) {
			$this->reference_deja_prise = false;
			$compteurs_avant = $this->compteurs;
			$this->db->begin();
			if ($this->ecrireLaVente($vente, $cloture_uuid, $date_de_la_j, $user) > 0) {
				$this->db->commit();
				$this->compteurs['ventes']++;
				return 1;
			}
			$this->db->rollback();
			$this->compteurs = $compteurs_avant;
			if (!$this->reference_deja_prise) {
				break;
			}
		}
		$this->error = $langs->transnoentities('TiBilletVenteRefusee', $vente['orderNumber'], $this->error);
		return -1;
	}

	/**
	 * Le contenu de la transaction d'une vente (voir importerUneVente).
	 * / The content of a sale's transaction.
	 *
	 * @return int 1 si OK, -1 sinon
	 */
	private function ecrireLaVente($vente, $cloture_uuid, $date_de_la_j, $user)
	{
		$id_de_la_facture = null;
		if (!empty($vente['orderedItem'])) {
			$id_du_tiers = $this->tiersDeLaVente($vente['customer'] ?? null, $user);
			if ($id_du_tiers < 0) {
				return -1;
			}
			$id_de_la_facture = $this->creerLaFacture($vente, $id_du_tiers, $date_de_la_j, $user);
			if ($id_de_la_facture < 0) {
				return -1;
			}
		}
		foreach (($vente['transfers'] ?? array()) as $virement) {
			if ($this->virer($virement, $date_de_la_j, $user) < 0) {
				return -1;
			}
		}
		foreach (($vente['variousPayments'] ?? array()) as $paiement_divers) {
			if ($this->paiementDivers($paiement_divers, $date_de_la_j, $user) < 0) {
				return -1;
			}
		}
		return $this->noterLaVente($vente, $cloture_uuid, $id_de_la_facture);
	}

	/**
	 * Le tiers de la vente : retrouve par e-mail, sans tenir compte des majuscules,
	 * le plus ancien s'il y en a plusieurs (Q3) ; cree s'il manque, avec l'e-mail
	 * comme nom (Q4). Sans acheteur : le client de passage (D6).
	 * / The sale's third party, by e-mail (oldest first), created if missing.
	 *
	 * @param array|null $client {identifier, email} ou null
	 * @param User       $user   Auteur
	 * @return int rowid du tiers, ou -1
	 */
	private function tiersDeLaVente($client, $user)
	{
		global $langs;

		$email = is_array($client) ? trim((string) ($client['email'] ?? '')) : '';
		if ($email === '') {
			return $this->reglages->tiersDePassage($user);
		}

		$sql = "SELECT rowid FROM ".$this->db->prefix()."societe";
		$sql .= " WHERE LOWER(email) = '".$this->db->escape(dol_strtolower($email))."'";
		$sql .= " AND entity IN (".getEntity('societe').")";
		$sql .= " ORDER BY rowid ASC";
		$resql = $this->db->query($sql);
		$ligne = $resql ? $this->db->fetch_object($resql) : null;
		if ($ligne) {
			return (int) $ligne->rowid;
		}

		$tiers = new Societe($this->db);
		$tiers->name = $email;
		$tiers->email = $email;
		$tiers->client = 1;
		$tiers->code_client = -1;
		$tiers->typent_id = (int) dol_getIdFromCode($this->db, 'TE_PRIVATE', 'c_typent', 'code', 'id');
		$tiers->ref_ext = 'tibillet:user:'.($client['identifier'] ?? '');
		$id_du_tiers = $tiers->create($user);
		if ($id_du_tiers <= 0) {
			$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $tiers->error.' '.implode(' ', (array) $tiers->errors));
			return -1;
		}
		return $id_du_tiers;
	}

	/**
	 * Cree, valide et paie la facture (ou l'avoir) d'une vente. Le total TTC, HT et TVA
	 * de Dolibarr doit egaler celui de TiBillet au centime (D8), sinon la vente est
	 * refusee.
	 * / Creates, validates and pays the invoice (or credit note); totals must match.
	 *
	 * @return int rowid de la facture, ou -1
	 */
	private function creerLaFacture($vente, $id_du_tiers, $date_de_la_j, $user)
	{
		global $langs;

		$est_un_avoir = (($vente['nature'] ?? '') === 'refund');

		$facture = new Facture($this->db);
		$facture->socid = $id_du_tiers;
		$facture->date = $date_de_la_j;
		$facture->type = $est_un_avoir ? Facture::TYPE_CREDIT_NOTE : Facture::TYPE_STANDARD;
		$facture->ref_ext = $vente['identifier'];
		$facture->note_private = $langs->transnoentities('TiBilletNoteDeLaFacture', $vente['orderNumber']);
		if ($est_un_avoir && !empty($vente['relatedSale']['identifier'])) {
			$id_de_la_facture_d_origine = $this->factureDeLaVente($vente['relatedSale']['identifier']);
			if ($id_de_la_facture_d_origine > 0) {
				$facture->fk_facture_source = $id_de_la_facture_d_origine;
			}
		}
		if ($facture->create($user) <= 0) {
			return $this->erreurDolibarr($facture);
		}

		$totaux_de_tibillet = array('ttc' => 0, 'ht' => 0, 'tva' => 0);
		foreach ($vente['orderedItem'] as $ligne) {
			if ($this->ajouterUneLigne($facture, $ligne, $est_un_avoir) < 0) {
				return -1;
			}
			$totaux_de_tibillet['ttc'] += (int) $ligne['totalTTC'];
			$totaux_de_tibillet['ht'] += (int) $ligne['totalHT'];
			$totaux_de_tibillet['tva'] += (int) $ligne['totalVAT'];
		}

		if ($facture->validate($user) <= 0) {
			$this->reference_deja_prise = ($this->db->lasterrno() === 'DB_ERROR_RECORD_ALREADY_EXISTS');
			return $this->erreurDolibarr($facture);
		}

		// Le centime de TiBillet fait foi (il est scelle dans la J) : Dolibarr
		// recalcule la TVA depuis le prix HT et peut s'ecarter d'un centime.
		// / TiBillet's cent is the truth; Dolibarr may drift by one.
		$facture->fetch($facture->id);
		$totaux_de_dolibarr = array(
			'ttc' => (int) round((float) $facture->total_ttc * 100),
			'ht' => (int) round((float) $facture->total_ht * 100),
			'tva' => (int) round((float) $facture->total_tva * 100),
		);
		if ($totaux_de_dolibarr !== $totaux_de_tibillet) {
			$this->error = $langs->transnoentities(
				'TiBilletTotauxDifferents',
				price2num($totaux_de_dolibarr['ttc'] / 100),
				price2num($totaux_de_dolibarr['ht'] / 100),
				price2num($totaux_de_tibillet['ttc'] / 100),
				price2num($totaux_de_tibillet['ht'] / 100)
			);
			return -1;
		}

		foreach (($vente['invoicePayments'] ?? array()) as $paiement_tibillet) {
			if ($this->payerLaFacture($facture, $paiement_tibillet, $date_de_la_j, $user) < 0) {
				return -1;
			}
		}

		$this->compteurs[$est_un_avoir ? 'avoirs' : 'factures']++;
		return (int) $facture->id;
	}

	/**
	 * Ajoute une ligne a la facture, en TTC, liee d'avance a son compte de vente
	 * (fk_code_ventilation) : le comptable n'a rien a lier. Quantite x prix si Dolibarr
	 * retombe sur le TTC de TiBillet, sinon une ligne de quantite 1 au TTC, la vraie
	 * quantite dans le libelle (D8). Sans produit Dolibarr pour le tarif : une ligne
	 * libre, au meme compte, et une phrase dans le resume.
	 * / Adds a TTC line pre-bound to its sales account.
	 *
	 * @return int 1 si OK, -1 sinon
	 */
	private function ajouterUneLigne($facture, $ligne, $est_un_avoir)
	{
		global $langs, $mysoc;

		$id_du_compte = $this->reglages->idDuCompteComptable($ligne['accountingAccount']);
		if ($id_du_compte <= 0) {
			$this->error = $langs->transnoentities('TiBilletCompteAbsentDuPlan', $ligne['accountingAccount']);
			return -1;
		}
		$taux = (float) $ligne['vatRate'];
		if ($taux != 0 && !empty($ligne['vatAccountingAccount'])) {
			$this->reglages->poserLeCompteDeTva($ligne['vatRate'], $ligne['vatAccountingAccount']);
		}

		$id_du_produit = 0;
		$type_de_ligne = Product::TYPE_SERVICE;
		if (!empty($ligne['priceIdentifier'])) {
			$id_du_produit = $this->produitParRefExt($ligne['priceIdentifier']);
			if ($id_du_produit <= 0) {
				$this->phrases_du_passage[] = $langs->transnoentities('TiBilletLigneLibre', $ligne['name']);
				$id_du_produit = 0;
			}
		}

		// Un avoir : Dolibarr veut une quantite positive et met lui-meme le prix en
		// negatif. Une facture : le signe reste sur le prix.
		// / A credit note: positive quantity, Dolibarr negates the price.
		$total_ttc = ((int) $ligne['totalTTC']) / 100;
		$quantite = abs((float) $ligne['quantity']);
		$prix_unitaire_ttc = ((int) $ligne['unitPrice']) / 100;
		if ($est_un_avoir) {
			$total_ttc = abs($total_ttc);
			$prix_unitaire_ttc = abs($prix_unitaire_ttc);
		} elseif ($total_ttc < 0) {
			$prix_unitaire_ttc = -abs($prix_unitaire_ttc);
		}
		$libelle = (string) $ligne['name'];
		$calcul = calcul_price_total($quantite, $prix_unitaire_ttc, 0, $taux, 0, 0, 0, 'TTC', 0, $type_de_ligne, $mysoc);
		$quantite_et_prix_tombent_juste = $quantite > 0 && (int) round((float) $calcul[2] * 100) === (int) round($total_ttc * 100);
		if (!$quantite_et_prix_tombent_juste) {
			$libelle = $libelle.' ('.price2num($quantite).' × '.price(abs($prix_unitaire_ttc)).')';
			$quantite = 1;
			$prix_unitaire_ttc = $total_ttc;
		}

		$resultat = $facture->addline(
			$libelle,           // desc
			0,                  // pu_ht (prix saisi en TTC)
			$quantite,          // qty
			$taux,              // txtva
			0,                  // txlocaltax1
			0,                  // txlocaltax2
			$id_du_produit,     // fk_product
			0,                  // remise_percent
			'',                 // date_start
			'',                 // date_end
			$id_du_compte,      // fk_code_ventilation : la ligne est liee d'avance
			0,                  // info_bits
			0,                  // fk_remise_except
			'TTC',              // price_base_type
			$prix_unitaire_ttc, // pu_ttc
			$type_de_ligne      // type (remplace par celui du produit s'il y en a un)
		);
		if ($resultat <= 0) {
			return $this->erreurDolibarr($facture);
		}
		return 1;
	}

	/**
	 * Un paiement de la facture, porte en banque : le circuit de TakePOS
	 * (Paiement::create, addPaymentToBank). La facture passe « payee » quand plus rien
	 * n'est du.
	 * / One invoice payment, into the bank, like TakePOS.
	 *
	 * @return int 1 si OK, -1 sinon
	 */
	private function payerLaFacture($facture, $paiement_tibillet, $date_de_la_j, $user)
	{
		$banque = $this->banqueDuCompte($paiement_tibillet['treasuryAccount'], $user);
		if ($banque === null) {
			return -1;
		}
		$code = $this->reglages->codeDePaiement($paiement_tibillet['method'] ?? null, (int) $banque->type === Account::TYPE_CASH);

		$paiement = new Paiement($this->db);
		$paiement->datepaye = $date_de_la_j;
		$paiement->amounts = array($facture->id => ((int) $paiement_tibillet['amount']) / 100);
		$paiement->paiementid = $this->reglages->idDuCodeDePaiement($paiement_tibillet['method'] ?? null, (int) $banque->type === Account::TYPE_CASH);
		$paiement->paiementcode = $code;
		$paiement->num_payment = '';
		$paiement->note_private = $facture->note_private;
		if ($paiement->create($user, 1) <= 0) {
			$this->reference_deja_prise = ($this->db->lasterrno() === 'DB_ERROR_RECORD_ALREADY_EXISTS');
			return $this->erreurDolibarr($paiement);
		}
		if ($paiement->addPaymentToBank($user, 'payment', '(CustomerInvoicePayment)', $banque->id, '', '') <= 0) {
			return $this->erreurDolibarr($paiement);
		}
		return 1;
	}

	/**
	 * Un virement interne d'une banque a une autre (recharge, vidage de carte), comme
	 * compta/bank/transfer.php : deux lignes de banque reliees (« banktransfert »), en
	 * LIQ si l'une des banques est une caisse.
	 * / An internal transfer between two banks, like compta/bank/transfer.php.
	 *
	 * @return int 1 si OK, -1 sinon
	 */
	private function virer($virement, $date_de_la_j, $user)
	{
		$depuis = $this->banqueDuCompte($virement['fromAccount'], $user);
		$vers = $this->banqueDuCompte($virement['toAccount'], $user);
		if ($depuis === null || $vers === null) {
			return -1;
		}
		if ((int) $depuis->id === (int) $vers->id) {
			return 1;
		}
		$une_caisse = ((int) $depuis->type === Account::TYPE_CASH || (int) $vers->type === Account::TYPE_CASH);
		$code = $une_caisse ? 'LIQ' : 'VIR';
		$montant = ((int) $virement['amount']) / 100;
		$libelle = (string) ($virement['label'] ?? '');

		$ligne_depuis = $depuis->addline($date_de_la_j, $code, $libelle, -$montant, '', 0, $user);
		$ligne_vers = $vers->addline($date_de_la_j, $code, $libelle, $montant, '', 0, $user);
		if ($ligne_depuis <= 0) {
			return $this->erreurDolibarr($depuis);
		}
		if ($ligne_vers <= 0) {
			return $this->erreurDolibarr($vers);
		}
		$adresse = DOL_URL_ROOT.'/compta/bank/line.php?rowid=';
		if ($depuis->add_url_line($ligne_depuis, $ligne_vers, $adresse, '(banktransfert)', 'banktransfert') <= 0) {
			return $this->erreurDolibarr($depuis);
		}
		if ($vers->add_url_line($ligne_vers, $ligne_depuis, $adresse, '(banktransfert)', 'banktransfert') <= 0) {
			return $this->erreurDolibarr($vers);
		}
		$this->compteurs['virements']++;
		return 1;
	}

	/**
	 * Un paiement divers (recharge offerte, jetons repris, ecart d'encaissement) : une
	 * ligne de banque sans facture, la contrepartie dans accountancy_code. Montant
	 * positif : l'argent entre (sens 1) ; negatif : il sort (sens 0).
	 * / A various payment: a bank line without invoice, counterpart in accountancy_code.
	 *
	 * @return int 1 si OK, -1 sinon
	 */
	private function paiementDivers($paiement_divers, $date_de_la_j, $user)
	{
		$banque = $this->banqueDuCompte($paiement_divers['treasuryAccount'], $user);
		if ($banque === null) {
			return -1;
		}
		$montant = (int) $paiement_divers['amount'];

		$paiement = new PaymentVarious($this->db);
		$paiement->datep = $date_de_la_j;
		$paiement->datev = $date_de_la_j;
		$paiement->amount = abs($montant) / 100;
		$paiement->sens = ($montant > 0) ? 1 : 0;
		$paiement->fk_account = $banque->id;
		$paiement->type_payment = $this->reglages->idDuCodeDePaiement($paiement_divers['method'] ?? null, (int) $banque->type === Account::TYPE_CASH);
		$paiement->label = (string) ($paiement_divers['label'] ?? '');
		$paiement->accountancy_code = $this->numeroDeCompteDolibarr($paiement_divers['counterpartAccount']);
		$paiement->num_payment = '';
		if ($paiement->create($user) <= 0) {
			return $this->erreurDolibarr($paiement);
		}
		$this->compteurs['paiements_divers']++;
		return 1;
	}

	// ------------------------------------------------------------------
	// La table du module / The module table
	// ------------------------------------------------------------------

	/** Vrai si la vente est deja recopiee / True if already copied */
	private function venteDejaImportee($vente_uuid)
	{
		$sql = "SELECT rowid FROM ".$this->db->prefix()."tibillet_vente_importee";
		$sql .= " WHERE vente_uuid = '".$this->db->escape($vente_uuid)."' AND entity = ".((int) $this->entity);
		$resql = $this->db->query($sql);
		return ($resql && $this->db->num_rows($resql) > 0);
	}

	/** La facture d'une vente deja recopiee, ou 0 / Invoice of a copied sale, or 0 */
	private function factureDeLaVente($vente_uuid)
	{
		$sql = "SELECT fk_facture FROM ".$this->db->prefix()."tibillet_vente_importee";
		$sql .= " WHERE vente_uuid = '".$this->db->escape($vente_uuid)."' AND entity = ".((int) $this->entity);
		$resql = $this->db->query($sql);
		$ligne = $resql ? $this->db->fetch_object($resql) : null;
		return ($ligne && $ligne->fk_facture !== null) ? (int) $ligne->fk_facture : 0;
	}

	/** Le plus grand numero de vente recopie d'une cloture, ou 0 / Cursor in a closure */
	private function derniereVenteImporteeDeLaCloture($cloture_uuid)
	{
		$sql = "SELECT MAX(numero_vente) as plus_grand FROM ".$this->db->prefix()."tibillet_vente_importee";
		$sql .= " WHERE cloture_uuid = '".$this->db->escape($cloture_uuid)."' AND entity = ".((int) $this->entity);
		$resql = $this->db->query($sql);
		$ligne = $resql ? $this->db->fetch_object($resql) : null;
		return ($ligne && $ligne->plus_grand !== null) ? (int) $ligne->plus_grand : 0;
	}

	/** Note la vente recopiee / Records the copied sale */
	private function noterLaVente($vente, $cloture_uuid, $id_de_la_facture)
	{
		global $langs;
		$sql = "INSERT INTO ".$this->db->prefix()."tibillet_vente_importee";
		$sql .= " (entity, vente_uuid, numero_vente, cloture_uuid, fk_facture, date_import) VALUES (";
		$sql .= ((int) $this->entity).", '".$this->db->escape($vente['identifier'])."', ".((int) $vente['orderNumber']).", ";
		$sql .= "'".$this->db->escape($cloture_uuid)."', ".($id_de_la_facture ? ((int) $id_de_la_facture) : "NULL").", ";
		$sql .= "'".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $this->db->lasterror());
			return -1;
		}
		return 1;
	}

	// ------------------------------------------------------------------
	// Petits outils / Small helpers
	// ------------------------------------------------------------------

	/** La banque (Account charge) d'un compte de tresorerie, ou null / The bank, or null */
	private function banqueDuCompte($numero_tibillet, $user)
	{
		$id_de_la_banque = $this->reglages->banquePourLeCompte($numero_tibillet, $user);
		if ($id_de_la_banque <= 0) {
			$this->error = $this->reglages->error;
			return null;
		}
		$banque = new Account($this->db);
		$banque->fetch($id_de_la_banque);
		return $banque;
	}

	/** Le produit Dolibarr de ce ref_ext (recherche exacte), ou 0 / Product by ref_ext */
	private function produitParRefExt($ref_ext)
	{
		$sql = "SELECT rowid FROM ".$this->db->prefix()."product";
		$sql .= " WHERE ref_ext = '".$this->db->escape($ref_ext)."'";
		$sql .= " AND entity IN (".getEntity('product').")";
		$resql = $this->db->query($sql);
		$ligne = $resql ? $this->db->fetch_object($resql) : null;
		return $ligne ? (int) $ligne->rowid : 0;
	}

	/** Garde l'erreur d'un objet Dolibarr, rend -1 / Keeps a Dolibarr object's error */
	private function erreurDolibarr($objet)
	{
		global $langs;
		$this->error = $langs->transnoentities('TiBilletErreurDolibarr', trim($objet->error.' '.implode(' ', (array) $objet->errors)));
		return -1;
	}

	/**
	 * Le resume du passage (sans balise : la fiche de la tache l'affiche sans
	 * echappement), garde pour la page du module.
	 * / The run summary, without tags, kept for the module page.
	 */
	private function finirLePassage($retour)
	{
		global $conf, $langs;

		$resume = $langs->transnoentities(
			'TiBilletResumeVentes',
			$this->compteurs['ventes'] ?? 0,
			$this->compteurs['factures'] ?? 0,
			$this->compteurs['avoirs'] ?? 0,
			$this->compteurs['virements'] ?? 0,
			$this->compteurs['paiements_divers'] ?? 0
		);
		$resume .= ' '.$langs->transnoentities('TiBilletResumeVentesClotures', $this->compteurs['clotures'] ?? 0);
		if ($this->reglages && !empty($this->reglages->faits)) {
			$resume .= "\n".implode("\n", $this->reglages->faits);
		}
		if (!empty($this->phrases_du_passage)) {
			$resume .= "\n".implode("\n", $this->phrases_du_passage);
		}
		if ($retour < 0 && $this->error !== '') {
			$resume .= "\n".$this->error;
		}
		$this->output = dol_string_nohtmltag($resume, 0, 'UTF-8', 0, 0);
		dolibarr_set_const($this->db, 'TIBILLET_VENTES_DATE', dol_now(), 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'TIBILLET_VENTES_RESUME', $this->output, 'chaine', 0, '', $conf->entity);
		return $retour;
	}
}
