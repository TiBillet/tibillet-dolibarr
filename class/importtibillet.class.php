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
 * L'import des clotures TiBillet dans le grand livre de Dolibarr.
 * / Import of TiBillet closures into Dolibarr's general ledger.
 *
 * UN PASSAGE (la tache de nuit, ou le bouton « Importer maintenant ») :
 * 1. lit les reglages ; sans adresse ou sans cle, ne fait rien et le dit ;
 * 2. lit le plan comptable de TiBillet et cree dans Dolibarr les comptes qui manquent
 *    (sous la forme Dolibarr : sans les zeros de droite), sans jamais modifier un
 *    compte existant ;
 * 3. demande les clotures APRES la derniere importee (curseur = plus grand numero de la
 *    table llx_tibillet_cloture_importee) ;
 * 4. pour chaque cloture, dans l'ordre : lit ses ecritures (JSON, centimes entiers),
 *    verifie l'exercice comptable, puis ecrit TOUTES ses ecritures et la ligne de la
 *    table dans UNE transaction : tout ou rien ;
 * 5. au premier refus (compte manquant chez TiBillet, exercice ferme, erreur reseau),
 *    s'arrete et le dit. Le passage suivant reprend a la meme cloture.
 * Les ecritures arrivent NON validees (date_validated vide) : le comptable relit puis
 * valide. Dolibarr choisit le numero de piece (getNextNumMvt) : pas de melange avec
 * ses autres pieces.
 *
 * REIMPORTER une cloture (bouton de la page du module) : dans une transaction, retire
 * ses pieces (refuse si une ligne est validee), relit ses ecritures chez TiBillet (avec
 * le plan du moment) et les reecrit. Sert quand le lieu a corrige son plan comptable.
 *
 * L'APPEL A TIBILLET est un appel cURL direct (pas getURLContent) : getURLContent
 * ecrit les en-tetes, donc la cle d'API, dans les journaux de Dolibarr. Pas de
 * redirection suivie (la cle ne part jamais vers un autre hote). En production :
 * https et certificat verifie. Mode developpement (TIBILLET_RESEAU_LOCAL) : nom resolu
 * par /etc/hosts, certificat non verifie.
 *
 * Spec : CHANTIER-06-B-module-dolibarr.md (depot Lespass, TECH_DOC/SESSIONS/
 * COMPTABILITE/CHANTIER-06-connecteurs-comptables/).
 */

require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingaccount.class.php';
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingjournal.class.php';
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/bookkeeping.class.php';
// validBookkeepingDate() de Dolibarr utilise dol_get_last_hour() sans charger sa
// bibliotheque : le cron la charge, une ligne de commande ou une autre page non.
// / Dolibarr's validBookkeepingDate() needs date.lib.php without loading it.
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

/**
 * L'import TiBillet. Construit avec la seule base de donnees (comme l'attend le
 * gestionnaire des taches planifiees de Dolibarr).
 * / The TiBillet import, built with the database only.
 */
class ImportTiBillet
{
	/** @var DoliDB */
	public $db;

	/** @var string Ce que la tache affiche dans « Travaux planifies » / Job output */
	public $output = '';

	/** @var string Le message d'erreur / Error message */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var int Societe (multi-societe) / Company */
	public $entity;

	/** Le type de document de nos lignes du grand livre / Our ledger lines doc type */
	const TYPE_DE_DOCUMENT = 'tibillet';

	/** Nature des journaux crees : 1 = operations diverses (OD) / Created journals nature */
	const NATURE_DES_JOURNAUX = 1;

	/**
	 * Les deux modes du module (constante TIBILLET_MODE). Un seul a la fois : sinon la
	 * meme vente entre deux fois dans la comptabilite (par l'ecriture ET par la facture).
	 * / The module's two modes. Only one at a time, or a sale would be counted twice.
	 */
	const MODE_ECRITURES = 'ecritures';
	const MODE_VENTES = 'ventes';

	/**
	 * @param DoliDB $db Base de donnees / Database handler
	 */
	public function __construct($db)
	{
		global $conf;
		$this->db = $db;
		$this->entity = $conf->entity;
	}

	// ------------------------------------------------------------------
	// La tache de nuit / The nightly job
	// ------------------------------------------------------------------

	/**
	 * LA tache de nuit : selon le mode, importe les clotures (mode « ecritures ») OU
	 * synchronise le catalogue puis importe les ventes (mode « articles et ventes »).
	 * Une seule tache, donc une seule heure a regler, et un ordre garanti.
	 * / THE nightly job: imports closures OR syncs the catalogue then the sales.
	 *
	 * @return int 0 si tout va bien, -1 sinon (voir $this->error) / 0 if OK, -1 if not
	 */
	public function passageDeLaNuit()
	{
		if (self::modeChoisi() === self::MODE_VENTES) {
			// Le catalogue d'abord : un tarif vendu doit exister comme produit. Les
			// ventes seulement si le catalogue est passe sans erreur.
			// / The catalogue first; sales only if it ran without error.
			dol_include_once('/tibillet/class/catalogue.class.php');
			$catalogue = new CatalogueTiBillet($this->db);
			$resultat = $catalogue->synchroniserLeCatalogue();
			$this->output = $catalogue->output;
			$this->error = $catalogue->error;
			if ($resultat < 0) {
				return $resultat;
			}
			dol_include_once('/tibillet/class/ventes.class.php');
			$ventes = new VentesTiBillet($this->db);
			$resultat = $ventes->importerLesVentes();
			$this->output .= "\n".$ventes->output;
			$this->error = $ventes->error;
			return $resultat;
		}
		return $this->importerLesClotures();
	}

	/**
	 * L'heure de la tache de nuit (constante TIBILLET_HEURE, « HH:MM »), ou 06:00. Le
	 * module ne lit que des journees deja cloturees, et TiBillet cloture la journee
	 * tout seul deux heures apres l'heure de fermeture du lieu (vers 4 h pour une
	 * fermeture a 2 h) : a 6 h, la soiree de la veille est prise le matin meme.
	 * / The nightly job time, or 06:00: after TiBillet's automatic day closure.
	 *
	 * @return string
	 */
	public static function heureDeLaTache()
	{
		$heure = getDolGlobalString('TIBILLET_HEURE');
		return self::heureEstValide($heure) ? $heure : '06:00';
	}

	/**
	 * Vrai pour une heure « HH:MM » de 00:00 a 23:59. / True for a valid "HH:MM".
	 *
	 * @param string $heure
	 * @return bool
	 */
	public static function heureEstValide($heure)
	{
		return (bool) preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', (string) $heure);
	}

	/**
	 * La prochaine fois qu'il sera « HH:MM » : aujourd'hui si c'est encore a venir,
	 * sinon demain. L'heure est lue dans le fuseau de la personne connectee (celui ou
	 * la page affiche le prochain passage), sinon dans celui du serveur (cron, essai).
	 * / The next "HH:MM", read in the logged-in person's time zone, else the server's.
	 *
	 * @param string $heure « HH:MM »
	 * @return int timestamp
	 */
	public static function prochaineOccurrence($heure)
	{
		$fuseau = empty($_SESSION['dol_tz_string']) ? date_default_timezone_get() : $_SESSION['dol_tz_string'];

		list($heures, $minutes) = explode(':', $heure);
		$maintenant = dol_now();
		$aujourd_hui = dol_getdate($maintenant, false, $fuseau);
		$moment = dol_mktime((int) $heures, (int) $minutes, 0, $aujourd_hui['mon'], $aujourd_hui['mday'], $aujourd_hui['year'], 'tz,'.$fuseau);
		if ($moment <= $maintenant) {
			$moment = dol_time_plus_duree($moment, 1, 'd');
		}
		return $moment;
	}

	/**
	 * Recale la tache de nuit sur la prochaine occurrence de son heure. Dolibarr garde
	 * ensuite cette heure d'un passage a l'autre (+ 24 h).
	 * / Moves the nightly job to the next occurrence of its time.
	 *
	 * @param User $user Auteur
	 * @return int 1 si recalee, -1 sinon (voir $this->error)
	 */
	public function recalerLaTacheDeNuit($user)
	{
		global $langs;
		require_once DOL_DOCUMENT_ROOT.'/cron/class/cronjob.class.php';

		$tache = new Cronjob($this->db);
		if ($tache->fetch(0, 'ImportTiBillet', 'passageDeLaNuit') <= 0) {
			$this->error = $langs->transnoentities('TiBilletTacheIntrouvable');
			return -1;
		}
		$tache->datenextrun = self::prochaineOccurrence(self::heureDeLaTache());
		if ($tache->update($user) < 0) {
			$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $tache->error);
			return -1;
		}
		return 1;
	}

	/**
	 * La date du prochain passage de la tache de nuit, ou null.
	 * / Next run of the nightly job, or null.
	 *
	 * @return int|null
	 */
	public function prochainPassage()
	{
		require_once DOL_DOCUMENT_ROOT.'/cron/class/cronjob.class.php';
		$tache = new Cronjob($this->db);
		if ($tache->fetch(0, 'ImportTiBillet', 'passageDeLaNuit') <= 0) {
			return null;
		}
		return $tache->datenextrun;
	}

	/**
	 * Un passage complet. Appelee par la tache de nuit et par « Importer maintenant ».
	 * / One full run. Called by the scheduled job and the "Import now" button.
	 *
	 * @return int 0 si tout va bien (ou rien a faire), -1 si le passage s'est arrete
	 *             / 0 if OK (or nothing to do), -1 if the run stopped
	 */
	public function importerLesClotures()
	{
		global $user, $langs;
		$langs->loadLangs(array('tibillet@tibillet'));

		// En mode « articles et ventes », les clotures n'entrent pas dans le grand livre.
		// / In "items and sales" mode, closures never enter the ledger.
		if (self::modeChoisi() === self::MODE_VENTES) {
			$this->output = $langs->transnoentities('TiBilletModeVentesRienAImporter');
			return 0;
		}

		if (!$this->moduleEstConfigure()) {
			$this->output = $langs->transnoentities('TiBilletNonConfigure');
			return 0;
		}

		// Le grand livre n'existe qu'avec la comptabilite en partie double. Ce n'est
		// pas une dependance du module : la page l'active en passant dans ce mode.
		// / The ledger needs double-entry accounting; the module page enables it.
		if (!isModEnabled('accounting')) {
			$this->error = $langs->transnoentities('TiBilletComptabiliteInactive');
			return -1;
		}

		// 1. Les comptes / The accounts
		$nombre_de_comptes_crees = $this->creerLesComptesManquants($user);
		if ($nombre_de_comptes_crees < 0) {
			return -1;
		}

		// 2. Les clotures a importer / The closures to import
		$curseur = $this->plusGrandNumeroImporte();
		$reponse = $this->appelerTiBillet('/api/v2/closures/?after_number='.((int) $curseur));
		if ($reponse['code'] != 200) {
			$this->error = $this->phraseDuRefus($reponse);
			return -1;
		}
		$clotures = $reponse['donnees']['results'];

		// 3. Une cloture apres l'autre / One closure after the other
		$numeros_importes = array();
		$nombre_de_journaux_crees = 0;
		foreach ($clotures as $cloture) {
			if ($this->clotureDejaImportee($cloture['uuid'])) {
				continue;
			}
			$resultat = $this->importerUneCloture($cloture['uuid'], $user, null);
			if ($resultat < 0) {
				$this->output = $this->resumeDuPassage($numeros_importes, $nombre_de_comptes_crees, $nombre_de_journaux_crees);
				return -1;
			}
			$nombre_de_journaux_crees += $resultat;
			$numeros_importes[] = (int) $cloture['number'];
		}

		$this->output = $this->resumeDuPassage($numeros_importes, $nombre_de_comptes_crees, $nombre_de_journaux_crees);
		return 0;
	}

	/**
	 * Reimporte une cloture deja importee : retire ses pieces et les reecrit avec les
	 * ecritures du moment, dans UNE transaction. Refuse si une ligne est validee.
	 * / Re-imports an imported closure in one transaction. Refused if a line is validated.
	 *
	 * @param int $rowid La ligne de llx_tibillet_cloture_importee / The table row
	 * @return int 1 si reimportee, -1 sinon (voir $this->error) / 1 if done, -1 if not
	 */
	public function reimporterUneCloture($rowid)
	{
		global $user, $langs;
		$langs->loadLangs(array('tibillet@tibillet'));

		if (self::modeChoisi() === self::MODE_VENTES) {
			$this->error = $langs->transnoentities('TiBilletModeVentesPasDeReimport');
			return -1;
		}

		$sql = "SELECT rowid, cloture_uuid, numero, pieces, mode FROM ".$this->db->prefix()."tibillet_cloture_importee";
		$sql .= " WHERE rowid = ".((int) $rowid)." AND entity = ".((int) $this->entity);
		$resql = $this->db->query($sql);
		$ligne = $resql ? $this->db->fetch_object($resql) : null;
		if (!$ligne) {
			$this->error = $langs->transnoentities('TiBilletClotureInconnue');
			return -1;
		}
		// Une cloture importee en mode « ventes » a des factures : ses ecritures s'y
		// ajouteraient (double compta). Elle ne se reimporte jamais.
		// / A closure imported in sales mode has invoices: never re-imported.
		if ($ligne->mode === self::MODE_VENTES) {
			$this->error = $langs->transnoentities('TiBilletClotureEnModeVentes');
			return -1;
		}

		$resultat = $this->importerUneCloture($ligne->cloture_uuid, $user, $ligne);
		return ($resultat < 0) ? -1 : 1;
	}

	/**
	 * Le mode choisi sur la page du module. Pas encore choisi : voir modeParDefaut().
	 * / The chosen mode; not chosen yet: see modeParDefaut().
	 *
	 * @return string self::MODE_ECRITURES ou self::MODE_VENTES
	 */
	public static function modeChoisi()
	{
		global $db;

		$mode_enregistre = getDolGlobalString('TIBILLET_MODE');
		if ($mode_enregistre === self::MODE_VENTES || $mode_enregistre === self::MODE_ECRITURES) {
			return $mode_enregistre;
		}
		// Seules les clotures du grand livre comptent : celles du mode « ventes » ne
		// doivent pas faire basculer un lieu neuf en « ecritures ».
		// / Only ledger closures count, not those imported as sales.
		$import = new self($db);
		return self::modeParDefaut($import->nombreDeCloturesImportees(self::MODE_ECRITURES));
	}

	/**
	 * Le mode quand aucun n'est enregistre : « articles et ventes » pour une
	 * installation neuve ; « ecritures » si des clotures sont deja dans le grand livre,
	 * pour qu'un lieu qui utilise ce mode (installe avant le choix du mode) ne bascule
	 * jamais en silence.
	 * / Default mode: sales for a new install, entries when closures are already imported.
	 *
	 * @param int $nombre_de_clotures_importees
	 * @return string self::MODE_ECRITURES ou self::MODE_VENTES
	 */
	public static function modeParDefaut($nombre_de_clotures_importees)
	{
		if ((int) $nombre_de_clotures_importees > 0) {
			return self::MODE_ECRITURES;
		}
		return self::MODE_VENTES;
	}

	/**
	 * Le nombre de clotures deja ecrites dans le grand livre (sert a l'alerte quand on
	 * change de mode). / Number of closures already in the ledger (mode change alert).
	 *
	 * @return int
	 */
	public function nombreDeCloturesImportees($mode = '')
	{
		$sql = "SELECT COUNT(*) as nb FROM ".$this->db->prefix()."tibillet_cloture_importee";
		$sql .= " WHERE entity = ".((int) $this->entity);
		if ($mode !== '') {
			$sql .= " AND mode = '".$this->db->escape($mode)."'";
		}
		$resql = $this->db->query($sql);
		return $resql ? (int) $this->db->fetch_object($resql)->nb : 0;
	}

	/**
	 * Teste l'adresse et la cle : lit le plan comptable de TiBillet.
	 * / Tests the address and the key by reading TiBillet's chart of accounts.
	 *
	 * @return array{0: bool, 1: string} (ok, phrase)
	 */
	public function testerLaConnexion()
	{
		global $langs;
		$langs->loadLangs(array('tibillet@tibillet'));

		if (!$this->moduleEstConfigure()) {
			return array(false, $langs->transnoentities('TiBilletNonConfigure'));
		}
		$reponse = $this->appelerTiBillet('/api/v2/accounts/');
		if ($reponse['code'] != 200) {
			return array(false, $this->phraseDuRefus($reponse));
		}
		$phrase = $langs->transnoentities('TiBilletConnexionOk', count($reponse['donnees']['results']));

		// Mode « articles et ventes » : la cle doit aussi avoir le droit « Produits ».
		// / Items and sales mode: the key also needs the "Products" right.
		if (self::modeChoisi() === self::MODE_VENTES) {
			$reponse_du_catalogue = $this->appelerTiBillet('/api/v2/products/?accounting=true');
			if ($reponse_du_catalogue['code'] == 403) {
				return array(false, $langs->transnoentities('TiBilletCleSansDroitProduits'));
			}
			if ($reponse_du_catalogue['code'] != 200) {
				return array(false, $this->phraseDuRefus($reponse_du_catalogue));
			}
			$phrase .= ' '.$langs->transnoentities('TiBilletCatalogueOk', count($reponse_du_catalogue['donnees']['results']));

			// Et le droit « Ventes » : TiBillet verifie le droit AVANT de chercher la
			// cloture. Un uuid nul donne donc 403 sans le droit, 404 avec.
			// / And the "sale" right: checked before the closure lookup.
			$reponse_des_ventes = $this->appelerTiBillet('/api/v2/closures/00000000-0000-0000-0000-000000000000/sales/');
			if ($reponse_des_ventes['code'] == 403) {
				return array(false, $langs->transnoentities('TiBilletCleSansDroitVentes'));
			}
			if ($reponse_des_ventes['code'] != 404 && $reponse_des_ventes['code'] != 200) {
				return array(false, $this->phraseDuRefus($reponse_des_ventes));
			}
		}
		return array(true, $phrase);
	}

	// ------------------------------------------------------------------
	// Une cloture / One closure
	// ------------------------------------------------------------------

	/**
	 * Ecrit une cloture dans le grand livre, en UNE transaction : ses ecritures, puis
	 * sa ligne dans la table. Avec $ligne_existante (reimport) : retire d'abord ses
	 * anciennes pieces, puis met a jour la ligne.
	 * / Writes one closure in one transaction; with $ligne_existante, re-import.
	 *
	 * @param string      $uuid             uuid TiBillet de la cloture
	 * @param User        $user             L'utilisateur auteur des lignes
	 * @param object|null $ligne_existante  La ligne de la table (reimport), ou null
	 * @return int Nombre de journaux crees, ou -1 (voir $this->error)
	 */
	private function importerUneCloture($uuid, $user, $ligne_existante)
	{
		global $langs;

		// L'adresse est reconstruite depuis l'uuid : la cle ne part que vers
		// TIBILLET_URL, jamais vers une adresse lue dans une reponse.
		// / The URL is rebuilt from the uuid: the key only goes to TIBILLET_URL.
		if (!preg_match('/^[0-9a-f-]{36}$/i', $uuid)) {
			$this->error = $langs->transnoentities('TiBilletReponseIllisible');
			return -1;
		}
		// Une cloture dont une partie des ventes est deja en factures (un passage du
		// mode « ventes » arrete par une vente refusee) ne va jamais dans le grand
		// livre : ces ventes y seraient comptees deux fois.
		// / A closure partly copied as invoices never goes into the ledger.
		$sql = "SELECT COUNT(*) as nb FROM ".$this->db->prefix()."tibillet_vente_importee";
		$sql .= " WHERE cloture_uuid = '".$this->db->escape($uuid)."' AND entity = ".((int) $this->entity);
		$resql = $this->db->query($sql);
		$ligne_des_ventes = $resql ? $this->db->fetch_object($resql) : null;
		if ($ligne_des_ventes && (int) $ligne_des_ventes->nb > 0) {
			$this->error = $langs->transnoentities('TiBilletClotureEnPartieEnVentes');
			return -1;
		}
		$reponse = $this->appelerTiBillet('/api/v2/closures/'.$uuid.'/entries/');
		if ($reponse['code'] != 200) {
			$this->error = $this->phraseDuRefus($reponse);
			return -1;
		}
		$donnees = $reponse['donnees'];
		if (!is_array($donnees) || !isset($donnees['entries'], $donnees['accountingDate'], $donnees['number'])) {
			$this->error = $langs->transnoentities('TiBilletReponseIllisible');
			return -1;
		}

		// La date des ecritures, a midi : aucun decalage de fuseau ne la fait changer
		// de jour. / Entries date at noon: no time zone shift changes the day.
		$date_des_ecritures = $this->dateDolibarr($donnees['accountingDate']);
		if ($date_des_ecritures === null) {
			$this->error = $langs->transnoentities('TiBilletReponseIllisible');
			return -1;
		}

		// L'exercice comptable doit etre ouvert, avant d'ecrire quoi que ce soit.
		// / The fiscal year must be open, before writing anything.
		$grand_livre = new BookKeeping($this->db);
		if ($grand_livre->validBookkeepingDate($date_des_ecritures) <= 0) {
			$this->error = $langs->transnoentities('TiBilletExerciceFerme', dol_print_date($date_des_ecritures, 'day'), (int) $donnees['number']);
			return -1;
		}

		$this->db->begin();

		// Reimport : retirer d'abord les anciennes pieces, toutes ou aucune.
		// / Re-import: remove the old pieces first, all or none.
		if ($ligne_existante !== null) {
			if ($this->retirerLesPieces($ligne_existante->pieces, (int) $ligne_existante->numero) < 0) {
				$this->db->rollback();
				return -1;
			}
		}

		$pieces_ecrites = array();
		$nombre_de_journaux_crees = 0;
		foreach ($donnees['entries'] as $ecriture) {
			// Garde dans le grand livre lui-meme : une ecriture deja presente n'est pas
			// reecrite, meme si la table du module a ete videe.
			// / Guard in the ledger itself, even if the module table was emptied.
			if ($this->ecritureDejaDansLeGrandLivre($ecriture['EcritureNum'])) {
				$this->error = $langs->transnoentities('TiBilletEcritureDejaPresente', $ecriture['EcritureNum']);
				$this->db->rollback();
				return -1;
			}

			$journal_cree = $this->creerLeJournalSiAbsent($ecriture['JournalCode'], $ecriture['JournalLib'], $user);
			if ($journal_cree < 0) {
				$this->db->rollback();
				return -1;
			}
			$nombre_de_journaux_crees += $journal_cree;

			$piece = $this->ecrireUneEcriture($ecriture, $date_des_ecritures, $user);
			if ($piece < 0) {
				$this->db->rollback();
				return -1;
			}
			$pieces_ecrites[] = $piece;
		}

		if ($this->noterLaCloture($uuid, $donnees, $date_des_ecritures, $pieces_ecrites, $ligne_existante) < 0) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return $nombre_de_journaux_crees;
	}

	/**
	 * Ecrit une ecriture (un journal d'une cloture) : un numero de piece donne par
	 * Dolibarr, puis une ligne par compte. Verifie l'equilibre sur les centimes entiers.
	 * / Writes one entry: one piece number from Dolibarr, one line per account.
	 *
	 * @param array $ecriture            L'ecriture du JSON TiBillet
	 * @param int   $date_des_ecritures  Timestamp de la date des ecritures
	 * @param User  $user                Auteur
	 * @return int Le numero de piece, ou -1
	 */
	private function ecrireUneEcriture($ecriture, $date_des_ecritures, $user)
	{
		global $langs;

		// Equilibre, en centimes entiers (jamais en flottants).
		// / Balance, in whole cents (never floats).
		$total_des_debits = 0;
		$total_des_credits = 0;
		foreach ($ecriture['lines'] as $ligne) {
			$total_des_debits += (int) $ligne['Debit'];
			$total_des_credits += (int) $ligne['Credit'];
		}
		if ($total_des_debits !== $total_des_credits) {
			$this->error = $langs->transnoentities('TiBilletEcritureDesequilibree', $ecriture['EcritureNum']);
			return -1;
		}

		// Dans la transaction, getNextNumMvt voit les pieces deja ecrites : deux
		// journaux d'une meme cloture ont deux numeros differents.
		// / Inside the transaction, getNextNumMvt sees the pieces already written.
		$grand_livre = new BookKeeping($this->db);
		$numero_de_piece = $grand_livre->getNextNumMvt();
		if ($numero_de_piece <= 0) {
			$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $grand_livre->error);
			return -1;
		}

		foreach ($ecriture['lines'] as $ligne) {
			$debit_en_centimes = (int) $ligne['Debit'];
			$credit_en_centimes = (int) $ligne['Credit'];

			$ligne_du_grand_livre = new BookKeeping($this->db);
			$ligne_du_grand_livre->doc_date = $date_des_ecritures;
			$ligne_du_grand_livre->doc_type = self::TYPE_DE_DOCUMENT;
			$ligne_du_grand_livre->doc_ref = $ecriture['EcritureNum'];
			$ligne_du_grand_livre->fk_doc = 0;
			$ligne_du_grand_livre->fk_docdet = 0;
			$ligne_du_grand_livre->code_journal = $ecriture['JournalCode'];
			$ligne_du_grand_livre->journal_label = $ecriture['JournalLib'];
			$ligne_du_grand_livre->numero_compte = $this->numeroDeCompteDolibarr($ligne['CompteNum']);
			$ligne_du_grand_livre->label_compte = $ligne['CompteLib'];
			$ligne_du_grand_livre->label_operation = $ecriture['EcritureLib'];
			$ligne_du_grand_livre->debit = $debit_en_centimes / 100;
			$ligne_du_grand_livre->credit = $credit_en_centimes / 100;
			$ligne_du_grand_livre->montant = ($debit_en_centimes > 0 ? $debit_en_centimes : $credit_en_centimes) / 100;
			$ligne_du_grand_livre->sens = ($debit_en_centimes > 0 ? 'D' : 'C');
			$ligne_du_grand_livre->piece_num = $numero_de_piece;
			$ligne_du_grand_livre->entity = $this->entity;

			if ($ligne_du_grand_livre->createStd($user) < 0) {
				$this->error = $langs->transnoentities('TiBilletErreurDolibarr', implode(', ', array_merge(array($ligne_du_grand_livre->error), $ligne_du_grand_livre->errors)));
				return -1;
			}
		}
		return $numero_de_piece;
	}

	/**
	 * Retire les pieces d'une cloture (reimport). Refuse si une ligne est validee, et
	 * verifie que TOUTES les lignes sont parties (sinon la piece serait amputee).
	 * / Removes a closure's pieces; refused if a line is validated; all lines or none.
	 *
	 * @param string $pieces  Les numeros de piece, separes par des virgules
	 * @param int    $numero  Le numero TiBillet de la cloture (pour les messages)
	 * @return int 1 si OK, -1 sinon
	 */
	private function retirerLesPieces($pieces, $numero)
	{
		global $langs;

		$numeros_de_piece = array();
		foreach (explode(',', $pieces) as $piece) {
			if ((int) $piece > 0) {
				$numeros_de_piece[] = (int) $piece;
			}
		}
		if (empty($numeros_de_piece)) {
			return 1;
		}
		$liste_sql = implode(',', $numeros_de_piece);

		$sql = "SELECT COUNT(*) as nb_lignes, SUM(CASE WHEN date_validated IS NOT NULL THEN 1 ELSE 0 END) as nb_validees";
		$sql .= " FROM ".$this->db->prefix()."accounting_bookkeeping";
		$sql .= " WHERE piece_num IN (".$liste_sql.") AND entity = ".((int) $this->entity);
		$resql = $this->db->query($sql);
		$compte = $resql ? $this->db->fetch_object($resql) : null;
		if (!$compte) {
			$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $this->db->lasterror());
			return -1;
		}
		if ((int) $compte->nb_validees > 0) {
			$this->error = $langs->transnoentities('TiBilletReimportRefuseValidee', $numero);
			return -1;
		}

		$lignes_retirees = 0;
		foreach ($numeros_de_piece as $numero_de_piece) {
			$grand_livre = new BookKeeping($this->db);
			$resultat = $grand_livre->deleteMvtNum($numero_de_piece);
			if ($resultat < 0) {
				$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $grand_livre->error);
				return -1;
			}
			$lignes_retirees += $resultat;
		}
		if ($lignes_retirees != (int) $compte->nb_lignes) {
			$this->error = $langs->transnoentities('TiBilletReimportRefuseExercice', $numero);
			return -1;
		}
		return 1;
	}

	/**
	 * Ecrit (ou met a jour, au reimport) la ligne de la cloture dans la table du module.
	 * / Writes (or updates, on re-import) the closure's row in the module table.
	 *
	 * @return int 1 si OK, -1 sinon
	 */
	protected function noterLaCloture($uuid, $donnees, $date_des_ecritures, $pieces_ecrites, $ligne_existante, $mode = self::MODE_ECRITURES)
	{
		global $langs;
		$pieces = implode(',', $pieces_ecrites);

		if ($ligne_existante === null) {
			$sql = "INSERT INTO ".$this->db->prefix()."tibillet_cloture_importee";
			$sql .= " (entity, cloture_uuid, numero, date_comptable, date_import, pieces, mode) VALUES (";
			$sql .= ((int) $this->entity).", '".$this->db->escape($uuid)."', ".((int) $donnees['number']).", ";
			$sql .= "'".$this->db->idate($date_des_ecritures)."', '".$this->db->idate(dol_now())."', ";
			$sql .= "'".$this->db->escape($pieces)."', '".$this->db->escape($mode)."')";
		} else {
			$sql = "UPDATE ".$this->db->prefix()."tibillet_cloture_importee";
			$sql .= " SET pieces = '".$this->db->escape($pieces)."', date_import = '".$this->db->idate(dol_now())."'";
			$sql .= " WHERE rowid = ".((int) $ligne_existante->rowid);
		}
		if (!$this->db->query($sql)) {
			$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $this->db->lasterror());
			return -1;
		}
		return 1;
	}

	// ------------------------------------------------------------------
	// Comptes et journaux / Accounts and journals
	// ------------------------------------------------------------------

	/**
	 * Cree dans le plan actif les comptes de TiBillet qui manquent. Ne modifie jamais
	 * un compte existant.
	 * / Creates TiBillet's missing accounts in the active chart; never edits one.
	 *
	 * @param User $user Auteur
	 * @return int Nombre de comptes crees, ou -1
	 */
	protected function creerLesComptesManquants($user)
	{
		global $langs;

		$reponse = $this->appelerTiBillet('/api/v2/accounts/');
		if ($reponse['code'] != 200) {
			$this->error = $this->phraseDuRefus($reponse);
			return -1;
		}

		$version_du_plan = $this->versionDuPlanActif();
		if ($version_du_plan === '') {
			$this->error = $langs->transnoentities('TiBilletPasDePlanComptable');
			return -1;
		}

		$nombre_de_comptes_crees = 0;
		foreach ($reponse['donnees']['results'] as $compte_tibillet) {
			$numero = $this->numeroDeCompteDolibarr($compte_tibillet['accountNumber']);
			$compte = new AccountingAccount($this->db);
			if ($compte->fetch(0, $numero, 1) > 0) {
				continue;
			}

			$nouveau_compte = new AccountingAccount($this->db);
			$nouveau_compte->fk_pcg_version = $version_du_plan;
			$nouveau_compte->account_number = $numero;
			$nouveau_compte->label = $compte_tibillet['name'];
			$nouveau_compte->labelshort = '';
			$nouveau_compte->pcg_type = $this->typeDeCompte($numero);
			$nouveau_compte->account_parent = $this->compteParent($numero);
			$nouveau_compte->active = 1;
			if ($nouveau_compte->create($user) < 0) {
				$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $nouveau_compte->error);
				return -1;
			}
			$nombre_de_comptes_crees++;
		}
		return $nombre_de_comptes_crees;
	}

	/**
	 * Cree le journal s'il n'existe pas : nature « operations diverses », actif.
	 * / Creates the journal if missing: "various operations" nature, active.
	 *
	 * @return int 1 si cree, 0 s'il existait, -1 si erreur
	 */
	private function creerLeJournalSiAbsent($code, $libelle, $user)
	{
		global $langs;

		$journal = new AccountingJournal($this->db);
		if ($journal->fetch(0, $code) > 0) {
			return 0;
		}
		$nouveau_journal = new AccountingJournal($this->db);
		$nouveau_journal->code = $code;
		$nouveau_journal->label = $libelle;
		$nouveau_journal->nature = self::NATURE_DES_JOURNAUX;
		$nouveau_journal->active = 1;
		$nouveau_journal->entity = $this->entity;
		if ($nouveau_journal->create($user) < 0) {
			$this->error = $langs->transnoentities('TiBilletErreurDolibarr', $nouveau_journal->error);
			return -1;
		}
		return 1;
	}

	/**
	 * Le numero d'un compte sous la forme Dolibarr : sans les zeros de droite (707000
	 * devient 707), sauf si ACCOUNTING_MANAGE_ZERO est active.
	 * Dolibarr applique la meme regle au compte d'un produit (Product::create et
	 * update, meme sans comptabilite) : un autre format ferait reecrire tous les
	 * produits a chaque passage du catalogue. Pour garder 6 chiffres partout, le lieu
	 * allume ACCOUNTING_MANAGE_ZERO.
	 * / Account number the Dolibarr way: trailing zeros removed unless MANAGE_ZERO.
	 * Dolibarr does the same on products.
	 */
	protected function numeroDeCompteDolibarr($numero)
	{
		$numero = trim((string) $numero);
		if (getDolGlobalString('ACCOUNTING_MANAGE_ZERO')) {
			return $numero;
		}
		$numero_court = rtrim($numero, '0');
		return ($numero_court === '') ? $numero : $numero_court;
	}

	/**
	 * Le type Dolibarr d'un compte, d'apres sa classe (premier chiffre).
	 * / The Dolibarr account type, from its class (first digit).
	 */
	private function typeDeCompte($numero)
	{
		$type_par_classe = array(
			'1' => 'CAPIT',
			'2' => 'IMMO',
			'3' => 'STOCK',
			'4' => 'THIRDPARTY',
			'5' => 'FINAN',
			'6' => 'EXPENSE',
			'7' => 'INCOME',
		);
		$classe = substr($numero, 0, 1);
		return isset($type_par_classe[$classe]) ? $type_par_classe[$classe] : 'XXXXXX';
	}

	/**
	 * Le compte parent dans le plan actif : on retire un chiffre a la fois (7079 → 707).
	 * Sans parent, la balance de Dolibarr ne range pas le compte dans son groupe.
	 * / Parent account in the active chart: remove one digit at a time.
	 *
	 * @return int rowid du parent, ou 0
	 */
	private function compteParent($numero)
	{
		for ($longueur = strlen($numero) - 1; $longueur >= 1; $longueur--) {
			$compte = new AccountingAccount($this->db);
			if ($compte->fetch(0, substr($numero, 0, $longueur), 1) > 0) {
				return (int) $compte->id;
			}
		}
		return 0;
	}

	/**
	 * Le code du plan comptable actif (ex. PCG18-ASSOC), ou '' si aucun.
	 * / The active chart code, or '' if none.
	 */
	protected function versionDuPlanActif()
	{
		$sql = "SELECT pcg_version FROM ".$this->db->prefix()."accounting_system";
		$sql .= " WHERE rowid = ".((int) getDolGlobalInt('CHARTOFACCOUNTS'));
		$resql = $this->db->query($sql);
		$ligne = $resql ? $this->db->fetch_object($resql) : null;
		return $ligne ? (string) $ligne->pcg_version : '';
	}

	// ------------------------------------------------------------------
	// La table du module / The module table
	// ------------------------------------------------------------------

	/** Le plus grand numero TiBillet importe (0 si aucun) / Highest imported number */
	protected function plusGrandNumeroImporte()
	{
		$sql = "SELECT MAX(numero) as plus_grand FROM ".$this->db->prefix()."tibillet_cloture_importee";
		$sql .= " WHERE entity = ".((int) $this->entity);
		$resql = $this->db->query($sql);
		$ligne = $resql ? $this->db->fetch_object($resql) : null;
		return ($ligne && $ligne->plus_grand !== null) ? (int) $ligne->plus_grand : 0;
	}

	/** Vrai si la cloture est deja dans la table / True if already imported */
	protected function clotureDejaImportee($uuid)
	{
		$sql = "SELECT rowid FROM ".$this->db->prefix()."tibillet_cloture_importee";
		$sql .= " WHERE cloture_uuid = '".$this->db->escape($uuid)."' AND entity = ".((int) $this->entity);
		$resql = $this->db->query($sql);
		return ($resql && $this->db->num_rows($resql) > 0);
	}

	/** Vrai si une ligne TiBillet porte deja ce doc_ref / True if doc_ref already there */
	private function ecritureDejaDansLeGrandLivre($numero_d_ecriture)
	{
		$sql = "SELECT COUNT(*) as nb FROM ".$this->db->prefix()."accounting_bookkeeping";
		$sql .= " WHERE doc_type = '".$this->db->escape(self::TYPE_DE_DOCUMENT)."'";
		$sql .= " AND doc_ref = '".$this->db->escape($numero_d_ecriture)."'";
		$sql .= " AND entity = ".((int) $this->entity);
		$resql = $this->db->query($sql);
		$ligne = $resql ? $this->db->fetch_object($resql) : null;
		return ($ligne && (int) $ligne->nb > 0);
	}

	// ------------------------------------------------------------------
	// L'appel a TiBillet / The call to TiBillet
	// ------------------------------------------------------------------

	/** Vrai si l'adresse et la cle sont remplies / True if address and key are set */
	protected function moduleEstConfigure()
	{
		return getDolGlobalString('TIBILLET_URL') !== '' && getDolGlobalString('TIBILLET_API_KEY') !== '';
	}

	/**
	 * Un GET sur l'API de TiBillet, par cURL direct. Rend le code HTTP, le JSON lu et
	 * l'erreur reseau. La cle n'est ecrite dans aucun journal.
	 * / A GET on TiBillet's API, direct cURL. The key is never logged.
	 *
	 * @param string   $chemin            Le chemin, ex. '/api/v2/accounts/'
	 * @param string[] $en_tetes_en_plus  D'autres en-tetes, ex. 'If-None-Match: "…"'
	 * @return array{code: int, donnees: mixed, erreur: string, etag: string}
	 */
	protected function appelerTiBillet($chemin, $en_tetes_en_plus = array())
	{
		global $langs;

		$adresse = rtrim(getDolGlobalString('TIBILLET_URL'), '/').$chemin;
		$mode_developpement = getDolGlobalInt('TIBILLET_RESEAU_LOCAL') == 1;
		$morceaux = parse_url($adresse);
		if (empty($morceaux['scheme']) || empty($morceaux['host'])) {
			return array('code' => 0, 'donnees' => null, 'erreur' => $langs->transnoentities('TiBilletAdresseInvalide'), 'etag' => '');
		}
		// En production, la cle ne circule qu'en https.
		// / In production, the key only travels over https.
		if (!$mode_developpement && $morceaux['scheme'] !== 'https') {
			return array('code' => 0, 'donnees' => null, 'erreur' => $langs->transnoentities('TiBilletHttpsObligatoire'), 'etag' => '');
		}

		$appel = curl_init($adresse);
		curl_setopt($appel, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($appel, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($appel, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($appel, CURLOPT_TIMEOUT, 120);
		curl_setopt($appel, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
		curl_setopt($appel, CURLOPT_HTTPHEADER, array_merge(array(
			'Authorization: Api-Key '.getDolGlobalString('TIBILLET_API_KEY'),
			'Accept: application/json',
		), $en_tetes_en_plus));
		// L'en-tete ETag de la reponse (catalogue) : lu au passage, ligne par ligne.
		// / The response ETag header (catalogue), read line by line.
		$etag_recu = '';
		curl_setopt($appel, CURLOPT_HEADERFUNCTION, function ($appel_en_cours, $ligne_d_en_tete) use (&$etag_recu) {
			if (stripos($ligne_d_en_tete, 'ETag:') === 0) {
				$etag_recu = trim(substr($ligne_d_en_tete, 5));
			}
			return strlen($ligne_d_en_tete);
		});
		if ($mode_developpement) {
			// Dev : curl envoie tout nom en *.localhost vers lui-meme ; on lui donne
			// l'adresse lue dans /etc/hosts. Certificat de dev non verifie.
			// / Dev: curl maps *.localhost to loopback; give it the /etc/hosts address.
			$port = !empty($morceaux['port']) ? $morceaux['port'] : ($morceaux['scheme'] === 'https' ? 443 : 80);
			$adresse_ip = gethostbyname($morceaux['host']);
			if ($adresse_ip !== $morceaux['host']) {
				curl_setopt($appel, CURLOPT_RESOLVE, array($morceaux['host'].':'.$port.':'.$adresse_ip));
			}
			curl_setopt($appel, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($appel, CURLOPT_SSL_VERIFYHOST, 0);
		}

		$contenu = curl_exec($appel);
		$code_http = (int) curl_getinfo($appel, CURLINFO_HTTP_CODE);
		$erreur_reseau = curl_error($appel);
		curl_close($appel);

		$donnees = null;
		if (is_string($contenu) && $contenu !== '') {
			$donnees = json_decode($contenu, true);
		}
		return array('code' => $code_http, 'donnees' => $donnees, 'erreur' => $erreur_reseau, 'etag' => $etag_recu);
	}

	/**
	 * La phrase a montrer pour une reponse qui n'est pas 200 : celle de TiBillet si elle
	 * existe, sinon le code HTTP ou l'erreur reseau.
	 * / The sentence for a non-200 response.
	 */
	protected function phraseDuRefus($reponse)
	{
		global $langs;
		if ($reponse['code'] == 0) {
			return $langs->transnoentities('TiBilletInjoignable', $reponse['erreur']);
		}
		if ($reponse['code'] == 403) {
			return $langs->transnoentities('TiBilletCleRefusee');
		}
		$detail = (is_array($reponse['donnees']) && isset($reponse['donnees']['detail'])) ? $reponse['donnees']['detail'] : '';
		return $langs->transnoentities('TiBilletRefus', $reponse['code'], $detail);
	}

	// ------------------------------------------------------------------
	// Petits outils / Small helpers
	// ------------------------------------------------------------------

	/** 'AAAA-MM-JJ' → timestamp Dolibarr a midi, ou null / ISO date to noon timestamp */
	protected function dateDolibarr($date_iso)
	{
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date_iso, $morceaux)) {
			return null;
		}
		return dol_mktime(12, 0, 0, (int) $morceaux[2], (int) $morceaux[3], (int) $morceaux[1], 'tzserver');
	}

	/** Le resume d'un passage / The summary of a run */
	private function resumeDuPassage($numeros_importes, $nombre_de_comptes_crees, $nombre_de_journaux_crees)
	{
		global $langs;
		$plage = empty($numeros_importes) ? '-' : (min($numeros_importes).' - '.max($numeros_importes));
		return $langs->transnoentities('TiBilletResume', count($numeros_importes), $plage, (int) $nombre_de_comptes_crees, (int) $nombre_de_journaux_crees);
	}
}
