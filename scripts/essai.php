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
 * 7. Mode « articles et ventes » : ni la tache de nuit ni « Reimporter » n'ecrivent
 *    dans le grand livre (un seul mode a la fois : pas de double compta).
 * 8. Catalogue (mode « articles et ventes ») : un produit par tarif en euros d'une
 *    vente, prix TTC, TVA, compte, en vente et categories = TiBillet, une seule ligne
 *    d'historique des prix ; 8 bis : une categorie posee a la main reste.
 * 9. Second passage : 304, rien d'ecrit.
 * 10. Prix change a la main : le prix de TiBillet revient, une ligne d'historique de plus.
 * 11. Ecartes (recharges, jetons, sans compte…) : aucun produit.
 * 12. Mode « ecritures » : la synchro ne fait rien.
 * 13. Un produit sans ref_ext TiBillet n'est jamais touche.
 * 14. L'heure de la tache de nuit : recalage, heure invalide refusee.
 * 15. Collision de ref « TIB-… » : erreur notee, produit du lieu intact, ETag non garde.
 * 22 a 34. Les ventes (06-D), avec une reponse de TiBillet IMITEE pour les clotures et
 *    les ventes : reglages, banques, codes de paiement, tiers par e-mail, factures
 *    payees et liees au compte, virements, avoirs, paiement divers, centime faux
 *    refuse, second passage, limite par passage.
 * 16 a 21. Avec une reponse de TiBillet IMITEE (produits « Essai imite », retires a la
 *    fin) : 16. un produit deja cree qui n'est plus synchronisable passe hors vente ;
 *    17. une reponse illisible est une erreur, sans ETag ; 18. un nom piege n'arrive
 *    pas en HTML dans le resume ; 19. un 304 garde le resume du dernier passage complet ;
 *    20. des espaces en trop ne font pas reecrire le produit chaque nuit ; 21. l'heure
 *    de la tache est celle du fuseau de la personne.
 * Prerequis du catalogue : la cle a aussi le droit « Produits » ; les modules Produits,
 * Services et Categories sont actifs. LES PRODUITS TIBILLET SONT RETIRES puis recrees.
 * Sortie : une ligne OK / ECHEC par verification ; code de sortie 0 si tout est OK.
 * / Exit code 0 when every check is OK.
 */

define('NOSESSION', 1);
require '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/tibillet/class/importtibillet.class.php');
dol_include_once('/tibillet/class/catalogue.class.php');
dol_include_once('/tibillet/class/reglages.class.php');
dol_include_once('/tibillet/class/ventes.class.php');
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

global $user, $langs, $conf;
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
	$resql = $db->query("SELECT rowid, cloture_uuid, numero, pieces FROM ".$db->prefix()."tibillet_cloture_importee WHERE mode = 'ecritures' ORDER BY numero");
	while ($ligne = $db->fetch_object($resql)) {
		$clotures[] = $ligne;
	}
	return $clotures;
}

// Les essais 1 a 6 tournent en mode « ecritures », quel que soit le mode laisse par un
// essai precedent interrompu ; le mode d'origine est remis a la fin.
// / Checks 1-6 run in entries mode; the original mode is restored at the end.
$mode_d_origine = getDolGlobalString('TIBILLET_MODE');
dolibarr_set_const($db, 'TIBILLET_MODE', ImportTiBillet::MODE_ECRITURES, 'chaine', 0, '', $conf->entity);

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

// 7. Mode « articles et ventes » : le module n'ecrit plus rien dans le grand livre,
// ni par la tache de nuit, ni par « Reimporter ». Puis on remet le mode d'avant.
// / "Items and sales" mode: nothing is written in the ledger any more.
dolibarr_set_const($db, 'TIBILLET_MODE', ImportTiBillet::MODE_VENTES, 'chaine', 0, '', $conf->entity);
$lignes_avant_mode_ventes = nombreDeLignes($db);
$import = new ImportTiBillet($db);
$retour = $import->importerLesClotures();
verifier($retour === 0 && $import->output === $langs->transnoentities('TiBilletModeVentesRienAImporter'), '7. Mode ventes : la tâche de nuit n\'importe rien ('.$import->output.')');
$clotures = cloturesImportees($db);
$derniere = end($clotures);
$retour = $import->reimporterUneCloture((int) $derniere->rowid);
verifier($retour === -1 && $import->error === $langs->transnoentities('TiBilletModeVentesPasDeReimport'), '7. Mode ventes : « Réimporter » est refusé ('.$import->error.')');
verifier(nombreDeLignes($db) === $lignes_avant_mode_ventes, '7. Mode ventes : grand livre inchangé ('.nombreDeLignes($db).' lignes)');

// ------------------------------------------------------------------
// 8 à 14. Le catalogue (mode « articles et ventes »), fiche 06-C
// / The catalogue ("items and sales" mode)
// ------------------------------------------------------------------

/** La valeur d'une propriete schema.org, ou null / A schema.org property value */
function propriete($element, $nom)
{
	foreach (($element['additionalProperty'] ?? array()) as $propriete) {
		if ($propriete['name'] === $nom) {
			return $propriete['value'];
		}
	}
	return null;
}

/** Le rowid du produit Dolibarr d'un tarif TiBillet, ou 0 / Product rowid of a price */
function produitDuTarif($db, $uuid_du_tarif)
{
	$resql = $db->query("SELECT rowid FROM ".$db->prefix()."product WHERE ref_ext = '".$db->escape($uuid_du_tarif)."'");
	$ligne = $db->fetch_object($resql);
	return $ligne ? (int) $ligne->rowid : 0;
}

/** Nombre de lignes d'une table, avec une condition / Row count of a table */
function compter($db, $table, $condition = '1 = 1')
{
	$resql = $db->query("SELECT COUNT(*) as nb FROM ".$db->prefix().$table." WHERE ".$condition);
	return (int) $db->fetch_object($resql)->nb;
}

/** Les rowid des categories d'un produit / Category ids of a product */
function categoriesDuProduit($db, $id_du_produit)
{
	$categorie = new Categorie($db);
	$categories = $categorie->containing($id_du_produit, Categorie::TYPE_PRODUCT, 'id');
	return is_array($categories) ? $categories : array();
}

/** Le rowid de la categorie de produits de ce ref_ext, ou 0 / Category id by ref_ext */
function categorieParRefExt($db, $ref_ext)
{
	$resql = $db->query("SELECT rowid FROM ".$db->prefix()."categorie WHERE type = 0 AND ref_ext = '".$db->escape($ref_ext)."'");
	$ligne = $db->fetch_object($resql);
	return $ligne ? (int) $ligne->rowid : 0;
}

/**
 * Le catalogue, avec une reponse de TiBillet imitee (pour les cas qu'on ne sait pas
 * produire avec le vrai Lespass : reponse illisible, nom piege, 304 voulu…).
 * / The catalogue with a faked TiBillet answer.
 */
class CatalogueImite extends CatalogueTiBillet
{
	/** @var array La reponse rendue a la place de TiBillet / The faked answer */
	public $reponse_imitee = array();

	protected function appelerTiBillet($chemin, $en_tetes_en_plus = array())
	{
		// Seul le catalogue est imite ; les comptes viennent du vrai TiBillet.
		// / Only the catalogue is faked; accounts come from the real TiBillet.
		if (strpos($chemin, '/api/v2/products/') === 0) {
			return $this->reponse_imitee;
		}
		return parent::appelerTiBillet($chemin, $en_tetes_en_plus);
	}
}

/** Fin commune des identifiants imites / Common end of faked identifiers */
define('FIN_IMITEE', '-0000-4000-8000-00000000e55a');

/**
 * Un identifiant imite. Le numero est AU DEBUT : la ref Dolibarr d'un tarif est
 * « TIB- » + les 8 premiers caracteres.
 * / A faked identifier; the number comes first (ref = "TIB-" + first 8 chars).
 */
function identifiantImite($numero)
{
	return sprintf('e55a%04d', $numero).FIN_IMITEE;
}

/**
 * Un produit TiBillet imite, avec ses proprietes comptables.
 * / A faked TiBillet product.
 */
function produitImite($nom, $numeros_de_tarifs, $proprietes_en_plus = array(), $nom_du_tarif = 'Pinte')
{
	$proprietes = array(
		'productType' => array('code' => 'N', 'label' => 'Article de caisse'),
		'vatRate' => '20.00',
		'accountingAccount' => array('number' => '707000', 'label' => 'Ventes'),
		'forAccountingSync' => true,
		'archived' => false,
	);
	foreach ($proprietes_en_plus as $nom_de_propriete => $valeur) {
		if ($valeur === null) {
			unset($proprietes[$nom_de_propriete]);
		} else {
			$proprietes[$nom_de_propriete] = $valeur;
		}
	}
	$additional_property = array();
	foreach ($proprietes as $nom_de_propriete => $valeur) {
		$additional_property[] = array('@type' => 'PropertyValue', 'name' => $nom_de_propriete, 'value' => $valeur);
	}
	$offres = array();
	foreach ($numeros_de_tarifs as $numero) {
		$offres[] = array('identifier' => identifiantImite($numero), 'name' => $nom_du_tarif, 'price' => '5.00', 'priceCurrency' => 'EUR');
	}
	return array('identifier' => identifiantImite(0), 'name' => $nom, 'offers' => $offres, 'additionalProperty' => $additional_property);
}

/** Un passage du catalogue imite / A run of the faked catalogue */
function passageImite($db, $reponse)
{
	$catalogue = new CatalogueImite($db);
	$catalogue->reponse_imitee = $reponse;
	$retour = $catalogue->synchroniserLeCatalogue();
	return array($retour, $catalogue);
}

/** Une reponse 200 avec ces produits / A 200 answer with these products */
function reponseImitee($produits, $etag = '"essai-imite"')
{
	return array('code' => 200, 'donnees' => array('results' => $produits), 'etag' => $etag);
}

/** Les compteurs du dernier passage / The last run's counters */
function compteursDu($catalogue)
{
	$propriete = new ReflectionProperty('CatalogueTiBillet', 'compteurs');
	$propriete->setAccessible(true);
	return $propriete->getValue($catalogue);
}

/** Un passage de la synchro, l'ETag oublie (tout est relu) / A sync run, ETag forgotten */
function synchroniserSansEtag($db)
{
	global $conf;
	dolibarr_del_const($db, 'TIBILLET_CATALOGUE_ETAG', $conf->entity);
	$catalogue = new CatalogueTiBillet($db);
	$retour = $catalogue->synchroniserLeCatalogue();
	return array($retour, $catalogue);
}

dolibarr_set_const($db, 'TIBILLET_MODE', ImportTiBillet::MODE_VENTES, 'chaine', 0, '', $conf->entity);
$catalogue = new CatalogueTiBillet($db);
$reponse_du_catalogue = appelerMethodePrivee($catalogue, 'appelerTiBillet', array('/api/v2/products/?accounting=true'));
verifier($reponse_du_catalogue['code'] == 200, '8. TiBillet rend le catalogue (code '.$reponse_du_catalogue['code'].' : la clé doit avoir le droit « Produits »)');

// Ce que Dolibarr doit avoir : un produit par tarif en euros d'un produit qui est une
// vente, avec compte et TVA. Le reste ne doit pas venir.
// / What Dolibarr must hold, and what must not come.
$tarifs_attendus = array();
$tarifs_ecartes = array();
foreach ($reponse_du_catalogue['donnees']['results'] ?? array() as $produit_tibillet) {
	$synchronisable = propriete($produit_tibillet, 'forAccountingSync') && propriete($produit_tibillet, 'accountingAccount') !== null && propriete($produit_tibillet, 'vatRate') !== null;
	foreach ($produit_tibillet['offers'] ?? array() as $tarif) {
		if ($synchronisable && $tarif['priceCurrency'] === 'EUR') {
			$tarifs_attendus[$tarif['identifier']] = array('produit' => $produit_tibillet, 'tarif' => $tarif);
		} else {
			$tarifs_ecartes[] = $tarif['identifier'];
		}
	}
}
verifier(count($tarifs_attendus) > 0, '8. Le catalogue a au moins un tarif à recopier ('.count($tarifs_attendus).' attendus, '.count($tarifs_ecartes).' écartés)');

// Depart propre : les produits TiBillet deja la sont retires (instance de TEST).
// / Clean start: TiBillet products already there are removed (TEST instance).
foreach (array_merge(array_keys($tarifs_attendus), $tarifs_ecartes) as $uuid_du_tarif) {
	$id_existant = produitDuTarif($db, $uuid_du_tarif);
	if ($id_existant > 0) {
		$produit_a_retirer = new Product($db);
		$produit_a_retirer->fetch($id_existant);
		$produit_a_retirer->delete($user);
	}
}

// 8. Un passage / One run
list($retour, $catalogue) = synchroniserSansEtag($db);
$premiere_ligne_du_resume = strtok($catalogue->output, "\n");
verifier($retour === 0, '8. Un passage de la synchro sans erreur ('.$premiere_ligne_du_resume.($catalogue->error ? ' / '.$catalogue->error : '').')');
$mere_caisse = categorieParRefExt($db, CatalogueTiBillet::REF_EXT_MERE_CAISSE);
$mere_types = categorieParRefExt($db, CatalogueTiBillet::REF_EXT_MERE_TYPES);
verifier($mere_caisse > 0 && $mere_types > 0, '8. Les deux catégories mères existent');
$nombre_de_produits_justes = 0;
foreach ($tarifs_attendus as $uuid_du_tarif => $attendu) {
	$id_du_produit = produitDuTarif($db, $uuid_du_tarif);
	$produit = new Product($db);
	if ($id_du_produit <= 0 || $produit->fetch($id_du_produit) <= 0) {
		verifier(false, '8. Produit absent pour le tarif '.$uuid_du_tarif);
		continue;
	}
	$compte_attendu = appelerMethodePrivee($catalogue, 'numeroDeCompteDolibarr', array(propriete($attendu['produit'], 'accountingAccount')['number']));
	$en_vente_attendu = (propriete($attendu['produit'], 'archived') || propriete($attendu['tarif'], 'archived')) ? 0 : 1;
	$categories = categoriesDuProduit($db, $id_du_produit);
	$type = propriete($attendu['produit'], 'productType');
	$categorie_du_type = categorieParRefExt($db, CatalogueTiBillet::PREFIXE_REF_EXT_TYPE.$type['code']);
	$categorie_de_caisse = propriete($attendu['produit'], 'posCategory');
	$caisse_juste = ($categorie_de_caisse === null) || in_array(categorieParRefExt($db, $categorie_de_caisse['identifier']), $categories);
	$juste = round((float) $produit->price_ttc, 2) === round((float) $attendu['tarif']['price'], 2)
		&& abs((float) $produit->tva_tx - (float) propriete($attendu['produit'], 'vatRate')) < 0.001
		&& (string) $produit->accountancy_code_sell === (string) $compte_attendu
		&& (int) $produit->status === $en_vente_attendu
		&& in_array($categorie_du_type, $categories)
		&& $caisse_juste
		&& compter($db, 'product_price', 'fk_product = '.$id_du_produit) === 1;
	if ($juste) {
		$nombre_de_produits_justes++;
	} else {
		verifier(false, '8. Produit '.$produit->ref.' ('.$produit->label.') : prix, TVA, compte, en vente, catégories ou historique faux');
	}
}
verifier($nombre_de_produits_justes === count($tarifs_attendus), '8. Prix TTC, TVA, compte, en vente, catégories = TiBillet, une ligne d\'historique ('.$nombre_de_produits_justes.' / '.count($tarifs_attendus).')');

// 11. Ecartes / Skipped
$ecartes_presents = 0;
foreach ($tarifs_ecartes as $uuid_du_tarif) {
	if (produitDuTarif($db, $uuid_du_tarif) > 0) {
		$ecartes_presents++;
	}
}
verifier($ecartes_presents === 0, '11. Aucun produit pour un tarif écarté (recharge, jetons, sans compte…) ('.count($tarifs_ecartes).' écartés)');

// 9. Second passage : 304, rien d'ecrit / Second run: 304, nothing written
$produits_avant = compter($db, 'product');
$historique_avant = compter($db, 'product_price');
// Par la methode de la tache de nuit : en mode ventes, elle lance la synchro.
// / Through the nightly job's method: in items and sales mode, it runs the sync.
$catalogue = new ImportTiBillet($db);
$retour = $catalogue->passageDeLaNuit();
// La tache enchaine ensuite les ventes : seul le debut de son resume (le catalogue)
// est lu ici. / The job then runs the sales: only the catalogue part is read here.
verifier(strpos($catalogue->output, $langs->transnoentities('TiBilletCatalogueInchange')) === 0 && compter($db, 'product') === $produits_avant && compter($db, 'product_price') === $historique_avant, '9. Second passage : catalogue inchangé (304), rien d\'écrit ('.strtok($catalogue->output, "\n").')');

// 10. Un prix change a la main, ETag oublie : le prix de TiBillet revient, une ligne
// d'historique de plus pour CE produit, aucune pour les autres.
// / A price changed by hand: TiBillet's price comes back, one more history line.
// Un tarif a prix libre s'il y en a un : son minimum doit survivre a la mise a jour.
// / A free price if any: its minimum must survive the update.
$premier_tarif = array_key_first($tarifs_attendus);
foreach ($tarifs_attendus as $uuid_du_tarif => $attendu) {
	if (!empty($attendu['tarif']['freePrice'])) {
		$premier_tarif = $uuid_du_tarif;
		break;
	}
}
$id_du_premier = produitDuTarif($db, $premier_tarif);
$db->query("UPDATE ".$db->prefix()."product SET price_ttc = 999, price = 999 WHERE rowid = ".$id_du_premier);
list($retour, $catalogue) = synchroniserSansEtag($db);
$produit = new Product($db);
$produit->fetch($id_du_premier);
$minimum_attendu = !empty($tarifs_attendus[$premier_tarif]['tarif']['freePrice']) ? round((float) $tarifs_attendus[$premier_tarif]['tarif']['price'], 2) : 0.0;
verifier($retour === 0 && round((float) $produit->price_ttc, 2) === round((float) $tarifs_attendus[$premier_tarif]['tarif']['price'], 2), '10. Le prix de TiBillet revient ('.$produit->price_ttc.')');
verifier(round((float) $produit->price_min_ttc, 2) === $minimum_attendu, '10. Le prix minimum (prix libre) est gardé ('.$produit->price_min_ttc.', attendu '.$minimum_attendu.')');
verifier(compter($db, 'product_price', 'fk_product = '.$id_du_premier) === 2 && compter($db, 'product_price') === $historique_avant + 1, '10. Une ligne d\'historique de plus, pour ce produit seulement');

// 8 bis. Une categorie posee a la main reste / A hand-set category stays
$categorie_manuelle = new Categorie($db);
$categorie_manuelle->label = 'Essai manuel '.dol_now();
$categorie_manuelle->type = Categorie::TYPE_PRODUCT;
$categorie_manuelle->create($user);
$categorie_manuelle->add_type($produit, Categorie::TYPE_PRODUCT);
list($retour, $catalogue) = synchroniserSansEtag($db);
verifier(in_array($categorie_manuelle->id, categoriesDuProduit($db, $id_du_premier)), '8 bis. La catégorie posée à la main reste après un passage');
$categorie_manuelle->delete($user);

// 13. Un produit sans ref_ext TiBillet n'est jamais touche / A product without ref_ext
$produit_du_lieu = new Product($db);
$produit_du_lieu->ref = 'ESSAI-LIEU-'.dol_now();
$produit_du_lieu->label = 'Produit du lieu';
$produit_du_lieu->price_base_type = 'TTC';
$produit_du_lieu->price_ttc = 1;
$produit_du_lieu->status = 1;
$produit_du_lieu->create($user);
$historique_du_lieu = compter($db, 'product_price', 'fk_product = '.$produit_du_lieu->id);
list($retour, $catalogue) = synchroniserSansEtag($db);
$produit_relu = new Product($db);
$produit_relu->fetch($produit_du_lieu->id);
verifier($produit_relu->label === 'Produit du lieu' && compter($db, 'product_price', 'fk_product = '.$produit_du_lieu->id) === $historique_du_lieu, '13. Un produit sans ref_ext TiBillet n\'est pas touché');
$produit_relu->delete($user);

// 15. Collision de ref : un produit du lieu porte deja la ref « TIB-… » d'un tarif. Le
// passage note une erreur, ne touche pas au produit du lieu, et n'enregistre pas
// l'ETag (la nuit suivante refait tout).
// / Ref collision: an error, the venue's product untouched, no ETag saved.
$tarif_en_collision = array_key_last($tarifs_attendus);
$produit_a_retirer = new Product($db);
$produit_a_retirer->fetch(produitDuTarif($db, $tarif_en_collision));
$produit_a_retirer->delete($user);
$produit_en_collision = new Product($db);
$produit_en_collision->ref = 'TIB-'.substr($tarif_en_collision, 0, 8);
$produit_en_collision->label = 'Produit du lieu en collision';
$produit_en_collision->price_base_type = 'TTC';
$produit_en_collision->price_ttc = 1;
$produit_en_collision->create($user);
list($retour, $catalogue) = synchroniserSansEtag($db);
$produit_relu = new Product($db);
$produit_relu->fetch($produit_en_collision->id);
verifier($retour === -1 && getDolGlobalString('TIBILLET_CATALOGUE_ETAG') === '', '15. Collision de ref : erreur notée, ETag non enregistré ('.$catalogue->error.')');
verifier($produit_relu->label === 'Produit du lieu en collision' && (string) $produit_relu->ref_ext === '', '15. Collision de ref : le produit du lieu n\'est pas touché');
$produit_relu->delete($user);
list($retour, $catalogue) = synchroniserSansEtag($db);
verifier($retour === 0 && produitDuTarif($db, $tarif_en_collision) > 0 && getDolGlobalString('TIBILLET_CATALOGUE_ETAG') !== '', '15. Collision levée : le tarif est créé, l\'ETag enregistré');

// 16 a 21 : reponse de TiBillet imitee / Faked TiBillet answer
$tarif_imite_1 = identifiantImite(1);
$tarif_imite_2 = identifiantImite(2);

// 16. Un produit deja dans Dolibarr qui n'est plus synchronisable (compte retire, n'est
// plus une vente, tarif plus en euros) passe HORS VENTE, et le resume le dit.
// / An existing product that can no longer be synced goes "not for sale".
list($retour, $catalogue) = passageImite($db, reponseImitee(array(produitImite('Essai imite', array(1, 2)))));
$produit_imite = new Product($db);
$produit_imite->fetch(produitDuTarif($db, $tarif_imite_1));
verifier($retour === 0 && $produit_imite->id > 0 && (int) $produit_imite->status === 1, '16. Produit imité créé, en vente');
list($retour, $catalogue) = passageImite($db, reponseImitee(array(produitImite('Essai imite', array(1, 2), array('accountingAccount' => null, 'accountingAccountError' => 'Il manque un compte.')))));
$produit_imite->fetch(produitDuTarif($db, $tarif_imite_1));
verifier((int) $produit_imite->status === 0 && strpos($catalogue->output, 'Non créé') === false && strpos($catalogue->output, 'hors vente') !== false, '16. Compte retiré : le produit déjà créé passe hors vente, le résumé le dit ('.str_replace("\n", ' | ', $catalogue->output).')');
passageImite($db, reponseImitee(array(produitImite('Essai imite', array(1, 2)))));
list($retour, $catalogue) = passageImite($db, reponseImitee(array(produitImite('Essai imite', array(1, 2), array('forAccountingSync' => false)))));
$produit_imite->fetch(produitDuTarif($db, $tarif_imite_1));
verifier((int) $produit_imite->status === 0, '16. N\'est plus une vente : le produit déjà créé passe hors vente');
passageImite($db, reponseImitee(array(produitImite('Essai imite', array(1, 2)))));
$produit_en_jetons = produitImite('Essai imite', array(1, 2));
$produit_en_jetons['offers'][1]['priceCurrency'] = 'TMP';
list($retour, $catalogue) = passageImite($db, reponseImitee(array($produit_en_jetons)));
$produit_imite->fetch(produitDuTarif($db, $tarif_imite_2));
verifier((int) $produit_imite->status === 0, '16. Tarif passé en jetons : son produit passe hors vente');

// 17. Une reponse illisible est une erreur, et l'ETag n'est pas enregistre.
// / An unreadable answer is an error, no ETag saved.
dolibarr_del_const($db, 'TIBILLET_CATALOGUE_ETAG', $conf->entity);
list($retour, $catalogue) = passageImite($db, array('code' => 200, 'donnees' => array('detail' => 'Autre chose'), 'etag' => '"illisible"'));
verifier($retour === -1 && getDolGlobalString('TIBILLET_CATALOGUE_ETAG') === '', '17. Réponse sans « results » : erreur, ETag non enregistré ('.$catalogue->error.')');
$tarif_sans_identifiant = produitImite('Essai imite', array(1));
unset($tarif_sans_identifiant['offers'][0]['identifier']);
list($retour, $catalogue) = passageImite($db, reponseImitee(array($tarif_sans_identifiant), '"sans-identifiant"'));
verifier($retour === -1 && getDolGlobalString('TIBILLET_CATALOGUE_ETAG') === '' && compter($db, 'product', "ref = 'TIB-'") === 0, '17. Tarif sans identifiant : erreur, aucun produit « TIB- », ETag non enregistré');

// 18. Un nom piege n'arrive pas en HTML dans le resume (affiche sans echappement par
// la fiche de la tache planifiee de Dolibarr).
// / A trapped name never reaches the summary as HTML.
list($retour, $catalogue) = passageImite($db, reponseImitee(array(produitImite('<img src=x onerror=alert(1)>Piege', array(3), array('accountingAccount' => null, 'accountingAccountError' => '<b>sans compte</b>')))));
verifier(strpos($catalogue->output, '<') === false && strpos(getDolGlobalString('TIBILLET_CATALOGUE_RESUME'), '<') === false && strpos($catalogue->output, 'Piege') !== false, '18. Nom piégé : aucune balise dans le résumé ('.str_replace("\n", ' | ', $catalogue->output).')');

// 19. Un 304 garde le resume du dernier passage complet ; seule la date avance.
// / A 304 keeps the last full run's summary.
$resume_complet = getDolGlobalString('TIBILLET_CATALOGUE_RESUME');
list($retour, $catalogue) = passageImite($db, array('code' => 304, 'donnees' => null, 'etag' => ''));
verifier($retour === 0 && getDolGlobalString('TIBILLET_CATALOGUE_RESUME') === $resume_complet && $resume_complet !== '', '19. 304 : le résumé du dernier passage complet est gardé');

// 20. Des espaces en trop chez TiBillet ne font pas reecrire le produit chaque nuit.
// / Extra spaces do not rewrite the product every night.
$avec_espaces = produitImite('Essai imite  ', array(1), array('posCategory' => array('identifier' => identifiantImite(9999), 'name' => 'Essai caisse ', 'order' => 1)), 'Pinte ');
passageImite($db, reponseImitee(array($avec_espaces)));
list($retour, $catalogue) = passageImite($db, reponseImitee(array($avec_espaces)));
$compteurs = compteursDu($catalogue);
verifier($retour === 0 && $compteurs['mis_a_jour'] === 0 && $compteurs['inchanges'] === 1, '20. Espaces en trop : second passage sans mise à jour ('.json_encode($compteurs).')');

// 21. L'heure de la tache est lue dans le fuseau de la personne connectee (celui de la
// page), sinon dans celui du serveur.
// / The job time is read in the logged-in person's time zone.
$_SESSION['dol_tz_string'] = 'Pacific/Auckland';
$moment = ImportTiBillet::prochaineOccurrence('02:30');
unset($_SESSION['dol_tz_string']);
$a_auckland = new DateTime('@'.$moment);
$a_auckland->setTimezone(new DateTimeZone('Pacific/Auckland'));
verifier($a_auckland->format('H:i') === '02:30' && $moment > dol_now() && $moment - dol_now() <= 86400, '21. Fuseau Pacific/Auckland : 02:30 à Auckland ('.$a_auckland->format('Y-m-d H:i').')');
$moment = ImportTiBillet::prochaineOccurrence('02:30');
$au_serveur = new DateTime('@'.$moment);
$au_serveur->setTimezone(new DateTimeZone(date_default_timezone_get()));
verifier($au_serveur->format('H:i') === '02:30', '21. Sans session : 02:30 dans le fuseau du serveur ('.date_default_timezone_get().')');

// Menage des produits et categories imites / Cleanup of faked products and categories
$resql = $db->query("SELECT rowid FROM ".$db->prefix()."product WHERE ref_ext LIKE '%".$db->escape(FIN_IMITEE)."' OR (ref LIKE 'TIB-%' AND label LIKE 'Essai imite%')");
while ($ligne = $db->fetch_object($resql)) {
	$produit_a_retirer = new Product($db);
	$produit_a_retirer->fetch((int) $ligne->rowid);
	$produit_a_retirer->delete($user);
}
$id_categorie_imitee = categorieParRefExt($db, identifiantImite(9999));
if ($id_categorie_imitee > 0) {
	$categorie_a_retirer = new Categorie($db);
	$categorie_a_retirer->fetch($id_categorie_imitee);
	$categorie_a_retirer->delete($user);
}
dolibarr_del_const($db, 'TIBILLET_CATALOGUE_ETAG', $conf->entity);

// ------------------------------------------------------------------
// 22 a 34. Les ventes (mode « articles et ventes »), fiche 06-D, avec une reponse de
// TiBillet IMITEE pour les clotures et les ventes (les comptes viennent du vrai
// TiBillet). Les clotures et ventes imitees sont retirees des tables du module a la
// fin ; les factures validees restent (instance de TEST).
// / Sales, with a faked TiBillet answer for closures and sales.
// ------------------------------------------------------------------

/**
 * Les ventes, avec les clotures et les ventes de TiBillet imitees.
 * / Sales with faked closures and sales.
 */
class VentesImitees extends VentesTiBillet
{
	/** @var array Les clotures rendues par la liste / Faked closure list */
	public $clotures_imitees = array();

	/** @var array uuid de cloture => liste de ventes / Faked sales per closure */
	public $ventes_imitees = array();

	/** @var int Taille de page plafonnee par le serveur imite (0 : pas de plafond) / Server-side page cap */
	public $ventes_par_page_au_plus = 0;

	protected function appelerTiBillet($chemin, $en_tetes_en_plus = array())
	{
		if (strpos($chemin, '/api/v2/closures/?') === 0) {
			return array('code' => 200, 'donnees' => array('results' => $this->clotures_imitees), 'etag' => '');
		}
		if (preg_match('#^/api/v2/closures/([0-9a-f-]+)/sales/\?(.*)$#', $chemin, $morceaux)) {
			parse_str($morceaux[2], $parametres);
			$apres = isset($parametres['after_number']) ? (int) $parametres['after_number'] : 0;
			$limite = isset($parametres['limit']) ? (int) $parametres['limit'] : 500;
			if ($this->ventes_par_page_au_plus > 0) {
				$limite = min($limite, $this->ventes_par_page_au_plus);
			}
			$page = array();
			$reste = 0;
			foreach (($this->ventes_imitees[$morceaux[1]] ?? array()) as $vente) {
				if ($vente['orderNumber'] <= $apres) {
					continue;
				}
				if (count($page) < $limite) {
					$page[] = $vente;
				} else {
					$reste++;
				}
			}
			$prochain = ($reste > 0 && $page) ? end($page)['orderNumber'] : null;
			return array('code' => 200, 'donnees' => array('results' => $page, 'nextAfterNumber' => $prochain), 'etag' => '');
		}
		return parent::appelerTiBillet($chemin, $en_tetes_en_plus);
	}
}

/**
 * Un identifiant de vente ou de cloture imitee, propre a ce passage de l'essai : un
 * second lancement ne retrouve pas les factures du premier.
 * / A faked sale or closure identifier, unique to this essai run.
 */
function identifiantDuPassage($numero)
{
	return sprintf('e55a%04d-%04d', $numero % 10000, $GLOBALS['numero_du_passage']).substr(FIN_IMITEE, 5);
}

/** Une cloture imitee / A faked closure */
function clotureImitee($numero)
{
	return array('uuid' => identifiantDuPassage($numero), 'number' => $numero, 'accountingDate' => '2026-03-10');
}

/** Une ligne de facture imitee (montants en centimes) / A faked invoice line */
function ligneImitee($tarif, $ttc, $ht, $tva, $taux = '20.00', $compte = '707000', $compte_tva = '445711', $quantite = '1.000000', $prix_unitaire = null)
{
	return array(
		'priceIdentifier' => $tarif, 'name' => 'Essai vente — ligne', 'quantity' => $quantite,
		'unitPrice' => ($prix_unitaire === null ? $ttc : $prix_unitaire), 'totalTTC' => $ttc, 'totalHT' => $ht, 'totalVAT' => $tva,
		'vatRate' => $taux, 'accountingAccount' => $compte, 'vatAccountingAccount' => $compte_tva,
	);
}

/** Une vente imitee / A faked sale */
function venteImitee($numero, $lignes, $paiements, $virements = array(), $divers = array(), $client = null, $nature = 'sale', $vente_liee = null)
{
	return array(
		'identifier' => identifiantDuPassage($numero), 'orderNumber' => $numero, 'nature' => $nature,
		'relatedSale' => $vente_liee, 'customer' => $client, 'orderedItem' => $lignes,
		'invoicePayments' => $paiements, 'transfers' => $virements, 'variousPayments' => $divers,
	);
}

/** La facture Dolibarr d'une vente imitee, ou null / The invoice of a faked sale */
function factureDeLaVenteImitee($db, $numero)
{
	$facture = new Facture($db);
	return ($facture->fetch(0, '', identifiantDuPassage($numero)) > 0) ? $facture : null;
}

/** Les lignes de banque d'un libelle, [numero de compte de la banque => montant] / Bank lines by label */
function lignesDeBanque($db, $libelle, $depuis_rowid)
{
	$lignes = array();
	$sql = "SELECT ba.account_number, b.amount FROM ".$db->prefix()."bank as b";
	$sql .= " JOIN ".$db->prefix()."bank_account as ba ON ba.rowid = b.fk_account";
	$sql .= " WHERE b.label = '".$db->escape($libelle)."' AND b.rowid > ".((int) $depuis_rowid)." ORDER BY b.rowid";
	$resql = $db->query($sql);
	while ($ligne = $db->fetch_object($resql)) {
		$lignes[] = array($ligne->account_number, round((float) $ligne->amount, 2));
	}
	return $lignes;
}

/** Le plus grand rowid d'une table / Highest rowid of a table */
function dernierRowid($db, $table)
{
	$resql = $db->query("SELECT MAX(rowid) as m FROM ".$db->prefix().$table);
	return (int) $db->fetch_object($resql)->m;
}

/** Un passage d'import des ventes imitees / A faked sales import run */
function passageDesVentes($db, $clotures, $ventes)
{
	$import = new VentesImitees($db);
	$import->clotures_imitees = $clotures;
	$import->ventes_imitees = $ventes;
	$retour = $import->importerLesVentes();
	return array($retour, $import);
}

dolibarr_set_const($db, 'TIBILLET_MODE', ImportTiBillet::MODE_VENTES, 'chaine', 0, '', $conf->entity);
$tarif_vendu = array_key_first($tarifs_attendus);
// Un essai interrompu a pu laisser des clotures imitees : elles fausseraient les
// curseurs. / An interrupted essai may have left faked closures.
$db->query("DELETE FROM ".$db->prefix()."tibillet_vente_importee WHERE vente_uuid LIKE '%".$db->escape(substr(FIN_IMITEE, 5))."'");
$db->query("DELETE FROM ".$db->prefix()."tibillet_cloture_importee WHERE cloture_uuid LIKE '%".$db->escape(substr(FIN_IMITEE, 5))."'");
$numero_du_passage = dol_now() % 10000;
$numero_de_cloture = 900000 + $numero_du_passage * 10;

// 22. Les reglages : prepares sans manque en dev ; un second passage ne refait rien.
// / Settings: prepared without anything missing; a second run does nothing.
$reglages = new ReglagesTiBillet($db);
$retour = $reglages->preparer($user);
verifier($retour === 0 && empty($reglages->manques), '22. Réglages préparés, rien ne manque ('.implode(' | ', $reglages->manques).')');
verifier(getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER') !== '', '22. Compte client général posé ('.getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER').')');
// Le journal de banque refuse d'ecrire sans compte fournisseur general, meme pour des
// encaissements (accountancy/journal/bankjournal.php).
// / The bank journal refuses to write without a general supplier account.
verifier(getDolGlobalString('ACCOUNTING_ACCOUNT_SUPPLIER') !== '', '22. Compte fournisseur général posé, exigé par le journal de banque ('.getDolGlobalString('ACCOUNTING_ACCOUNT_SUPPLIER').')');
$reglages = new ReglagesTiBillet($db);
$reglages->preparer($user);
verifier(empty($reglages->faits), '22. Second passage : rien de plus à régler ('.implode(' | ', $reglages->faits).')');

// 22 bis. Un compte de TVA pose a la main par le lieu n'est jamais remplace.
// / A VAT account set by hand by the venue is never replaced.
global $mysoc;
$ligne_du_taux = $db->fetch_object($db->query("SELECT rowid, accountancy_code_sell FROM ".$db->prefix()."c_tva WHERE fk_pays = ".((int) $mysoc->country_id)." AND taux = 20 AND recuperableonly = 0 ORDER BY (code IS NULL OR code = '') DESC, active DESC, rowid ASC"));
$db->query("UPDATE ".$db->prefix()."c_tva SET accountancy_code_sell = 'ESSAI' WHERE rowid = ".((int) $ligne_du_taux->rowid));
$reglages_tva = new ReglagesTiBillet($db);
$reglages_tva->poserLeCompteDeTva('20.00', '445711');
$compte_apres = $db->fetch_object($db->query("SELECT accountancy_code_sell FROM ".$db->prefix()."c_tva WHERE rowid = ".((int) $ligne_du_taux->rowid)))->accountancy_code_sell;
$db->query("UPDATE ".$db->prefix()."c_tva SET accountancy_code_sell = '".$db->escape((string) $ligne_du_taux->accountancy_code_sell)."' WHERE rowid = ".((int) $ligne_du_taux->rowid));
verifier($compte_apres === 'ESSAI', '22 bis. Un compte de TVA posé à la main reste ('.$compte_apres.')');

// 22 ter. Un compte comptable pose sur un mode de paiement (Dictionnaires) remplace
// celui de la banque dans le journal de banque : c'est un manque, l'import ne demarre
// pas. / An account on a payment mode replaces the bank's: reported, import blocked.
$compte_de_cb_avant = $db->fetch_object($db->query("SELECT accountancy_code FROM ".$db->prefix()."c_paiement WHERE code = 'CB' AND entity IN (".getEntity('c_paiement').")"))->accountancy_code;
$db->query("UPDATE ".$db->prefix()."c_paiement SET accountancy_code = '5112' WHERE code = 'CB' AND entity IN (".getEntity('c_paiement').")");
$reglages_cb = new ReglagesTiBillet($db);
$retour_cb = $reglages_cb->preparer($user);
$db->query("UPDATE ".$db->prefix()."c_paiement SET accountancy_code = ".($compte_de_cb_avant === null ? "NULL" : "'".$db->escape($compte_de_cb_avant)."'")." WHERE code = 'CB' AND entity IN (".getEntity('c_paiement').")");
verifier($retour_cb === -1 && strpos(implode(' ', $reglages_cb->manques), 'CB') !== false, '22 ter. Compte posé sur le mode CB : signalé, l\'import ne démarre pas ('.implode(' | ', $reglages_cb->manques).')');

// 23. Une banque par compte de tresorerie : la caisse (530) en type caisse, le
// meme rowid au second appel.
// / One bank per treasury account; same rowid on the second call.
$id_caisse = $reglages->banquePourLeCompte('530000', $user);
$banque = new Account($db);
$banque->fetch($id_caisse);
verifier($id_caisse > 0 && (int) $banque->type === Account::TYPE_CASH && $banque->account_number === '53' && (int) $banque->fk_accountancy_journal > 0, '23. Banque de la caisse : type caisse, compte 53, journal posé');
verifier($reglages->banquePourLeCompte('530000', $user) === $id_caisse, '23. Second appel : la même banque');
// 23 bis. Sans le lien enregistre, la banque qui porte deja ce compte est retrouvee,
// pas recreee. / Without the saved link, the bank holding the account is found again.
$banques_avant = compter($db, 'bank_account');
dolibarr_del_const($db, 'TIBILLET_BANQUE_530000', $conf->entity);
$id_retrouve = $reglages->banquePourLeCompte('530000', $user);
verifier($id_retrouve === $id_caisse && compter($db, 'bank_account') === $banques_avant, '23 bis. Lien effacé : la banque du compte 53 est retrouvée, aucune banque créée');

// 24. Les codes de paiement (D22) / Payment codes
verifier($reglages->idDuCodeDePaiement('LE', false) === (int) dol_getIdFromCode($db, 'TIB', 'c_paiement', 'code', 'id', 1) && (int) dol_getIdFromCode($db, 'TIB', 'c_paiement', 'code', 'id', 1) > 0, '24. Monnaie locale → code TIB, créé et actif');
verifier($reglages->idDuCodeDePaiement('SN', false) === (int) dol_getIdFromCode($db, 'VAD', 'c_paiement', 'code', 'id', 1), '24. Stripe → VAD, actif');
verifier($reglages->idDuCodeDePaiement('CA', false) === (int) dol_getIdFromCode($db, 'LIQ', 'c_paiement', 'code', 'id', 1) && $reglages->idDuCodeDePaiement('CC', true) === (int) dol_getIdFromCode($db, 'LIQ', 'c_paiement', 'code', 'id', 1), '24. Espèces → LIQ ; toute banque de caisse → LIQ');

// 25 et 26. Le tiers : par e-mail, sans majuscules, le plus ancien ; sinon le client
// de passage.
// / Third party by e-mail; otherwise the walk-in customer.
$ventes_tibillet = new VentesTiBillet($db);
$ventes_tibillet->reglages = new ReglagesTiBillet($db);
$courriel = 'essai-'.dol_now().'@exemple.fr';
$id_tiers = appelerMethodePrivee($ventes_tibillet, 'tiersDeLaVente', array(array('identifier' => identifiantImite(1), 'email' => $courriel), $user));
$id_tiers_majuscules = appelerMethodePrivee($ventes_tibillet, 'tiersDeLaVente', array(array('identifier' => identifiantImite(1), 'email' => strtoupper($courriel)), $user));
$tiers = new Societe($db);
$tiers->fetch($id_tiers);
verifier($id_tiers > 0 && $id_tiers_majuscules === $id_tiers && $tiers->email === $courriel && $tiers->name === $courriel && (int) $tiers->client === 1, '25. Tiers créé par e-mail, retrouvé en majuscules');
$second_tiers = new Societe($db);
$second_tiers->name = 'Essai doublon';
$second_tiers->email = $courriel;
$second_tiers->client = 1;
$second_tiers->code_client = -1;
$second_tiers->create($user);
verifier(appelerMethodePrivee($ventes_tibillet, 'tiersDeLaVente', array(array('identifier' => identifiantImite(1), 'email' => $courriel), $user)) === $id_tiers, '25. Deux tiers avec cet e-mail : le plus ancien');
$second_tiers->delete($second_tiers->id, $user);
$id_de_passage = appelerMethodePrivee($ventes_tibillet, 'tiersDeLaVente', array(null, $user));
verifier($id_de_passage > 0 && $id_de_passage === getDolGlobalInt('TIBILLET_TIERS_DE_PASSAGE'), '26. Sans acheteur : le client de passage');

// 27 a 32. Une cloture de six ventes / One closure with six sales
$cloture_a = clotureImitee($numero_de_cloture);
$premiere_ligne_de_banque = dernierRowid($db, 'bank');
$ventes_a = array(
	// 27. Biere en especes / Cash beer
	venteImitee(1, array(ligneImitee($tarif_vendu, 500, 417, 83)), array(array('treasuryAccount' => '530000', 'method' => 'CA', 'amount' => 500)), array(), array(), array('identifier' => identifiantImite(1), 'email' => $courriel)),
	// 28. Biere + recharge en CB / Beer + top-up by card
	venteImitee(2, array(ligneImitee($tarif_vendu, 500, 417, 83)), array(array('treasuryAccount' => '512100', 'method' => 'CC', 'amount' => 500)),
		array(array('fromAccount' => '419100', 'fromMethod' => null, 'toAccount' => '512100', 'toMethod' => 'CC', 'amount' => 1000, 'label' => 'Essai recharge '.$numero_de_cloture))),
	// 29. Recharge seule / Top-up alone
	venteImitee(3, array(), array(),
		array(array('fromAccount' => '419100', 'fromMethod' => null, 'toAccount' => '530000', 'toMethod' => 'CA', 'amount' => 700, 'label' => 'Essai recharge seule '.$numero_de_cloture))),
	// 30. Avoir de la vente 1 / Credit note of sale 1
	venteImitee(4, array(ligneImitee($tarif_vendu, -500, -417, -83)), array(array('treasuryAccount' => '530000', 'method' => 'CA', 'amount' => -500)), array(), array(), null, 'refund', array('identifier' => identifiantDuPassage(1), 'orderNumber' => 1)),
	// 30 bis. Avoir d'une vente jamais importee / Credit note of an unknown sale
	venteImitee(5, array(ligneImitee($tarif_vendu, -500, -417, -83)), array(array('treasuryAccount' => '530000', 'method' => 'CA', 'amount' => -500)), array(), array(), null, 'refund', array('identifier' => identifiantDuPassage(4242), 'orderNumber' => 4242)),
	// 34. Recharge offerte / Offered top-up
	venteImitee(6, array(), array(), array(),
		array(array('treasuryAccount' => '419100', 'method' => null, 'amount' => -1000, 'counterpartAccount' => '623400', 'label' => 'Essai recharge offerte '.$numero_de_cloture))),
	// Quantite x prix qui ne tombe pas sur le total (2 x 2,50 dont 0,50 offert) : une
	// ligne de quantite 1 au total (D8). / Quantity x price off the total: quantity 1.
	venteImitee(7, array(ligneImitee($tarif_vendu, 450, 375, 75, '20.00', '707000', '445711', '2.000000', 250)), array(array('treasuryAccount' => '530000', 'method' => 'CA', 'amount' => 450))),
);
list($retour, $import) = passageDesVentes($db, array($cloture_a), array($cloture_a['uuid'] => $ventes_a));
verifier($retour === 0, '27. Un passage des ventes sans erreur ('.str_replace("\n", ' | ', $import->output).($import->error ? ' / '.$import->error : '').')');

$facture_1 = factureDeLaVenteImitee($db, 1);
$compte_707 = new AccountingAccount($db);
$compte_707->fetch(0, '707', 1);
$facture_1_juste = $facture_1 && (int) $facture_1->status === Facture::STATUS_CLOSED && (int) $facture_1->paye === 1
	&& round((float) $facture_1->total_ttc, 2) === 5.0 && round((float) $facture_1->total_ht, 2) === 4.17
	&& (int) $facture_1->socid === $id_tiers && count($facture_1->lines) === 1
	&& (int) $facture_1->lines[0]->fk_code_ventilation === (int) $compte_707->id;
verifier($facture_1_juste, '27. Bière en espèces : facture validée, payée, 5,00 TTC, au tiers de l\'e-mail, ligne liée au 707');
$paiements_1 = $facture_1 ? $facture_1->getListOfPayments() : array();
$banque_du_paiement_1 = $facture_1 ? $db->fetch_object($db->query("SELECT b.fk_account FROM ".$db->prefix()."paiement_facture as pf JOIN ".$db->prefix()."paiement as p ON p.rowid = pf.fk_paiement JOIN ".$db->prefix()."bank as b ON b.rowid = p.fk_bank WHERE pf.fk_facture = ".((int) $facture_1->id))) : null;
verifier(count($paiements_1) === 1 && $paiements_1[0]['type'] === 'LIQ' && $banque_du_paiement_1 && (int) $banque_du_paiement_1->fk_account === $id_caisse, '27. Un paiement LIQ, en banque sur la caisse');

$facture_2 = factureDeLaVenteImitee($db, 2);
verifier($facture_2 && round((float) $facture_2->total_ttc, 2) === 5.0 && (int) $facture_2->socid === $id_de_passage, '28. Bière + recharge : facture de 5,00 au client de passage');
verifier(lignesDeBanque($db, 'Essai recharge '.$numero_de_cloture, $premiere_ligne_de_banque) === array(array('4191', -10.0), array('5121', 10.0)), '28. La recharge : un virement de 10,00 de la banque 4191 vers la banque 512100');

$ligne_3 = $db->fetch_object($db->query("SELECT fk_facture FROM ".$db->prefix()."tibillet_vente_importee WHERE vente_uuid = '".$db->escape(identifiantDuPassage(3))."'"));
verifier($ligne_3 && $ligne_3->fk_facture === null && factureDeLaVenteImitee($db, 3) === null && count(lignesDeBanque($db, 'Essai recharge seule '.$numero_de_cloture, $premiere_ligne_de_banque)) === 2, '29. Recharge seule : aucune facture, un virement, la vente notée');

$avoir_4 = factureDeLaVenteImitee($db, 4);
$avoir_5 = factureDeLaVenteImitee($db, 5);
verifier($avoir_4 && (int) $avoir_4->type === Facture::TYPE_CREDIT_NOTE && (int) $avoir_4->fk_facture_source === (int) $facture_1->id && round((float) $avoir_4->total_ttc, 2) === -5.0, '30. Avoir : relié à la facture de la vente d\'origine, -5,00');
verifier($avoir_5 && (int) $avoir_5->type === Facture::TYPE_CREDIT_NOTE && empty($avoir_5->fk_facture_source), '30 bis. Avoir d\'une vente jamais importée : un avoir seul');

$ligne_divers = $db->fetch_object($db->query("SELECT v.accountancy_code, v.sens, v.amount, ba.account_number FROM ".$db->prefix()."payment_various as v JOIN ".$db->prefix()."bank as b ON b.rowid = v.fk_bank JOIN ".$db->prefix()."bank_account as ba ON ba.rowid = b.fk_account WHERE v.label = '".$db->escape('Essai recharge offerte '.$numero_de_cloture)."'"));
verifier($ligne_divers && $ligne_divers->accountancy_code === '6234' && (int) $ligne_divers->sens === 0 && round((float) $ligne_divers->amount, 2) === 10.0 && $ligne_divers->account_number === '4191', '34. Recharge offerte : paiement divers en sortie de 10,00 sur la banque 4191, contrepartie 6234');

$facture_7 = factureDeLaVenteImitee($db, 7);
verifier($facture_7 && round((float) $facture_7->total_ttc, 2) === 4.5 && (float) $facture_7->lines[0]->qty === 1.0 && strpos($facture_7->lines[0]->desc, '×') !== false, '27 bis. Quantité × prix hors du total : une ligne de quantité 1 à 4,50, la quantité dans le libellé');

$cloture_notee = $db->fetch_object($db->query("SELECT mode FROM ".$db->prefix()."tibillet_cloture_importee WHERE cloture_uuid = '".$db->escape($cloture_a['uuid'])."'"));
verifier($cloture_notee && $cloture_notee->mode === 'ventes', '33. La clôture est notée importée en mode « ventes »');

// 32. Second passage : rien de plus / Second run: nothing more
$factures_avant = compter($db, 'facture');
list($retour, $import) = passageDesVentes($db, array($cloture_a), array($cloture_a['uuid'] => $ventes_a));
verifier($retour === 0 && compter($db, 'facture') === $factures_avant, '32. Second passage : aucune facture de plus');

// 32 bis. Une vente deja importee, renvoyee seule : ignoree (garde vente par vente).
// / An already imported sale, sent again alone: skipped.
$import_seul = new VentesImitees($db);
$import_seul->reglages = new ReglagesTiBillet($db);
$factures_avant = compter($db, 'facture');
$retour_seul = appelerMethodePrivee($import_seul, 'importerUneVente', array($ventes_a[0], $cloture_a['uuid'], dol_now(), $user));
verifier($retour_seul === 0 && compter($db, 'facture') === $factures_avant, '32 bis. Vente déjà importée renvoyée seule : ignorée, aucune facture de plus');

// 31. Une vente dont le HT ne tombe pas juste : refusee, rien d'ecrit, le passage
// s'arrete ; la vente suivante attend.
// / A sale whose HT is off by a cent: refused, nothing written, the run stops.
$cloture_b = clotureImitee($numero_de_cloture + 1);
$ventes_b = array(
	venteImitee(11, array(ligneImitee($tarif_vendu, 500, 416, 84)), array(array('treasuryAccount' => '530000', 'method' => 'CA', 'amount' => 500))),
	venteImitee(12, array(ligneImitee($tarif_vendu, 500, 417, 83)), array(array('treasuryAccount' => '530000', 'method' => 'CA', 'amount' => 500))),
);
$factures_avant = compter($db, 'facture');
$banque_avant = compter($db, 'bank');
list($retour, $import) = passageDesVentes($db, array($cloture_b), array($cloture_b['uuid'] => $ventes_b));
verifier($retour === -1 && compter($db, 'facture') === $factures_avant && compter($db, 'bank') === $banque_avant && strpos($import->error, '11') !== false && factureDeLaVenteImitee($db, 12) === null, '31. HT faux d\'un centime : vente refusée, rien d\'écrit, la suivante attend ('.$import->error.')');

// 33. Limite de ventes par passage : coupure, puis reprise sans doublon.
// / Per-run limit: cut, then resumed without duplicate.
$cloture_c = clotureImitee($numero_de_cloture + 2);
$ventes_c = array();
for ($numero = 21; $numero <= 23; $numero++) {
	$ventes_c[] = venteImitee($numero, array(ligneImitee($tarif_vendu, 500, 417, 83)), array(array('treasuryAccount' => '530000', 'method' => 'CA', 'amount' => 500)));
}
$limite_d_avant = getDolGlobalString('TIBILLET_VENTES_PAR_PASSAGE');
dolibarr_set_const($db, 'TIBILLET_VENTES_PAR_PASSAGE', '2', 'chaine', 0, '', $conf->entity);
list($retour, $import) = passageDesVentes($db, array($cloture_c), array($cloture_c['uuid'] => $ventes_c));
$deux_seulement = factureDeLaVenteImitee($db, 22) !== null && factureDeLaVenteImitee($db, 23) === null;
list($retour_2, $import) = passageDesVentes($db, array($cloture_c), array($cloture_c['uuid'] => $ventes_c));
$facture_23 = factureDeLaVenteImitee($db, 23);
$facture_22 = factureDeLaVenteImitee($db, 22);
verifier($retour === 0 && $deux_seulement && $retour_2 === 0 && $facture_23 !== null && $facture_23->id > $facture_22->id, '33. Limite de 2 ventes : coupure, puis reprise à la vente suivante, dans l\'ordre');

// 33 bis. Sans limite (constante a 0, ou absente) : le passage suit la pagination de
// TiBillet jusqu'au bout, en un seul passage.
// / No limit (constant 0 or absent): the run follows the pagination to the end.
$sans_limite_complet = true;
$valeurs_sans_limite = array('0', '');
foreach ($valeurs_sans_limite as $position => $valeur_sans_limite) {
	if ($valeur_sans_limite === '') {
		dolibarr_del_const($db, 'TIBILLET_VENTES_PAR_PASSAGE', $conf->entity);
	} else {
		dolibarr_set_const($db, 'TIBILLET_VENTES_PAR_PASSAGE', $valeur_sans_limite, 'chaine', 0, '', $conf->entity);
	}
	$cloture_d = clotureImitee($numero_de_cloture + 3 + $position);
	$premier_numero = 31 + 10 * $position;
	$ventes_d = array();
	for ($numero = $premier_numero; $numero < $premier_numero + 5; $numero++) {
		$ventes_d[] = venteImitee($numero, array(ligneImitee($tarif_vendu, 500, 417, 83)), array(array('treasuryAccount' => '530000', 'method' => 'CA', 'amount' => 500)));
	}
	$import = new VentesImitees($db);
	$import->clotures_imitees = array($cloture_d);
	$import->ventes_imitees = array($cloture_d['uuid'] => $ventes_d);
	$import->ventes_par_page_au_plus = 2;
	$retour = $import->importerLesVentes();
	$toutes_importees = true;
	for ($numero = $premier_numero; $numero < $premier_numero + 5; $numero++) {
		if (factureDeLaVenteImitee($db, $numero) === null) {
			$toutes_importees = false;
		}
	}
	$cloture_d_notee = $db->fetch_object($db->query("SELECT mode FROM ".$db->prefix()."tibillet_cloture_importee WHERE cloture_uuid = '".$db->escape($cloture_d['uuid'])."'"));
	if (!($retour === 0 && $toutes_importees && $cloture_d_notee && $cloture_d_notee->mode === 'ventes')) {
		$sans_limite_complet = false;
	}
}
verifier($sans_limite_complet, '33 bis. Sans limite (0 ou absente) : 5 ventes en pages de 2, toutes importées en un passage, clôture notée');

if ($limite_d_avant === '') {
	dolibarr_del_const($db, 'TIBILLET_VENTES_PAR_PASSAGE', $conf->entity);
} else {
	dolibarr_set_const($db, 'TIBILLET_VENTES_PAR_PASSAGE', $limite_d_avant, 'chaine', 0, '', $conf->entity);
}

// 35. « Reimporter » une cloture importee en mode ventes : refuse, meme en mode
// ecritures (sinon ses ecritures s'ajouteraient a ses factures).
// / Re-importing a closure imported in sales mode: refused.
dolibarr_set_const($db, 'TIBILLET_MODE', ImportTiBillet::MODE_ECRITURES, 'chaine', 0, '', $conf->entity);
$ligne_de_la_cloture_a = $db->fetch_object($db->query("SELECT rowid FROM ".$db->prefix()."tibillet_cloture_importee WHERE cloture_uuid = '".$db->escape($cloture_a['uuid'])."'"));
$lignes_du_grand_livre_avant = nombreDeLignes($db);
$import_ecritures = new ImportTiBillet($db);
$retour = $ligne_de_la_cloture_a ? $import_ecritures->reimporterUneCloture((int) $ligne_de_la_cloture_a->rowid) : 0;
verifier($retour === -1 && $import_ecritures->error === $langs->transnoentities('TiBilletClotureEnModeVentes') && nombreDeLignes($db) === $lignes_du_grand_livre_avant, '35. Réimporter une clôture importée en mode ventes : refusé, grand livre inchangé ('.$import_ecritures->error.')');
dolibarr_set_const($db, 'TIBILLET_MODE', ImportTiBillet::MODE_VENTES, 'chaine', 0, '', $conf->entity);

// 36. Le test de connexion, en mode ventes, dit quand la cle n'a pas le droit
// « Ventes » (TiBillet verifie le droit avant de chercher la cloture : un uuid nul
// suffit).
// / The connection test reports a key without the "sale" right.
class ConnexionImitee extends ImportTiBillet
{
	/** @var int Le code rendu pour la route des ventes / Code for the sales route */
	public $code_des_ventes = 403;

	protected function appelerTiBillet($chemin, $en_tetes_en_plus = array())
	{
		if (strpos($chemin, '/sales/') !== false) {
			return array('code' => $this->code_des_ventes, 'donnees' => array('detail' => 'imité'), 'etag' => '');
		}
		return parent::appelerTiBillet($chemin, $en_tetes_en_plus);
	}
}
$connexion = new ConnexionImitee($db);
list($connexion_reussie, $phrase_de_connexion) = $connexion->testerLaConnexion();
verifier(!$connexion_reussie && $phrase_de_connexion === $langs->transnoentities('TiBilletCleSansDroitVentes'), '36. Clé sans le droit « Ventes » : le test de connexion le dit ('.$phrase_de_connexion.')');
$connexion->code_des_ventes = 404;
list($connexion_reussie, $phrase_de_connexion) = $connexion->testerLaConnexion();
verifier($connexion_reussie, '36. Avec le droit « Ventes » (404 sur un uuid nul) : connexion réussie');

// 35 bis. Une cloture dont une partie des ventes est deja en factures (passage coupe
// par une vente refusee) ne s'importe jamais en ecritures : double compta.
// / A closure partly copied as invoices is never imported as entries.
$cloture_en_partie = identifiantDuPassage(7777);
$db->query("INSERT INTO ".$db->prefix()."tibillet_vente_importee (entity, vente_uuid, numero_vente, cloture_uuid, fk_facture, date_import) VALUES (".((int) $conf->entity).", '".$db->escape(identifiantDuPassage(7778))."', 1, '".$db->escape($cloture_en_partie)."', NULL, '".$db->idate(dol_now())."')");
$import_ecritures = new ImportTiBillet($db);
$lignes_du_grand_livre_avant = nombreDeLignes($db);
$retour = appelerMethodePrivee($import_ecritures, 'importerUneCloture', array($cloture_en_partie, $user, null));
verifier($retour === -1 && $import_ecritures->error === $langs->transnoentities('TiBilletClotureEnPartieEnVentes') && nombreDeLignes($db) === $lignes_du_grand_livre_avant, '35 bis. Clôture en partie en factures : jamais importée en écritures ('.$import_ecritures->error.')');

// 37. Le mode par defaut : « articles et ventes » pour une installation neuve, mais
// « ecritures » si des clotures sont deja dans le grand livre (un lieu qui utilise le
// mode ecritures ne bascule jamais en silence). Un mode enregistre fait toujours foi.
// / Default mode: sales for a new install, entries if closures are already imported.
verifier(ImportTiBillet::modeParDefaut(0) === ImportTiBillet::MODE_VENTES && ImportTiBillet::modeParDefaut(3) === ImportTiBillet::MODE_ECRITURES, '37. Mode par défaut : ventes sans clôture importée, écritures sinon');
$mode_enregistre = getDolGlobalString('TIBILLET_MODE');
dolibarr_del_const($db, 'TIBILLET_MODE', $conf->entity);
$mode_sans_reglage = ImportTiBillet::modeChoisi();
dolibarr_set_const($db, 'TIBILLET_MODE', $mode_enregistre, 'chaine', 0, '', $conf->entity);
verifier($mode_sans_reglage === ImportTiBillet::MODE_ECRITURES, '37. Sans mode enregistré, avec des clôtures déjà importées : écritures ('.$mode_sans_reglage.')');
// Seulement des clotures en mode ventes (les clotures en ecritures masquees le temps
// de l'essai, puis remises) : le mode par defaut reste « ventes ».
// / Only sales-mode closures: the default mode stays sales.
$db->query("UPDATE ".$db->prefix()."tibillet_cloture_importee SET mode = 'essai37' WHERE mode = 'ecritures'");
dolibarr_del_const($db, 'TIBILLET_MODE', $conf->entity);
$mode_avec_des_ventes_seulement = ImportTiBillet::modeChoisi();
dolibarr_set_const($db, 'TIBILLET_MODE', $mode_enregistre, 'chaine', 0, '', $conf->entity);
$db->query("UPDATE ".$db->prefix()."tibillet_cloture_importee SET mode = 'ecritures' WHERE mode = 'essai37'");
verifier($mode_avec_des_ventes_seulement === ImportTiBillet::MODE_VENTES, '37. Des clôtures importées en ventes seulement : le mode par défaut reste ventes ('.$mode_avec_des_ventes_seulement.')');
$import_compte = new ImportTiBillet($db);
verifier($import_compte->nombreDeCloturesImportees(ImportTiBillet::MODE_ECRITURES) === compter($db, 'tibillet_cloture_importee', "mode = 'ecritures'") && $import_compte->nombreDeCloturesImportees(ImportTiBillet::MODE_ECRITURES) < $import_compte->nombreDeCloturesImportees(), '37. Le mode par défaut ne compte que les clôtures du grand livre, pas celles importées en ventes');

// Menage : les clotures et ventes imitees sortent des tables du module (sinon le
// curseur des vraies clotures sauterait).
// / Cleanup: faked closures and sales leave the module tables.
$db->query("DELETE FROM ".$db->prefix()."tibillet_vente_importee WHERE vente_uuid LIKE '%".$db->escape(substr(FIN_IMITEE, 5))."'");
$db->query("DELETE FROM ".$db->prefix()."tibillet_cloture_importee WHERE cloture_uuid LIKE '%".$db->escape(substr(FIN_IMITEE, 5))."'");

// 12. Mode « ecritures » : la synchro ne fait rien / Entries mode: no sync
dolibarr_set_const($db, 'TIBILLET_MODE', ImportTiBillet::MODE_ECRITURES, 'chaine', 0, '', $conf->entity);
$produits_avant = compter($db, 'product');
list($retour, $catalogue) = synchroniserSansEtag($db);
verifier($retour === 0 && $catalogue->output === $langs->transnoentities('TiBilletModeEcrituresPasDeCatalogue') && compter($db, 'product') === $produits_avant, '12. Mode écritures : la synchro ne fait rien');

// 14. L'heure de la tache de nuit / The nightly job time
$heure_d_avant = getDolGlobalString('TIBILLET_HEURE');
// L'heure choisie est dans une heure : le prochain passage doit tomber AUJOURD'HUI (ou
// juste apres minuit), donc dans les 24 h, et a cette heure-la.
// / The chosen time is one hour from now: the next run is within 24 h, at that time.
$heure_dans_une_heure = dol_print_date(dol_now() + 3600, '%H:%M');
dolibarr_set_const($db, 'TIBILLET_HEURE', $heure_dans_une_heure, 'chaine', 0, '', $conf->entity);
$retour = $import->recalerLaTacheDeNuit($user);
$prochain = $import->prochainPassage();
$ecart_en_secondes = $prochain - dol_now();
verifier($retour === 1 && dol_print_date($prochain, '%H:%M') === $heure_dans_une_heure && $ecart_en_secondes > 0 && $ecart_en_secondes <= 3600, '14. Heure '.$heure_dans_une_heure.' : la tâche est recalée dans l\'heure qui vient ('.dol_print_date($prochain, 'dayhour').')');
verifier(!ImportTiBillet::heureEstValide('25:00') && !ImportTiBillet::heureEstValide('7h30') && ImportTiBillet::heureEstValide('00:00'), '14. Une heure invalide est refusée (25:00, 7h30)');
dolibarr_set_const($db, 'TIBILLET_HEURE', ($heure_d_avant !== '' ? $heure_d_avant : '06:00'), 'chaine', 0, '', $conf->entity);
$import->recalerLaTacheDeNuit($user);

dolibarr_set_const($db, 'TIBILLET_MODE', $mode_d_origine, 'chaine', 0, '', $conf->entity);

echo "\n".($nombre_d_echecs === 0 ? 'TOUT EST OK' : $nombre_d_echecs.' ECHEC(S)')."\n";
exit($nombre_d_echecs === 0 ? 0 : 1);
