<?php
/* Copyright (C) 2026 Cooperative TiBillet — GPL v3 ou plus (voir COPYING) */

/**
 * Essai automatique du module TiBillet, contre une API TiBillet de dev.
 * / Automatic test of the TiBillet module, against a dev TiBillet API.
 *
 * A LANCER DANS LE CONTENEUR DOLIBARR, sur une instance de TEST (il ecrit et efface
 * dans le grand livre) :
 *     php /var/www/html/custom/tibillet/scripts/essai.php
 * Prerequis : module active, reglages remplis (adresse, cle, mode dev), un exercice
 * ouvert, et chez TiBillet au moins une cloture J avec deux journaux (ex. BAR + WEB).
 *
 * CE QUI EST VERIFIE :
 * 1. Import : un passage importe les clotures de TiBillet (ou il n'y a plus rien a
 *    importer), sans erreur.
 * 2. Coherence : pour chaque cloture importee, le grand livre est EGAL, compte par
 *    compte et au centime, aux ecritures que TiBillet donne pour cette cloture.
 * 3. Non validees : aucune ligne TiBillet du grand livre n'a de date de validation.
 * 4. Pas de doublon : un second passage n'ecrit rien.
 * 5. Reimporter : la derniere cloture garde les memes montants, sur de nouvelles pieces.
 * 6. Tout ou rien : si la 2e ecriture d'une cloture echoue, la 1re n'est pas gardee.
 * Sortie : une ligne OK / ECHEC par verification ; code de sortie 0 si tout est OK.
 * / Exit code 0 when every check is OK.
 */

define('NOSESSION', 1);
require '/var/www/html/master.inc.php';
dol_include_once('/tibillet/class/importtibillet.class.php');

global $user, $langs;
$user = new User($db);
$user->fetch(0, 'admin');
$user->loadRights();
$langs->setDefaultLang('fr_FR');

$nombre_d_echecs = 0;

/**
 * Affiche le resultat d'une verification. / Prints a check result.
 */
function verifier($condition, $phrase)
{
	global $nombre_d_echecs;
	echo ($condition ? 'OK     ' : 'ECHEC  ').$phrase."\n";
	if (!$condition) {
		$nombre_d_echecs++;
	}
}

/**
 * Appelle une methode privee du module (pour l'essai « tout ou rien »).
 * / Calls a private method of the module.
 */
function appelerMethodePrivee($objet, $nom, $arguments)
{
	$methode = new ReflectionMethod($objet, $nom);
	$methode->setAccessible(true);
	return $methode->invokeArgs($objet, $arguments);
}

/**
 * Les soldes par compte (en centimes) des lignes TiBillet d'une liste de pieces.
 * / Balances per account (cents) of the TiBillet lines of some pieces.
 */
function soldesDuGrandLivre($db, $pieces)
{
	$soldes = array();
	if ($pieces === '') {
		return $soldes;
	}
	$sql = "SELECT numero_compte, SUM(ROUND(debit * 100)) as debit, SUM(ROUND(credit * 100)) as credit";
	$sql .= " FROM ".$db->prefix()."accounting_bookkeeping WHERE doc_type = 'tibillet'";
	$sql .= " AND piece_num IN (".$db->sanitize($pieces).") GROUP BY numero_compte";
	$resql = $db->query($sql);
	while ($ligne = $db->fetch_object($resql)) {
		$soldes[$ligne->numero_compte] = array((int) $ligne->debit, (int) $ligne->credit);
	}
	ksort($soldes);
	return $soldes;
}

/**
 * Les soldes par compte (en centimes, forme Dolibarr) des ecritures TiBillet.
 * / Balances per account of TiBillet's entries.
 */
function soldesDeTiBillet($import, $uuid)
{
	$reponse = appelerMethodePrivee($import, 'appelerTiBillet', array('/api/v2/closures/'.$uuid.'/entries/'));
	$soldes = array();
	if ($reponse['code'] != 200) {
		return null;
	}
	foreach ($reponse['donnees']['entries'] as $ecriture) {
		foreach ($ecriture['lines'] as $ligne) {
			$compte = appelerMethodePrivee($import, 'numeroDeCompteDolibarr', array($ligne['CompteNum']));
			if (!isset($soldes[$compte])) {
				$soldes[$compte] = array(0, 0);
			}
			$soldes[$compte][0] += (int) $ligne['Debit'];
			$soldes[$compte][1] += (int) $ligne['Credit'];
		}
	}
	ksort($soldes);
	return $soldes;
}

/** Les identifiants des lignes de quelques pieces / Row ids of some pieces' lines */
function identifiantsDesLignes($db, $pieces)
{
	$identifiants = array();
	$resql = $db->query("SELECT rowid FROM ".$db->prefix()."accounting_bookkeeping WHERE doc_type = 'tibillet' AND piece_num IN (".$db->sanitize($pieces).")");
	while ($ligne = $db->fetch_object($resql)) {
		$identifiants[] = (int) $ligne->rowid;
	}
	return $identifiants;
}

/** Le nombre de lignes TiBillet du grand livre / Number of TiBillet ledger lines */
function nombreDeLignes($db, $condition = '')
{
	$resql = $db->query("SELECT COUNT(*) as nb FROM ".$db->prefix()."accounting_bookkeeping WHERE doc_type = 'tibillet'".$condition);
	return (int) $db->fetch_object($resql)->nb;
}

/** Les clotures importees / Imported closures */
function cloturesImportees($db)
{
	$clotures = array();
	$resql = $db->query("SELECT rowid, cloture_uuid, numero, pieces FROM ".$db->prefix()."tibillet_cloture_importee ORDER BY numero");
	while ($ligne = $db->fetch_object($resql)) {
		$clotures[] = $ligne;
	}
	return $clotures;
}

$import = new ImportTiBillet($db);

// 1. Import / Import
$retour = $import->importerLesClotures();
verifier($retour === 0, "1. Un passage d'import sans erreur (".$import->output.($import->error ? ' / '.$import->error : '').")");
$clotures = cloturesImportees($db);
verifier(count($clotures) > 0, '1. Au moins une clôture importée ('.count($clotures).')');

// 2. Coherence / Consistency
foreach ($clotures as $cloture) {
	$attendu = soldesDeTiBillet($import, $cloture->cloture_uuid);
	$obtenu = soldesDuGrandLivre($db, $cloture->pieces);
	verifier($attendu !== null && $attendu === $obtenu, '2. Clôture n° '.$cloture->numero.' : grand livre = TiBillet, compte par compte ('.count($obtenu).' comptes)');
}

// 3. Non validees / Not validated
verifier(nombreDeLignes($db, ' AND date_validated IS NOT NULL') === 0, '3. Aucune ligne TiBillet validée ('.nombreDeLignes($db).' lignes)');

// 4. Pas de doublon / No duplicate
$lignes_avant = nombreDeLignes($db);
$retour = $import->importerLesClotures();
verifier($retour === 0 && nombreDeLignes($db) === $lignes_avant, '4. Second passage : rien de réécrit ('.$lignes_avant.' lignes)');

// 5. Reimporter la derniere cloture / Re-import the last closure
$derniere = end($clotures);
$soldes_avant = soldesDuGrandLivre($db, $derniere->pieces);
$lignes_avant_reimport = identifiantsDesLignes($db, $derniere->pieces);
$retour = $import->reimporterUneCloture((int) $derniere->rowid);
$derniere_apres = null;
foreach (cloturesImportees($db) as $cloture) {
	if ($cloture->rowid == $derniere->rowid) {
		$derniere_apres = $cloture;
	}
}
verifier($retour === 1 && $derniere_apres !== null, '5. Réimport de la clôture n° '.$derniere->numero.($import->error ? ' ('.$import->error.')' : ''));
if ($derniere_apres !== null) {
	// Les numeros de piece peuvent revenir les memes (Dolibarr reprend le plus grand
	// numero + 1, et les pieces retirees etaient les dernieres) : on verifie que les
	// LIGNES ont ete reecrites. / Lines must be rewritten (piece numbers may repeat).
	$lignes_apres_reimport = identifiantsDesLignes($db, $derniere_apres->pieces);
	verifier(count(array_intersect($lignes_avant_reimport, $lignes_apres_reimport)) === 0 && count($lignes_apres_reimport) === count($lignes_avant_reimport), '5. Lignes réécrites ('.count($lignes_apres_reimport).' lignes, pièces '.$derniere_apres->pieces.')');
	verifier(soldesDuGrandLivre($db, $derniere_apres->pieces) === $soldes_avant, '5. Mêmes montants, compte par compte');
	verifier(nombreDeLignes($db) === $lignes_avant, '5. Même nombre de lignes au total');
	$derniere = $derniere_apres;
}

// 6. Tout ou rien : une cloture a au moins deux ecritures. On retire sa ligne de la
// table et les lignes de sa PREMIERE ecriture seulement ; la seconde est encore la :
// le module ecrit la premiere, bute sur la seconde (deja presente), et doit tout
// annuler. Puis on remet la cloture en etat.
// / All or nothing: the 2nd entry is already there, the 1st must not be kept.
$cloture_a_deux_ecritures = null;
foreach (cloturesImportees($db) as $cloture) {
	if (count(explode(',', $cloture->pieces)) >= 2) {
		$cloture_a_deux_ecritures = $cloture;
	}
}
if ($cloture_a_deux_ecritures === null) {
	verifier(false, '6. Tout ou rien : aucune clôture importée avec deux écritures (faire une vente en caisse ET une en ligne le même jour)');
} else {
	$pieces = explode(',', $cloture_a_deux_ecritures->pieces);
	$premiere_piece = (int) $pieces[0];
	$resql = $db->query("SELECT doc_ref FROM ".$db->prefix()."accounting_bookkeeping WHERE piece_num = ".$premiere_piece." LIMIT 1");
	$reference_de_la_premiere = $db->fetch_object($resql)->doc_ref;

	$db->query("DELETE FROM ".$db->prefix()."accounting_bookkeeping WHERE piece_num = ".$premiere_piece);
	$db->query("DELETE FROM ".$db->prefix()."tibillet_cloture_importee WHERE rowid = ".((int) $cloture_a_deux_ecritures->rowid));

	$resultat = appelerMethodePrivee($import, 'importerUneCloture', array($cloture_a_deux_ecritures->cloture_uuid, $user, null));
	$lignes_de_la_premiere = nombreDeLignes($db, " AND doc_ref = '".$db->escape($reference_de_la_premiere)."'");
	verifier($resultat < 0, '6. Tout ou rien : la clôture n° '.$cloture_a_deux_ecritures->numero.' est refusée ('.$import->error.')');
	verifier($lignes_de_la_premiere === 0, '6. Tout ou rien : la première écriture ('.$reference_de_la_premiere.') n\'est pas gardée');

	// Remettre en etat : retirer la seconde ecriture, puis reimporter toute la cloture.
	// / Restore: remove the 2nd entry, then import the whole closure again.
	foreach (array_slice($pieces, 1) as $piece) {
		$db->query("DELETE FROM ".$db->prefix()."accounting_bookkeeping WHERE piece_num = ".((int) $piece));
	}
	$resultat = appelerMethodePrivee($import, 'importerUneCloture', array($cloture_a_deux_ecritures->cloture_uuid, $user, null));
	verifier($resultat >= 0 && nombreDeLignes($db) === $lignes_avant, '6. Remise en état de la clôture n° '.$cloture_a_deux_ecritures->numero);
}

echo "\n".($nombre_d_echecs === 0 ? 'TOUT EST OK' : $nombre_d_echecs.' ECHEC(S)')."\n";
exit($nombre_d_echecs === 0 ? 0 : 1);
