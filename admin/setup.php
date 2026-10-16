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
 * La page du module TiBillet : reglages (mode, heure de la tache, adresse, cle), test
 * de connexion, import ou synchro a la demande, liste des clotures importees et
 * « Reimporter », dernier passage du catalogue et des ventes, banques et client de
 * passage du mode « articles et ventes ». Reservee aux admins.
 * / The TiBillet module page: settings, connection test, import or sync on demand,
 * imported closures and "Re-import", last catalogue run. Admins only.
 *
 * Toutes les actions sont des POST avec le jeton CSRF de Dolibarr (newToken()).
 * La cle d'API n'est jamais affichee : le champ reste vide, « vide = inchangee ».
 * / Every action is a POST with Dolibarr's CSRF token. The API key is never shown.
 */

// Charger Dolibarr (meme recherche que le modele de module de Dolibarr).
// / Load Dolibarr (same lookup as Dolibarr's module template).
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/tibillet/class/importtibillet.class.php');
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

$langs->loadLangs(array('admin', 'tibillet@tibillet'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$adresse_de_la_page = $_SERVER['PHP_SELF'];

// ------------------------------------------------------------------
// Les actions (toutes en POST) / Actions (all POST)
// ------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if ($action === 'enregistrer') {
		$adresse_du_lieu = trim(GETPOST('tibillet_url', 'alphanohtml'));
		$nouvelle_cle = trim(GETPOST('tibillet_cle', 'alphanohtml'));
		$mode_developpement = GETPOST('tibillet_reseau_local', 'int') ? 1 : 0;

		// Le mode : un seul a la fois. Le changement est libre (decision du mainteneur),
		// mais on previent si des clotures sont deja dans le grand livre.
		// / The mode: one at a time. Free to change, with a warning if closures exist.
		$mode_d_avant = ImportTiBillet::modeChoisi();
		$mode_demande = GETPOST('tibillet_mode', 'aZ09');
		if (in_array($mode_demande, array(ImportTiBillet::MODE_ECRITURES, ImportTiBillet::MODE_VENTES), true)) {
			dolibarr_set_const($db, 'TIBILLET_MODE', $mode_demande, 'chaine', 0, '', $conf->entity);
			$import = new ImportTiBillet($db);
			$nombre_de_clotures_importees = $import->nombreDeCloturesImportees();
			if ($mode_demande !== $mode_d_avant && $nombre_de_clotures_importees > 0) {
				setEventMessages($langs->trans('TiBilletModeChangeAlerte', $nombre_de_clotures_importees), null, 'warnings');
			}
			// Le mode « articles et ventes » range des produits, des categories, des
			// factures, des tiers et des banques : il active les modules de Dolibarr qui
			// les portent. Le mode « ecritures » n'en a pas besoin : ce ne sont pas des
			// dependances du module.
			// / Items and sales mode turns on the Dolibarr modules it fills.
			if ($mode_demande === ImportTiBillet::MODE_VENTES) {
				$modules_du_mode_ventes = array(
					'product' => 'modProduct',
					'service' => 'modService',
					'category' => 'modCategorie',
					'societe' => 'modSociete',
					'facture' => 'modFacture',
					'banque' => 'modBanque',
				);
				foreach ($modules_du_mode_ventes as $nom_court => $module_a_activer) {
					if (!isModEnabled($nom_court)) {
						activateModule($module_a_activer);
					}
				}
			}
		}

		// L'heure de la tache de nuit. Une heure invalide est refusee : la tache ne bouge pas.
		// / The nightly job time. An invalid time is refused: the job does not move.
		$heure_demandee = trim(GETPOST('tibillet_heure', 'alphanohtml'));
		if (ImportTiBillet::heureEstValide($heure_demandee)) {
			dolibarr_set_const($db, 'TIBILLET_HEURE', $heure_demandee, 'chaine', 0, '', $conf->entity);
			$import = new ImportTiBillet($db);
			if ($import->recalerLaTacheDeNuit($user) < 0) {
				setEventMessages($import->error, null, 'errors');
			}
		} else {
			setEventMessages($langs->trans('TiBilletHeureInvalide'), null, 'errors');
		}

		dolibarr_set_const($db, 'TIBILLET_URL', $adresse_du_lieu, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'TIBILLET_RESEAU_LOCAL', $mode_developpement, 'chaine', 0, '', $conf->entity);
		// Vide = garder la cle enregistree. Le suffixe _KEY fait chiffrer la cle par
		// Dolibarr (dolEncrypt) dans la base.
		// / Empty = keep the saved key. The _KEY suffix makes Dolibarr encrypt it.
		if ($nouvelle_cle !== '') {
			dolibarr_set_const($db, 'TIBILLET_API_KEY', $nouvelle_cle, 'chaine', 0, '', $conf->entity);
		}
		setEventMessages($langs->trans('TiBilletReglagesEnregistres'), null, 'mesgs');
	}

	if ($action === 'tester') {
		$import = new ImportTiBillet($db);
		list($connexion_reussie, $phrase) = $import->testerLaConnexion();
		setEventMessages($phrase, null, $connexion_reussie ? 'mesgs' : 'errors');
		// En mode « articles et ventes », le test prepare aussi Dolibarr et dit ce
		// qu'il a regle et ce qui manque encore.
		// / In sales mode, the test also prepares Dolibarr and reports.
		if ($connexion_reussie && ImportTiBillet::modeChoisi() === ImportTiBillet::MODE_VENTES) {
			dol_include_once('/tibillet/class/reglages.class.php');
			$reglages = new ReglagesTiBillet($db);
			$reglages->preparer($user);
			// Ces phrases portent des libelles venus de TiBillet (comptes, banques) :
			// echappees, setEventMessages() affiche du HTML.
			// / These sentences hold TiBillet labels: escaped, messages render HTML.
			if (!empty($reglages->faits)) {
				setEventMessages(null, array_map('dol_escape_htmltag', $reglages->faits), 'mesgs');
			}
			if (!empty($reglages->manques)) {
				setEventMessages($langs->trans('TiBilletReglagesAFaire'), array_map('dol_escape_htmltag', $reglages->manques), 'warnings');
			} else {
				setEventMessages($langs->trans('TiBilletReglagesPrets'), null, 'mesgs');
			}
		}
	}

	if ($action === 'importer_ventes') {
		dol_include_once('/tibillet/class/ventes.class.php');
		$ventes = new VentesTiBillet($db);
		$resultat = $ventes->importerLesVentes();
		if ($ventes->output !== '') {
			setEventMessages(nl2br(dol_escape_htmltag($ventes->output, 0, 1)), null, $resultat < 0 ? 'warnings' : 'mesgs');
		}
		if ($resultat < 0) {
			setEventMessages(dol_escape_htmltag($ventes->error), null, 'errors');
		}
	}

	// Les banques de chaque compte de tresorerie, et le client de passage (D11, D6).
	// Une banque de caisse n'accepte que les especes : refusee pour un autre compte
	// que 53x.
	// / Bank of each treasury account, and the walk-in customer.
	if ($action === 'banques') {
		foreach ($_POST as $nom_du_champ => $valeur) {
			if (!preg_match('/^banque_([0-9A-Za-z]+)$/', $nom_du_champ, $morceaux)) {
				continue;
			}
			$numero_tibillet = $morceaux[1];
			$id_de_la_banque = GETPOSTINT($nom_du_champ);
			$banque = new Account($db);
			if ($id_de_la_banque <= 0 || $banque->fetch($id_de_la_banque) <= 0) {
				continue;
			}
			if ((int) $banque->type === Account::TYPE_CASH && strpos($numero_tibillet, '53') !== 0) {
				setEventMessages($langs->trans('TiBilletBanqueDeCaisseRefusee', $numero_tibillet, $banque->label), null, 'errors');
				continue;
			}
			dolibarr_set_const($db, 'TIBILLET_BANQUE_'.$numero_tibillet, $id_de_la_banque, 'chaine', 0, '', $conf->entity);
		}
		$id_du_client_de_passage = GETPOSTINT('tiers_de_passage');
		if ($id_du_client_de_passage > 0) {
			dolibarr_set_const($db, 'TIBILLET_TIERS_DE_PASSAGE', $id_du_client_de_passage, 'chaine', 0, '', $conf->entity);
		}
		setEventMessages($langs->trans('TiBilletReglagesEnregistres'), null, 'mesgs');
	}

	if ($action === 'importer') {
		$import = new ImportTiBillet($db);
		$resultat = $import->importerLesClotures();
		if ($import->output !== '') {
			setEventMessages($import->output, null, $resultat < 0 ? 'warnings' : 'mesgs');
		}
		if ($resultat < 0) {
			setEventMessages($import->error, null, 'errors');
		}
	}

	if ($action === 'synchroniser') {
		dol_include_once('/tibillet/class/catalogue.class.php');
		$catalogue = new CatalogueTiBillet($db);
		$resultat = $catalogue->synchroniserLeCatalogue();
		if ($catalogue->output !== '') {
			setEventMessages(nl2br(dol_escape_htmltag($catalogue->output, 0, 1)), null, $resultat < 0 ? 'warnings' : 'mesgs');
		}
		if ($resultat < 0) {
			setEventMessages($catalogue->error, null, 'errors');
		}
	}

	if ($action === 'reimporter') {
		$import = new ImportTiBillet($db);
		if ($import->reimporterUneCloture(GETPOSTINT('rowid')) > 0) {
			setEventMessages($langs->trans('TiBilletClotureReimportee'), null, 'mesgs');
		} else {
			setEventMessages($import->error, null, 'errors');
		}
	}

	// Apres un POST, on revient en GET : un rechargement de la page ne rejoue rien.
	// / After a POST, back to GET: reloading the page replays nothing.
	header('Location: '.$adresse_de_la_page);
	exit;
}

// ------------------------------------------------------------------
// La page / The page
// ------------------------------------------------------------------

llxHeader('', $langs->trans('TiBilletReglages'), '', '', 0, 0, '', '', '', 'mod-tibillet page-admin');

$lien_retour = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('TiBilletReglages'), $lien_retour, 'title_setup');
$mode_actuel = ImportTiBillet::modeChoisi();
$explication = ($mode_actuel === ImportTiBillet::MODE_VENTES) ? 'TiBilletExplicationVentes' : 'TiBilletExplication';
print '<div class="opacitymedium">'.$langs->trans($explication, ImportTiBillet::heureDeLaTache()).'</div><br>';

// Les reglages / Settings
print '<form method="POST" action="'.$adresse_de_la_page.'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="enregistrer">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('TiBilletMode').'<br><span class="opacitymedium small">'.$langs->trans('TiBilletModeAide').'</span></td><td>';
foreach (array(ImportTiBillet::MODE_VENTES => 'TiBilletModeVentes', ImportTiBillet::MODE_ECRITURES => 'TiBilletModeEcritures') as $valeur_du_mode => $libelle_du_mode) {
	$mode_coche = (ImportTiBillet::modeChoisi() === $valeur_du_mode) ? ' checked' : '';
	print '<label><input type="radio" name="tibillet_mode" value="'.$valeur_du_mode.'"'.$mode_coche.'> '.$langs->trans($libelle_du_mode).'</label><br>';
}
print '</td></tr>';

$import_pour_la_page = new ImportTiBillet($db);
$prochain_passage = $import_pour_la_page->prochainPassage();
$phrase_du_prochain_passage = $prochain_passage ? $langs->trans('TiBilletProchainPassage', dol_print_date($prochain_passage, 'dayhour', 'tzuserrel')) : '';
print '<tr class="oddeven"><td>'.$langs->trans('TiBilletHeure').'<br><span class="opacitymedium small">'.$langs->trans('TiBilletHeureAide').'</span></td>';
print '<td><input type="time" name="tibillet_heure" value="'.dol_escape_htmltag(ImportTiBillet::heureDeLaTache()).'"> <span class="opacitymedium">'.dol_escape_htmltag($phrase_du_prochain_passage).'</span></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('TiBilletAdresse').'<br><span class="opacitymedium small">'.$langs->trans('TiBilletAdresseAide').'</span></td>';
print '<td><input type="text" class="minwidth400" name="tibillet_url" value="'.dol_escape_htmltag(getDolGlobalString('TIBILLET_URL')).'"></td></tr>';

$etat_de_la_cle = getDolGlobalString('TIBILLET_API_KEY') !== '' ? $langs->trans('TiBilletCleDefinie') : $langs->trans('TiBilletCleAbsente');
$aide_de_la_cle = ($mode_actuel === ImportTiBillet::MODE_VENTES) ? 'TiBilletCleAideVentes' : 'TiBilletCleAide';
print '<tr class="oddeven"><td>'.$langs->trans('TiBilletCle').'<br><span class="opacitymedium small">'.$langs->trans($aide_de_la_cle).'</span></td>';
print '<td><input type="password" class="minwidth400" name="tibillet_cle" value="" autocomplete="new-password"> <span class="opacitymedium">'.$etat_de_la_cle.'</span></td></tr>';

$coche = getDolGlobalInt('TIBILLET_RESEAU_LOCAL') ? ' checked' : '';
print '<tr class="oddeven"><td>'.$langs->trans('TiBilletReseauLocal').'<br><span class="opacitymedium small">'.$langs->trans('TiBilletReseauLocalAide').'</span></td>';
print '<td><input type="checkbox" name="tibillet_reseau_local" value="1"'.$coche.'></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('TiBilletEnregistrer').'"></div>';
print '</form><br>';

// Tester, importer / Test, import
print '<div class="center">';
$boutons = array('tester' => 'TiBilletTester', 'importer' => 'TiBilletImporterMaintenant');
if ($mode_actuel === ImportTiBillet::MODE_VENTES) {
	$boutons = array('tester' => 'TiBilletTester', 'synchroniser' => 'TiBilletSynchroniserMaintenant', 'importer_ventes' => 'TiBilletImporterLesVentes');
}
foreach ($boutons as $action_du_bouton => $libelle_du_bouton) {
	print '<form method="POST" action="'.$adresse_de_la_page.'" class="inline-block">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="'.$action_du_bouton.'">';
	print '<input type="submit" class="button" value="'.$langs->trans($libelle_du_bouton).'">';
	print '</form> ';
}
print '</div><br>';

// Le dernier passage du catalogue / Last catalogue run
if ($mode_actuel === ImportTiBillet::MODE_VENTES) {
	print load_fiche_titre($langs->trans('TiBilletCatalogue'), '', '');
	$date_du_dernier_passage = getDolGlobalInt('TIBILLET_CATALOGUE_DATE');
	print '<table class="noborder centpercent"><tr class="oddeven"><td>';
	if ($date_du_dernier_passage > 0) {
		print '<b>'.$langs->trans('TiBilletDernierPassage', dol_print_date($date_du_dernier_passage, 'dayhour', 'tzuserrel')).'</b><br>';
		// Un passage « inchange » (304) garde le resume du dernier passage complet.
		// / An "unchanged" run (304) keeps the last full run's summary.
		$date_du_resume = getDolGlobalInt('TIBILLET_CATALOGUE_DATE_RESUME');
		if ($date_du_resume > 0 && $date_du_resume !== $date_du_dernier_passage) {
			print $langs->trans('TiBilletInchangeResumeDu', dol_print_date($date_du_resume, 'dayhour', 'tzuserrel')).'<br>';
		}
		// dol_escape_htmltag() remplace un retour a la ligne par « \n » sauf avec
		// $keepn = 1 (3e argument) : nl2br() en fait ensuite des <br>.
		// / dol_escape_htmltag() turns newlines into "\n" unless $keepn = 1.
		print nl2br(dol_escape_htmltag(getDolGlobalString('TIBILLET_CATALOGUE_RESUME'), 0, 1));
	} else {
		print '<span class="opacitymedium">'.$langs->trans('TiBilletAucunPassage').'</span>';
	}
	print '</td></tr></table><br>';

	// Le dernier passage des ventes / Last sales run
	print load_fiche_titre($langs->trans('TiBilletVentes'), '', '');
	$date_des_ventes = getDolGlobalInt('TIBILLET_VENTES_DATE');
	print '<table class="noborder centpercent"><tr class="oddeven"><td>';
	if ($date_des_ventes > 0) {
		print '<b>'.$langs->trans('TiBilletDernierPassage', dol_print_date($date_des_ventes, 'dayhour', 'tzuserrel')).'</b><br>';
		print nl2br(dol_escape_htmltag(getDolGlobalString('TIBILLET_VENTES_RESUME'), 0, 1));
	} else {
		print '<span class="opacitymedium">'.$langs->trans('TiBilletAucunPassage').'</span>';
	}
	print '</td></tr></table><br>';

	// Les banques de chaque compte de tresorerie et le client de passage. Une ligne par
	// compte deja vu (le module cree la banque au premier paiement).
	// / The bank of each treasury account seen so far, and the walk-in customer.
	print load_fiche_titre($langs->trans('TiBilletBanques'), '', '');
	print '<div class="opacitymedium">'.$langs->trans('TiBilletBanquesAide').'</div>';
	print '<form method="POST" action="'.$adresse_de_la_page.'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="banques">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('TiBilletCompteTiBillet').'</td><td>'.$langs->trans('TiBilletBanqueDolibarr').'</td></tr>';
	$banques_ouvertes = array();
	$resql_banques = $db->query("SELECT rowid, label, courant FROM ".$db->prefix()."bank_account WHERE clos = 0 AND entity IN (".getEntity('bank_account').") ORDER BY label");
	while ($resql_banques && ($banque_ouverte = $db->fetch_object($resql_banques))) {
		$banques_ouvertes[(int) $banque_ouverte->rowid] = $banque_ouverte->label.((int) $banque_ouverte->courant === Account::TYPE_CASH ? ' ('.$langs->trans('TiBilletCaisse').')' : '');
	}
	$resql_liens = $db->query("SELECT name, value FROM ".$db->prefix()."const WHERE name LIKE 'TIBILLET\\_BANQUE\\_%' AND entity = ".((int) $conf->entity)." ORDER BY name");
	$nombre_de_liens = 0;
	while ($resql_liens && ($lien = $db->fetch_object($resql_liens))) {
		$nombre_de_liens++;
		$numero_tibillet = substr($lien->name, strlen('TIBILLET_BANQUE_'));
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($numero_tibillet).'</td><td>';
		print '<select name="banque_'.dol_escape_htmltag($numero_tibillet).'" class="minwidth300">';
		foreach ($banques_ouvertes as $id_banque => $libelle_banque) {
			$choisie = ((int) $lien->value === $id_banque) ? ' selected' : '';
			print '<option value="'.$id_banque.'"'.$choisie.'>'.dol_escape_htmltag($libelle_banque).'</option>';
		}
		print '</select></td></tr>';
	}
	if ($nombre_de_liens === 0) {
		print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium">'.$langs->trans('TiBilletAucuneBanque').'</span></td></tr>';
	}
	$formulaire = new Form($db);
	print '<tr class="oddeven"><td>'.$langs->trans('TiBilletClientDePassageChoisi').'</td><td>';
	print $formulaire->select_company(getDolGlobalInt('TIBILLET_TIERS_DE_PASSAGE'), 'tiers_de_passage', '(s.client:IN:1,3)', 0, 0, 0, array(), 0, 'minwidth300');
	print '</td></tr>';
	print '</table>';
	print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('TiBilletEnregistrer').'"></div>';
	print '</form><br>';
}

// Les clotures importees / Imported closures
print load_fiche_titre($langs->trans('TiBilletClotures'), '', '');
$sql = "SELECT rowid, numero, date_comptable, date_import, pieces, mode FROM ".$db->prefix()."tibillet_cloture_importee";
$sql .= " WHERE entity = ".((int) $conf->entity)." ORDER BY numero DESC LIMIT 50";
$resql = $db->query($sql);
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('TiBilletNumero').'</td><td>'.$langs->trans('TiBilletDateComptable').'</td>';
print '<td>'.$langs->trans('TiBilletDateImport').'</td><td>'.$langs->trans('TiBilletMode').'</td><td>'.$langs->trans('TiBilletPieces').'</td><td></td></tr>';
$nombre_de_clotures = 0;
while ($resql && ($cloture = $db->fetch_object($resql))) {
	$nombre_de_clotures++;
	print '<tr class="oddeven">';
	print '<td>'.((int) $cloture->numero).'</td>';
	print '<td>'.dol_print_date($db->jdate($cloture->date_comptable), 'day').'</td>';
	print '<td>'.dol_print_date($db->jdate($cloture->date_import), 'dayhour').'</td>';
	$cloture_en_mode_ventes = ($cloture->mode === ImportTiBillet::MODE_VENTES);
	print '<td>'.$langs->trans($cloture_en_mode_ventes ? 'TiBilletModeVentesCourt' : 'TiBilletModeEcrituresCourt').'</td>';
	print '<td>'.dol_escape_htmltag($cloture->pieces).'</td>';
	print '<td class="right">';
	// Une cloture importee en factures ne se reimporte pas en ecritures.
	// / A closure imported as invoices is never re-imported as entries.
	if (!$cloture_en_mode_ventes) {
		print '<form method="POST" action="'.$adresse_de_la_page.'" class="inline-block">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="reimporter">';
		print '<input type="hidden" name="rowid" value="'.((int) $cloture->rowid).'">';
		print '<input type="submit" class="button smallpaddingimp" title="'.dol_escape_htmltag($langs->trans('TiBilletReimporterAide')).'" value="'.$langs->trans('TiBilletReimporter').'">';
		print '</form>';
	}
	print '</td>';
	print '</tr>';
}
if ($nombre_de_clotures === 0) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('TiBilletAucuneCloture').'</span></td></tr>';
}
print '</table>';

llxFooter();
$db->close();
