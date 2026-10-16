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
 * Descripteur du module TiBillet.
 * / TiBillet module descriptor.
 *
 * Ce que fait le module, chaque jour, selon le mode choisi sur sa page (un seul a la
 * fois, sinon chaque vente serait comptee deux fois) :
 * - mode « ecritures » : il va chercher chez TiBillet les clotures journalieres du lieu
 *   qu'il n'a pas encore, et range leurs ecritures dans le grand livre, NON validees ;
 * - mode « articles et ventes » : il recopie le catalogue de TiBillet (produits,
 *   categories, TVA, compte de vente), puis les ventes des clotures en factures payees,
 *   virements internes et paiements divers.
 * / Each day, depending on the mode: imports closures into the ledger, OR copies
 * TiBillet's catalogue then its sales.
 *
 * Spec : TECH_DOC/SESSIONS/COMPTABILITE/CHANTIER-06-connecteurs-comptables/
 *        CHANTIER-06-B-module-dolibarr.md, CHANTIER-06-C-catalogue.md et
 *        CHANTIER-06-D-ventes.md (depot Lespass).
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Le module TiBillet.
 * / The TiBillet module.
 */
class modTiBillet extends DolibarrModules
{
	/**
	 * Constructeur : nom, dependances, reglages, tache planifiee, table.
	 * / Constructor: name, dependencies, settings, scheduled job, table.
	 *
	 * @param DoliDB $db Base de donnees / Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		// Numero de module externe (>= 100000). A reserver sur
		// https://wiki.dolibarr.org/index.php/List_of_modules_id avant une diffusion.
		// / External module id (>= 100000). To reserve on the Dolibarr wiki.
		$this->numero = 517300;
		$this->rights_class = 'tibillet';
		$this->family = "financial";
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "TiBilletDescription";
		$this->descriptionlong = "TiBilletDescription";
		$this->editor_name = 'Cooperative TiBillet';
		$this->editor_url = 'https://tibillet.coop';
		$this->version = '0.1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		// « tibillet@tibillet » : Dolibarr lit img/object_tibillet.png dans ce module
		// (le logo carré « tiB » de TiBillet, 64 x 64).
		// / Dolibarr reads img/object_tibillet.png from this module.
		$this->picto = 'tibillet@tibillet';

		$this->module_parts = array();
		$this->dirs = array();
		$this->config_page_url = array("setup.php@tibillet");

		// Le grand livre n'existe que si la comptabilite en partie double est active.
		// / The general ledger only exists when double-entry accounting is enabled.
		// modCron : sans le module « Travaux planifiés », cron_run_jobs.php annule la
		// tâche de nuit (« Module Scheduled jobs (cron) not activated »). Dolibarr
		// l'active tout seul avec TiBillet. / Without modCron the nightly job is cancelled.
		$this->depends = array('modAccounting', 'modCron');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("tibillet@tibillet");
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(24, 0);

		// Les reglages sont ecrits par la page du module (admin/setup.php).
		// / Settings are written by the module page.
		$this->const = array();

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();

		// La tache de nuit : active des l'installation (decision du mainteneur), une
		// fois par jour, a l'heure reglee sur la page du module (TIBILLET_HEURE, 06:00
		// par defaut, apres la cloture automatique de TiBillet). Dolibarr garde l'heure de cette premiere date pour les passages
		// suivants. Sans adresse ni cle, elle ne fait rien et le dit.
		// / The nightly job: enabled at install, once a day at the configured time.
		dol_include_once('/tibillet/class/importtibillet.class.php');
		$premier_passage = ImportTiBillet::prochaineOccurrence(ImportTiBillet::heureDeLaTache());
		$this->cronjobs = array(
			0 => array(
				// « clé:fichier@module » : la fiche de la tâche charge nos traductions
				// (libellé et commentaire), sinon elle montre les clés brutes.
				// / "key:file@module" makes the job card load our translations.
				'label' => 'TiBilletTacheDeNuit:tibillet@tibillet',
				'jobtype' => 'method',
				'class' => '/tibillet/class/importtibillet.class.php',
				'objectname' => 'ImportTiBillet',
				'method' => 'passageDeLaNuit',
				'parameters' => '',
				'comment' => 'TiBilletTacheCommentaire',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'datenextrun' => $premier_passage,
				'status' => 1,
				'test' => 'isModEnabled("tibillet")',
				'priority' => 50,
			),
		);

		// Pas de droits propres : la page du module est reservee aux admins.
		// / No own rights: the module page is admin-only.
		$this->rights = array();
		$this->menu = array();
	}

	/**
	 * A l'activation : cree la table des clotures importees, la tache planifiee.
	 * / On enable: creates the imported closures table and the scheduled job.
	 *
	 * @param string $options Options ('', 'noboxes')
	 * @return int 1 si OK, <= 0 sinon / 1 if OK, <= 0 if KO
	 */
	public function init($options = '')
	{
		global $conf;

		$resultat = $this->_load_tables('/tibillet/sql/');
		if ($resultat < 0) {
			return -1;
		}

		// L'ancienne tache (methode importerLesClotures) est remplacee par
		// passageDeLaNuit. Dolibarr ne la retire pas tout seul : son test n'est pas
		// '1' (voir DolibarrModules::delete_cronjobs).
		// / The old job is replaced by passageDeLaNuit; Dolibarr does not remove it.
		$sql = "DELETE FROM ".$this->db->prefix()."cronjob";
		$sql .= " WHERE module_name = 'tibillet' AND methodename = 'importerLesClotures'";
		$sql .= " AND entity = ".((int) $conf->entity);
		$this->db->query($sql);

		$this->remove($options);
		return $this->_init(array(), $options);
	}

	/**
	 * A la desactivation : retire la tache et les droits. Les clotures importees et la
	 * table restent (le grand livre n'est jamais touche).
	 * / On disable: removes the job. Imported data and the table stay.
	 *
	 * @param string $options Options
	 * @return int 1 si OK, <= 0 sinon / 1 if OK, <= 0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
