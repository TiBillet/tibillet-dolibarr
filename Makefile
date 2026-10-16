# Publier une version du module TiBillet pour Dolibarr.
# / Publish a release of the TiBillet module for Dolibarr.
#
# Pour une nouvelle version :
#   1. changer `$this->version` dans core/modules/modTiBillet.class.php ;
#   2. commiter et pousser ;
#   3. lancer `make release`.
# / New release: bump $this->version, commit and push, then `make release`.

.PHONY: help version zip release

# La version est lue dans le descripteur du module : une seule source.
# / The version is read from the module descriptor: a single source.
VERSION := $(shell sed -n "s/.*this->version = '\([^']*\)'.*/\1/p" core/modules/modTiBillet.class.php)
TAG := v$(VERSION)
# Dolibarr exige ce nom : module_<nom>-<version>.zip, avec un dossier tibillet/ dedans.
# / Dolibarr requires this name, with a tibillet/ folder inside.
ZIP := dist/module_tibillet-$(VERSION).zip

help:
	@echo "Module TiBillet pour Dolibarr — version $(VERSION)"
	@echo ""
	@echo "  make version   affiche la version lue dans le module"
	@echo "  make zip       fabrique $(ZIP) (fichiers commités seulement)"
	@echo "  make release   tag $(TAG) + zip + release GitHub (gh)"

version:
	@echo $(VERSION)

# Le zip est fait par `git archive` : seulement les fichiers commités, sans .git, et
# sans ce que .gitattributes marque `export-ignore` (scripts d'essai, Makefile…).
# / The zip only holds committed files, minus `export-ignore` ones.
zip:
	@test -n "$(VERSION)" || (echo "Version introuvable dans core/modules/modTiBillet.class.php" && exit 1)
	@mkdir -p dist
	git archive --format=zip --prefix=tibillet/ -o $(ZIP) HEAD
	@echo ""
	@unzip -l $(ZIP)
	@echo ""
	@echo "Zip prêt : $(ZIP)"

# Refuse de publier si quelque chose n'est pas commité ou poussé, ou si la version
# existe déjà : une release publiée ne doit plus jamais changer.
# / Refuses if anything is uncommitted or unpushed, or if the version already exists.
release:
	@test -n "$(VERSION)" || (echo "Version introuvable dans core/modules/modTiBillet.class.php" && exit 1)
	@command -v gh >/dev/null || (echo "Il faut l'outil gh (GitHub CLI) : https://cli.github.com" && exit 1)
	@test -z "$$(git status --porcelain)" || (echo "Des fichiers ne sont pas commités : commitez d'abord." && git status --short && exit 1)
	@git fetch --quiet origin
	@test "$$(git rev-parse HEAD)" = "$$(git rev-parse origin/main)" || (echo "La branche main locale n'est pas égale à origin/main : poussez (ou tirez) d'abord." && exit 1)
	@! git rev-parse --quiet --verify "refs/tags/$(TAG)" >/dev/null || (echo "Le tag $(TAG) existe déjà : changez la version dans core/modules/modTiBillet.class.php." && exit 1)
	$(MAKE) zip
	git tag -a $(TAG) -m "TiBillet pour Dolibarr $(VERSION)"
	git push origin $(TAG)
	gh release create $(TAG) $(ZIP) \
		--title "TiBillet pour Dolibarr $(VERSION)" \
		--notes "Installation : dans Dolibarr, Configuration → Modules/Applications → Déployer/Installer un module externe → envoyer le fichier module_tibillet-$(VERSION).zip ci-dessous. Mise à jour : même chose, puis désactiver et réactiver le module. Guide pas à pas : https://github.com/TiBillet/tibillet-dolibarr/blob/main/docs/02-relier-tibillet-a-dolibarr.md"
	@echo ""
	@echo "Release $(TAG) publiée."
