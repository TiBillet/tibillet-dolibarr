<?php
/* Copyright (C) 2026 Cooperative TiBillet
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * La synchro du catalogue de TiBillet dans Dolibarr (mode « articles et ventes »).
 * / Sync of TiBillet's catalogue into Dolibarr ("items and sales" mode).
 *
 * UN PASSAGE (la tache de nuit, ou le bouton « Synchroniser le catalogue maintenant ») :
 * 1. ne fait rien en mode « ecritures », ni sans adresse ou sans cle (et le dit) ;
 * 2. echoue si les modules Produits, Services ou Categories sont inactifs ;
 * 3. lit TOUT le catalogue : GET /api/v2/products/?accounting=true, avec l'ETag du
 *    dernier passage reussi dans If-None-Match. 304 = rien n'a change : fin ;
 * 4. range les categories sous deux meres : « Caisse TiBillet » (une fille par
 *    categorie de caisse) et « Types TiBillet » (une fille par type de produit) ;
 * 5. pour chaque TARIF en euros d'un produit qui est une vente (`forAccountingSync`) :
 *    cree ou met a jour UN produit Dolibarr (ref_ext = uuid du tarif), dans UNE
 *    transaction par tarif : une erreur n'arrete pas les autres ;
 * 6. enregistre le nouvel ETag seulement si AUCUNE erreur : sinon la nuit suivante
 *    refait tout.
 *
 * CE QUE LE MODULE NE FAIT JAMAIS : supprimer un produit ou une categorie (un produit
 * facture ne se supprime pas : un tarif archive passe « hors vente ») ; toucher un
 * produit ou une categorie sans ref_ext TiBillet ; retirer une categorie posee a la
 * main sur un produit.
 *
 * Spec : CHANTIER-06-C-catalogue.md (depot Lespass, TECH_DOC/SESSIONS/COMPTABILITE/
 * CHANTIER-06-connecteurs-comptables/).
 */

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/tibillet/class/importtibillet.class.php');

/**
 * La synchro du catalogue. Herite de l'import : meme appel a TiBillet, meme forme
 * des numeros de compte. Le compte de vente est seulement ecrit sur le produit : le
 * plan comptable de Dolibarr n'est jamais touche.
 * / The catalogue sync; reuses the import's call and account numbers. The chart of
 * accounts is never touched.
 */
class CatalogueTiBillet extends ImportTiBillet
{
	/** ref_ext des deux categories meres / ref_ext of the two parent categories */
	const REF_EXT_MERE_CAISSE = 'tibillet:caisse';
	const REF_EXT_MERE_TYPES = 'tibillet:types';

	/** Prefixe du ref_ext d'une categorie de type / Prefix of a type category ref_ext */
	const PREFIXE_REF_EXT_TYPE = 'tibillet:type:';

	/** Les types TiBillet vendus comme des services / TiBillet types sold as services */
	const TYPES_VENDUS_COMME_SERVICES = array('B', 'F', 'G', 'Q', 'C', 'A');

	/** @var array<string,int> Les compteurs du passage / Run counters */
	private $compteurs = array();

	/** @var string[] Les phrases des articles non crees et des erreurs / Sentences */
	private $phrases_du_passage = array();

	/** @var int[] Les categories filles TiBillet (rowid) / TiBillet child categories */
	private $categories_tibillet = array();

	// ------------------------------------------------------------------
	// Un passage / One run
	// ------------------------------------------------------------------

	/**
	 * Un passage complet de la synchro du catalogue.
	 * / One full catalogue sync run.
	 *
	 * @return int 0 si tout va bien (ou rien a faire), -1 sinon (voir $this->error)
	 */
	public function synchroniserLeCatalogue()
	{
		global $user, $langs, $conf;
		$langs->loadLangs(array('tibillet@tibillet'));

		if (self::modeChoisi() !== self::MODE_VENTES) {
			$this->output = $langs->transnoentities('TiBilletModeEcrituresPasDeCatalogue');
			return 0;
		}
		if (!$this->moduleEstConfigure()) {
			$this->output = $langs->transnoentities('TiBilletNonConfigure');
			return 0;
		}
		if (!isModEnabled('product') || !isModEnabled('service') || !isModEnabled('category')) {
			$this->error = $langs->transnoentities('TiBilletModulesProduitsInactifs');
			return -1;
		}

		// 1. Le catalogue, s'il a change / The catalogue, if it changed
		$en_tetes = array();
		$dernier_etag = getDolGlobalString('TIBILLET_CATALOGUE_ETAG');
		if ($dernier_etag !== '') {
			$en_tetes[] = 'If-None-Match: '.$dernier_etag;
		}
		$reponse = $this->appelerTiBillet('/api/v2/products/?accounting=true', $en_tetes);
		if ($reponse['code'] == 304) {
			// Le resume du dernier passage complet reste vrai (rien n'a change) : il
			// garde la liste des articles a corriger. Seule la date du passage avance.
			// / The last full run's summary stays true; only the run date moves.
			$this->output = $langs->transnoentities('TiBilletCatalogueInchange');
			dolibarr_set_const($this->db, 'TIBILLET_CATALOGUE_DATE', dol_now(), 'chaine', 0, '', $conf->entity);
			return 0;
		}
		if ($reponse['code'] == 403) {
			$this->error = $langs->transnoentities('TiBilletCleSansDroitProduits');
			return -1;
		}
		if ($reponse['code'] != 200 || !is_array($reponse['donnees'])) {
			$this->error = $this->phraseDuRefus($reponse);
			return -1;
		}
		if (!isset($reponse['donnees']['results']) || !is_array($reponse['donnees']['results'])) {
			$this->error = $langs->transnoentities('TiBilletReponseIllisible');
			return -1;
		}

		// 2. Les deux meres et les filles deja connues / Parents and known children
		$mere_caisse = $this->trouverOuCreerUneCategorie(self::REF_EXT_MERE_CAISSE, $langs->transnoentities('TiBilletCategorieCaisse'), 0, $user);
		$mere_types = $this->trouverOuCreerUneCategorie(self::REF_EXT_MERE_TYPES, $langs->transnoentities('TiBilletCategorieTypes'), 0, $user);
		if ($mere_caisse < 0 || $mere_types < 0) {
			return -1;
		}
		$this->categories_tibillet = $this->filles($mere_caisse, $mere_types);

		// 3. Les tarifs / The prices
		$this->compteurs = array('crees' => 0, 'mis_a_jour' => 0, 'inchanges' => 0, 'ecartes' => 0, 'non_crees' => 0, 'erreurs' => 0);
		$this->phrases_du_passage = array();
		foreach ($reponse['donnees']['results'] as $produit_tibillet) {
			$this->synchroniserUnProduit($produit_tibillet, $mere_caisse, $mere_types, $user);
		}

		// 4. L'ETag, seulement sans erreur / The ETag, only without error
		if ($this->compteurs['erreurs'] === 0 && $reponse['etag'] !== '') {
			dolibarr_set_const($this->db, 'TIBILLET_CATALOGUE_ETAG', $reponse['etag'], 'chaine', 0, '', $conf->entity);
		}

		// Deux phrases : transnoentities() de Dolibarr prend au plus 5 parametres.
		// / Two sentences: Dolibarr's transnoentities() takes at most 5 parameters.
		$this->output = $langs->transnoentities(
			'TiBilletResumeCatalogue',
			$this->compteurs['crees'],
			$this->compteurs['mis_a_jour'],
			$this->compteurs['inchanges']
		).' '.$langs->transnoentities(
			'TiBilletResumeCatalogueSuite',
			$this->compteurs['ecartes'],
			$this->compteurs['non_crees'],
			$this->compteurs['erreurs']
		);
		if (!empty($this->phrases_du_passage)) {
			$this->output .= "\n".implode("\n", $this->phrases_du_passage);
		}
		// Le resume contient des noms venus de TiBillet. La fiche de la tache planifiee
		// de Dolibarr l'affiche SANS echappement (cron/card.php) : on retire toute
		// balise, en gardant les retours a la ligne.
		// / The summary holds TiBillet names, shown unescaped by Dolibarr's job card.
		$this->output = dol_string_nohtmltag($this->output, 0, 'UTF-8', 0, 0);
		$this->noterLePassage();

		if ($this->compteurs['erreurs'] > 0) {
			$this->error = $langs->transnoentities('TiBilletCatalogueAvecErreurs', $this->compteurs['erreurs']);
			return -1;
		}
		return 0;
	}

	// ------------------------------------------------------------------
	// Un produit, un tarif / One product, one price
	// ------------------------------------------------------------------

	/**
	 * Les tarifs d'un produit TiBillet : ecartes si ce n'est pas une vente, non crees
	 * sans compte ou sans TVA, sinon un produit Dolibarr par tarif en euros.
	 * / A TiBillet product's prices: skipped, not created, or one Dolibarr product each.
	 */
	private function synchroniserUnProduit($produit_tibillet, $mere_caisse, $mere_types, $user)
	{
		global $langs;

		if (!isset($produit_tibillet['name']) || !is_array($produit_tibillet['offers'] ?? array())) {
			$this->noterUneErreur((string) ($produit_tibillet['identifier'] ?? '?'), $langs->transnoentities('TiBilletArticleIllisible'));
			return;
		}
		$nom_du_produit = trim((string) $produit_tibillet['name']);
		$tarifs = $produit_tibillet['offers'] ?? array();

		// Ce n'est pas une vente : ecarte. Un produit Dolibarr deja cree pour un de
		// ses tarifs (quand c'en etait une) passe hors vente.
		// / Not a sale: skipped; an already created Dolibarr product goes not for sale.
		if (!$this->propriete($produit_tibillet, 'forAccountingSync')) {
			foreach ($tarifs as $tarif) {
				$deja_cree = $this->passerHorsVenteSiDejaCree($tarif, $nom_du_produit, $langs->transnoentities('TiBilletPlusUneVente'), $user);
				if (!$deja_cree) {
					$this->compteurs['ecartes']++;
				}
			}
			return;
		}

		// Sans compte ou sans TVA, Dolibarr rangerait l'article au compte par defaut,
		// different du FEC : on ne le cree pas, on le dit (avec la phrase de TiBillet).
		// / Without account or VAT: not created, and said.
		$compte = $this->propriete($produit_tibillet, 'accountingAccount');
		$taux_de_tva = $this->propriete($produit_tibillet, 'vatRate');
		if ($compte === null || $taux_de_tva === null) {
			$phrase_de_tibillet = $this->propriete($produit_tibillet, 'accountingAccountError');
			if ($phrase_de_tibillet === null) {
				$phrase_de_tibillet = $this->propriete($produit_tibillet, 'vatRateError');
			}
			$au_moins_un_non_cree = false;
			foreach ($tarifs as $tarif) {
				$deja_cree = $this->passerHorsVenteSiDejaCree($tarif, $nom_du_produit, (string) $phrase_de_tibillet, $user);
				if (!$deja_cree) {
					$this->compteurs['non_crees']++;
					$au_moins_un_non_cree = true;
				}
			}
			if ($au_moins_un_non_cree) {
				$this->phrases_du_passage[] = $langs->transnoentities('TiBilletArticleNonCree', $nom_du_produit, (string) $phrase_de_tibillet);
			}
			return;
		}

		// Les categories voulues : celle de caisse (si le produit en a une) et celle du type.
		// / Wanted categories: the POS one (if any) and the type one.
		$categories_voulues = array();
		$categorie_de_caisse = $this->propriete($produit_tibillet, 'posCategory');
		if ($categorie_de_caisse !== null) {
			$id_caisse = $this->trouverOuCreerUneCategorie($categorie_de_caisse['identifier'], $categorie_de_caisse['name'], $mere_caisse, $user);
			if ($id_caisse < 0) {
				$this->noterUneErreur($nom_du_produit, $this->error);
				return;
			}
			$categories_voulues[] = $id_caisse;
		}
		$type = $this->propriete($produit_tibillet, 'productType');
		if (!is_array($type) || !isset($type['code'], $type['label'])) {
			$this->noterUneErreur($nom_du_produit, $langs->transnoentities('TiBilletArticleIllisible'));
			return;
		}
		$id_type = $this->trouverOuCreerUneCategorie(self::PREFIXE_REF_EXT_TYPE.$type['code'], $type['label'], $mere_types, $user);
		if ($id_type < 0) {
			$this->noterUneErreur($nom_du_produit, $this->error);
			return;
		}
		$categories_voulues[] = $id_type;
		$this->categories_tibillet = array_unique(array_merge($this->categories_tibillet, $categories_voulues));

		$produit_archive = (bool) $this->propriete($produit_tibillet, 'archived');
		// Un produit sans tarif n'a pas de cle « offers » (l'API retire les listes vides).
		// / A product without prices has no "offers" key.
		foreach ($tarifs as $tarif) {
			if (empty($tarif['identifier']) || !isset($tarif['name'], $tarif['price'])) {
				$this->noterUneErreur($nom_du_produit, $langs->transnoentities('TiBilletArticleIllisible'));
				continue;
			}
			// Un tarif en jetons (temps, fidelite…) n'est pas une vente en euros.
			// / A token price is not a sale in euros.
			if (($tarif['priceCurrency'] ?? 'EUR') !== 'EUR') {
				$deja_cree = $this->passerHorsVenteSiDejaCree($tarif, $nom_du_produit, $langs->transnoentities('TiBilletPlusEnEuros'), $user);
				if (!$deja_cree) {
					$this->compteurs['ecartes']++;
				}
				continue;
			}
			// trim() : Product::update() retire les espaces des bords. Sans lui, un nom
			// « Biere  » serait reecrit chaque nuit.
			// / trim(): Product::update() trims, else the label is rewritten every night.
			$voulu = array(
				'ref_ext' => $tarif['identifier'],
				'ref' => 'TIB-'.substr($tarif['identifier'], 0, 8),
				'label' => trim(dol_trunc($nom_du_produit.' — '.trim((string) $tarif['name']), 255, 'right', 'UTF-8', 1)),
				'type' => in_array($type['code'], self::TYPES_VENDUS_COMME_SERVICES, true) ? Product::TYPE_SERVICE : Product::TYPE_PRODUCT,
				'prix_ttc' => (float) $tarif['price'],
				'prix_minimum_ttc' => !empty($tarif['freePrice']) ? (float) $tarif['price'] : 0.0,
				'taux_de_tva' => (float) $taux_de_tva,
				'compte' => $this->numeroDeCompteDolibarr($compte['number']),
				'en_vente' => ($produit_archive || $this->propriete($tarif, 'archived')) ? 0 : 1,
			);
			$this->synchroniserUnTarif($voulu, $categories_voulues, $user);
		}
	}

	/**
	 * Le produit Dolibarr deja cree pour ce tarif, s'il existe, passe hors vente : le
	 * tarif ne peut plus etre recopie (plus une vente, plus de compte, plus en euros).
	 * Il n'est jamais supprime (il a pu etre facture).
	 * / The existing Dolibarr product of this price goes not for sale (never deleted).
	 *
	 * @param array  $tarif          Le tarif TiBillet
	 * @param string $nom_du_produit Le nom du produit TiBillet
	 * @param string $raison         Pourquoi le tarif ne peut plus etre recopie
	 * @param User   $user           Auteur
	 * @return bool vrai si un produit Dolibarr existe pour ce tarif
	 */
	private function passerHorsVenteSiDejaCree($tarif, $nom_du_produit, $raison, $user)
	{
		global $langs;

		if (empty($tarif['identifier'])) {
			return false;
		}
		$id_existant = $this->produitParRefExt($tarif['identifier']);
		if ($id_existant <= 0) {
			return false;
		}

		$produit = new Product($this->db);
		$produit->fetch($id_existant);
		if ((int) $produit->status === 0) {
			$this->compteurs['inchanges']++;
			return true;
		}
		$produit->status = 0;
		if ($produit->update($produit->id, $user) <= 0) {
			$this->noterUneErreur($produit->label, $langs->transnoentities('TiBilletErreurDolibarr', $produit->error));
			return true;
		}
		$this->compteurs['mis_a_jour']++;
		$this->phrases_du_passage[] = $langs->transnoentities('TiBilletArticlePasseHorsVente', $produit->label, $raison);
		return true;
	}

	/**
	 * Cree ou met a jour le produit Dolibarr d'un tarif, puis ses categories, dans UNE
	 * transaction.
	 * / Creates or updates one price's Dolibarr product and its categories.
	 *
	 * @param array $voulu              Les valeurs voulues (voir synchroniserUnProduit)
	 * @param int[] $categories_voulues Les categories TiBillet voulues
	 * @param User  $user               Auteur
	 */
	private function synchroniserUnTarif($voulu, $categories_voulues, $user)
	{
		$this->db->begin();

		$id_existant = $this->produitParRefExt($voulu['ref_ext']);
		if ($id_existant > 0) {
			$resultat = $this->mettreAJourLeProduit($id_existant, $voulu, $user);
			$id_du_produit = $id_existant;
		} else {
			$resultat = $this->creerLeProduit($voulu, $user);
			$id_du_produit = $resultat;
		}
		if ($resultat < 0) {
			$this->db->rollback();
			$this->noterUneErreur($voulu['label'], $this->error);
			return;
		}

		$categories_changees = $this->rangerDansSesCategories($id_du_produit, $categories_voulues, $user);
		if ($categories_changees < 0) {
			$this->db->rollback();
			$this->noterUneErreur($voulu['label'], $this->error);
			return;
		}
		$this->db->commit();

		if ($id_existant <= 0) {
			$this->compteurs['crees']++;
		} elseif ($resultat > 0 || $categories_changees > 0) {
			$this->compteurs['mis_a_jour']++;
		} else {
			$this->compteurs['inchanges']++;
		}
	}

	/**
	 * Cree le produit. Tout est pose AVANT create() : create() ecrit deja la ligne
	 * d'historique des prix, un updatePrice() juste apres en ecrirait une seconde.
	 * / Creates the product; everything is set BEFORE create().
	 *
	 * @return int id du produit, ou -1
	 */
	private function creerLeProduit($voulu, $user)
	{
		global $langs;

		$produit = new Product($this->db);
		$produit->ref = $voulu['ref'];
		$produit->ref_ext = $voulu['ref_ext'];
		$produit->label = $voulu['label'];
		$produit->type = $voulu['type'];
		$produit->price_base_type = 'TTC';
		$produit->price_ttc = $voulu['prix_ttc'];
		$produit->price_min_ttc = $voulu['prix_minimum_ttc'];
		$produit->tva_tx = $voulu['taux_de_tva'];
		$produit->accountancy_code_sell = $voulu['compte'];
		// Sans status explicite, Dolibarr cree le produit « hors vente ».
		// / Without an explicit status, Dolibarr creates it "not for sale".
		$produit->status = $voulu['en_vente'];
		$produit->status_buy = 0;

		$id_du_produit = $produit->create($user);
		if ($id_du_produit <= 0) {
			$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $produit->error.' '.implode(' ', (array) $produit->errors));
			return -1;
		}
		return $id_du_produit;
	}

	/**
	 * Met a jour le produit seulement si une valeur a change : updatePrice() ecrit une
	 * ligne d'historique a CHAQUE appel (hors multiprix), meme sans changement.
	 * / Updates only what changed: updatePrice() logs a price line at EVERY call.
	 *
	 * @return int 1 si quelque chose a change, 0 sinon, -1 si erreur
	 */
	private function mettreAJourLeProduit($id_du_produit, $voulu, $user)
	{
		global $langs;

		$produit = new Product($this->db);
		if ($produit->fetch($id_du_produit) <= 0) {
			$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $produit->error);
			return -1;
		}

		$quelque_chose_a_change = 0;

		// La fiche : libelle, type, compte, en vente. update() reecrit TOUS les champs
		// de l'objet (d'ou le fetch avant), et le type seulement avec $updatetype = true.
		// / The card: update() rewrites every field, and the type only with $updatetype.
		$la_fiche_change = ($produit->label !== $voulu['label'])
			|| ((int) $produit->type !== (int) $voulu['type'])
			|| ((string) $produit->accountancy_code_sell !== (string) $voulu['compte'])
			|| ((int) $produit->status !== (int) $voulu['en_vente']);
		if ($la_fiche_change) {
			$produit->label = $voulu['label'];
			$produit->type = $voulu['type'];
			$produit->accountancy_code_sell = $voulu['compte'];
			$produit->status = $voulu['en_vente'];
			if ($produit->update($produit->id, $user, 0, 'update', true) <= 0) {
				$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $produit->error);
				return -1;
			}
			$quelque_chose_a_change = 1;
		}

		// Le prix : TTC, TVA, minimum. Le 5e argument (minimum) est obligatoire : sans
		// lui, updatePrice() remet le minimum a 0.
		// / The price: the 5th argument (minimum) is required, else it is reset to 0.
		$le_prix_change = (round((float) $produit->price_ttc, 2) !== round($voulu['prix_ttc'], 2))
			|| (abs((float) $produit->tva_tx - $voulu['taux_de_tva']) > 0.001)
			|| (round((float) $produit->price_min_ttc, 2) !== round($voulu['prix_minimum_ttc'], 2));
		if ($le_prix_change) {
			if ($produit->updatePrice($voulu['prix_ttc'], 'TTC', $user, $voulu['taux_de_tva'], $voulu['prix_minimum_ttc']) <= 0) {
				$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $produit->error);
				return -1;
			}
			$quelque_chose_a_change = 1;
		}

		return $quelque_chose_a_change;
	}

	/**
	 * Range le produit dans ses categories TiBillet. Ne retire QUE les categories
	 * TiBillet en trop : une categorie posee a la main reste (setCategories() les
	 * retirerait toutes, d'ou l'ajout et le retrait un par un).
	 * / Files the product; removes only extra TiBillet categories.
	 *
	 * @return int nombre de changements, ou -1
	 */
	private function rangerDansSesCategories($id_du_produit, $categories_voulues, $user)
	{
		global $langs;

		$produit = new Product($this->db);
		$produit->fetch($id_du_produit);
		$categorie = new Categorie($this->db);
		$categories_actuelles = $categorie->containing($id_du_produit, Categorie::TYPE_PRODUCT, 'id');
		if (!is_array($categories_actuelles)) {
			$categories_actuelles = array();
		}

		$nombre_de_changements = 0;
		foreach ($categories_voulues as $id_de_categorie) {
			if (in_array($id_de_categorie, $categories_actuelles)) {
				continue;
			}
			$categorie_a_ajouter = new Categorie($this->db);
			$categorie_a_ajouter->fetch($id_de_categorie);
			if ($categorie_a_ajouter->add_type($produit, Categorie::TYPE_PRODUCT) < 0) {
				$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $categorie_a_ajouter->error);
				return -1;
			}
			$nombre_de_changements++;
		}
		foreach ($categories_actuelles as $id_de_categorie) {
			$categorie_tibillet_en_trop = in_array($id_de_categorie, $this->categories_tibillet) && !in_array($id_de_categorie, $categories_voulues);
			if (!$categorie_tibillet_en_trop) {
				continue;
			}
			$categorie_a_retirer = new Categorie($this->db);
			$categorie_a_retirer->fetch($id_de_categorie);
			if ($categorie_a_retirer->del_type($produit, Categorie::TYPE_PRODUCT) < 0) {
				$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $categorie_a_retirer->error);
				return -1;
			}
			$nombre_de_changements++;
		}
		return $nombre_de_changements;
	}

	// ------------------------------------------------------------------
	// Les categories / Categories
	// ------------------------------------------------------------------

	/**
	 * La categorie de produits de ce ref_ext (recherche EXACTE : Categorie::fetch fait
	 * un LIKE, sans filtre de type). Sinon, celle du meme nom sous la meme mere, qui
	 * prend ce ref_ext. Sinon, creee. Renommee si son libelle a change chez TiBillet.
	 * / The product category with this exact ref_ext, else the same-named sibling
	 * (which takes the ref_ext), else created; renamed if needed.
	 *
	 * @return int rowid, ou -1
	 */
	private function trouverOuCreerUneCategorie($ref_ext, $libelle, $id_de_la_mere, $user)
	{
		global $langs;

		// Categorie::update() retire les espaces des bords : on compare sans eux.
		// / Categorie::update() trims: compare without edge spaces.
		$libelle = trim((string) $libelle);

		$categorie_pour_lire_les_types = new Categorie($this->db);
		$type_produit = (int) $categorie_pour_lire_les_types->MAP_ID[Categorie::TYPE_PRODUCT];

		$sql = "SELECT rowid, label FROM ".$this->db->prefix()."categorie";
		$sql .= " WHERE ref_ext = '".$this->db->escape($ref_ext)."'";
		$sql .= " AND type = ".$type_produit;
		$sql .= " AND entity IN (".getEntity('category').")";
		$resql = $this->db->query($sql);
		$ligne = $resql ? $this->db->fetch_object($resql) : null;

		if ($ligne) {
			if ($ligne->label !== $libelle) {
				$categorie = new Categorie($this->db);
				$categorie->fetch((int) $ligne->rowid);
				$categorie->label = $libelle;
				if ($categorie->update($user) < 0) {
					$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $categorie->error);
					return -1;
				}
			}
			return (int) $ligne->rowid;
		}

		// Une categorie du meme nom sous la meme mere est reprise : Dolibarr refuse d'en
		// creer une seconde (Categorie::already_exists, meme critere qu'ici). C'est le
		// cas d'une categorie supprimee puis recreee chez TiBillet : meme nom, nouvel
		// identifiant.
		// / A same-named sibling is reused: Dolibarr refuses a second one.
		$sql = "SELECT rowid FROM ".$this->db->prefix()."categorie";
		$sql .= " WHERE fk_parent = ".((int) $id_de_la_mere);
		$sql .= " AND label = '".$this->db->escape($libelle)."'";
		$sql .= " AND type = ".$type_produit;
		$sql .= " AND entity IN (".getEntity('category').")";
		$resql = $this->db->query($sql);
		$meme_nom = $resql ? $this->db->fetch_object($resql) : null;
		if ($meme_nom) {
			$sql = "UPDATE ".$this->db->prefix()."categorie SET ref_ext = '".$this->db->escape($ref_ext)."'";
			$sql .= " WHERE rowid = ".((int) $meme_nom->rowid);
			if (!$this->db->query($sql)) {
				$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $this->db->lasterror());
				return -1;
			}
			return (int) $meme_nom->rowid;
		}

		$categorie = new Categorie($this->db);
		$categorie->label = $libelle;
		$categorie->type = Categorie::TYPE_PRODUCT;
		$categorie->fk_parent = $id_de_la_mere;
		$categorie->ref_ext = $ref_ext;
		$categorie->visible = 1;
		$id_de_la_categorie = $categorie->create($user);
		if ($id_de_la_categorie <= 0) {
			$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $categorie->error);
			return -1;
		}
		return $id_de_la_categorie;
	}

	/**
	 * Les filles des deux meres TiBillet : ce sont les seules categories que le module
	 * retire d'un produit.
	 * / The children of the two TiBillet parents: the only categories ever removed.
	 *
	 * @return int[]
	 */
	private function filles($mere_caisse, $mere_types)
	{
		$filles = array();
		$sql = "SELECT rowid FROM ".$this->db->prefix()."categorie";
		$sql .= " WHERE fk_parent IN (".((int) $mere_caisse).", ".((int) $mere_types).")";
		$resql = $this->db->query($sql);
		while ($resql && ($ligne = $this->db->fetch_object($resql))) {
			$filles[] = (int) $ligne->rowid;
		}
		return $filles;
	}

	// ------------------------------------------------------------------
	// Petits outils / Small helpers
	// ------------------------------------------------------------------

	/**
	 * Le produit Dolibarr de ce ref_ext (recherche exacte, dans la societe), ou 0.
	 * / The Dolibarr product with this exact ref_ext, or 0.
	 */
	private function produitParRefExt($ref_ext)
	{
		$sql = "SELECT rowid FROM ".$this->db->prefix()."product";
		$sql .= " WHERE ref_ext = '".$this->db->escape($ref_ext)."'";
		$sql .= " AND entity IN (".getEntity('product').")";
		$resql = $this->db->query($sql);
		$ligne = $resql ? $this->db->fetch_object($resql) : null;
		return $ligne ? (int) $ligne->rowid : 0;
	}

	/**
	 * La valeur d'une propriete schema.org (`additionalProperty`), ou null.
	 * / The value of a schema.org additionalProperty, or null.
	 */
	private function propriete($element, $nom)
	{
		foreach (($element['additionalProperty'] ?? array()) as $propriete) {
			if (($propriete['name'] ?? '') === $nom) {
				return $propriete['value'];
			}
		}
		return null;
	}

	/** Note une erreur sur un article / Records an error on an item */
	private function noterUneErreur($libelle, $phrase)
	{
		global $langs;
		$this->compteurs['erreurs']++;
		$this->phrases_du_passage[] = $langs->transnoentities('TiBilletErreurArticle', $libelle, $phrase);
	}

	/** Garde la date et le resume du passage pour la page du module / Keeps run date and summary */
	private function noterLePassage()
	{
		global $conf;
		dolibarr_set_const($this->db, 'TIBILLET_CATALOGUE_DATE', dol_now(), 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'TIBILLET_CATALOGUE_RESUME', $this->output, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'TIBILLET_CATALOGUE_DATE_RESUME', dol_now(), 'chaine', 0, '', $conf->entity);
	}
}
