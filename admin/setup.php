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
 * La page du module TiBillet : reglages, test de connexion, import a la demande,
 * liste des clotures importees et « Reimporter ». Reservee aux admins.
 * / The TiBillet module page: settings, connection test, import on demand, imported
 * closures and "Re-import". Admins only.
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
print '<div class="opacitymedium">'.$langs->trans('TiBilletExplication').'</div><br>';

// Les reglages / Settings
print '<form method="POST" action="'.$adresse_de_la_page.'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="enregistrer">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('TiBilletAdresse').'<br><span class="opacitymedium small">'.$langs->trans('TiBilletAdresseAide').'</span></td>';
print '<td><input type="text" class="minwidth400" name="tibillet_url" value="'.dol_escape_htmltag(getDolGlobalString('TIBILLET_URL')).'"></td></tr>';

$etat_de_la_cle = getDolGlobalString('TIBILLET_API_KEY') !== '' ? $langs->trans('TiBilletCleDefinie') : $langs->trans('TiBilletCleAbsente');
print '<tr class="oddeven"><td>'.$langs->trans('TiBilletCle').'<br><span class="opacitymedium small">'.$langs->trans('TiBilletCleAide').'</span></td>';
print '<td><input type="password" class="minwidth400" name="tibillet_cle" value="" autocomplete="new-password"> <span class="opacitymedium">'.$etat_de_la_cle.'</span></td></tr>';

$coche = getDolGlobalInt('TIBILLET_RESEAU_LOCAL') ? ' checked' : '';
print '<tr class="oddeven"><td>'.$langs->trans('TiBilletReseauLocal').'<br><span class="opacitymedium small">'.$langs->trans('TiBilletReseauLocalAide').'</span></td>';
print '<td><input type="checkbox" name="tibillet_reseau_local" value="1"'.$coche.'></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('TiBilletEnregistrer').'"></div>';
print '</form><br>';

// Tester, importer / Test, import
print '<div class="center">';
foreach (array('tester' => 'TiBilletTester', 'importer' => 'TiBilletImporterMaintenant') as $action_du_bouton => $libelle_du_bouton) {
	print '<form method="POST" action="'.$adresse_de_la_page.'" class="inline-block">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="'.$action_du_bouton.'">';
	print '<input type="submit" class="button" value="'.$langs->trans($libelle_du_bouton).'">';
	print '</form> ';
}
print '</div><br>';

// Les clotures importees / Imported closures
print load_fiche_titre($langs->trans('TiBilletClotures'), '', '');
$sql = "SELECT rowid, numero, date_comptable, date_import, pieces FROM ".$db->prefix()."tibillet_cloture_importee";
$sql .= " WHERE entity = ".((int) $conf->entity)." ORDER BY numero DESC LIMIT 50";
$resql = $db->query($sql);
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('TiBilletNumero').'</td><td>'.$langs->trans('TiBilletDateComptable').'</td>';
print '<td>'.$langs->trans('TiBilletDateImport').'</td><td>'.$langs->trans('TiBilletPieces').'</td><td></td></tr>';
$nombre_de_clotures = 0;
while ($resql && ($cloture = $db->fetch_object($resql))) {
	$nombre_de_clotures++;
	print '<tr class="oddeven">';
	print '<td>'.((int) $cloture->numero).'</td>';
	print '<td>'.dol_print_date($db->jdate($cloture->date_comptable), 'day').'</td>';
	print '<td>'.dol_print_date($db->jdate($cloture->date_import), 'dayhour').'</td>';
	print '<td>'.dol_escape_htmltag($cloture->pieces).'</td>';
	print '<td class="right"><form method="POST" action="'.$adresse_de_la_page.'" class="inline-block">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="reimporter">';
	print '<input type="hidden" name="rowid" value="'.((int) $cloture->rowid).'">';
	print '<input type="submit" class="button smallpaddingimp" title="'.dol_escape_htmltag($langs->trans('TiBilletReimporterAide')).'" value="'.$langs->trans('TiBilletReimporter').'">';
	print '</form></td>';
	print '</tr>';
}
if ($nombre_de_clotures === 0) {
	print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('TiBilletAucuneCloture').'</span></td></tr>';
}
print '</table>';

llxFooter();
$db->close();
