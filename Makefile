# Publier une version du module TiBillet pour Dolibarr.
# / Publish a release of the TiBillet module for Dolibarr.
#
# Pour une nouvelle version : commiter et pousser, puis lancer `make release`.
# - Si la version du descripteur est DEJA publiee (son tag existe), `make release`
#   passe a la suivante (0.3.0 → 0.4.0), la commite et la pousse, puis publie.
# - Sinon (version changee a la main), il publie cette version telle quelle.
# / Commit and push, then `make release`. An already released version is bumped
# (0.3.0 → 0.4.0), committed and pushed first; a hand-set version is released as is.

.PHONY: help version zip release controles publier

DESCRIPTEUR := core/modules/modTiBillet.class.php

# La version est lue dans le descripteur du module : une seule source.
# / The version is read from the module descriptor: a single source.
VERSION := $(shell sed -n "s/.*this->version = '\([^']*\)'.*/\1/p" $(DESCRIPTEUR))
TAG := v$(VERSION)
# La version suivante : le deuxieme nombre + 1, le troisieme remis a 0.
# / Next version: second number + 1, third back to 0.
VERSION_SUIVANTE := $(shell echo "$(VERSION)" | awk -F. '{ printf "%s.%s.0", $$1, $$2 + 1 }')
# Dolibarr exige ce nom : module_<nom>-<version>.zip, avec un dossier tibillet/ dedans.
# / Dolibarr requires this name, with a tibillet/ folder inside.
ZIP := dist/module_tibillet-$(VERSION).zip

help:
	@echo "Module TiBillet pour Dolibarr — version $(VERSION)"
	@echo ""
	@echo "  make version   affiche la version lue dans le module"
	@echo "  make zip       fabrique $(ZIP) (fichiers commités seulement)"
	@echo "  make release   publie $(TAG), ou passe à $(VERSION_SUIVANTE) si $(TAG) existe déjà"

version:
	@echo $(VERSION)

# Le zip est fait par `git archive` : seulement les fichiers commités, sans .git, et
# sans ce que .gitattributes marque `export-ignore` (scripts d'essai, Makefile…).
# / The zip only holds committed files, minus `export-ignore` ones.
zip:
	@test -n "$(VERSION)" || (echo "Version introuvable dans $(DESCRIPTEUR)" && exit 1)
	@mkdir -p dist
	git archive --format=zip --prefix=tibillet/ -o $(ZIP) HEAD
	@echo ""
	@unzip -l $(ZIP)
	@echo ""
	@echo "Zip prêt : $(ZIP)"

# Refuse de publier si quelque chose n'est pas commité ou poussé : le zip et le tag
# doivent correspondre exactement a origin/main.
# / Refuses if anything is uncommitted or unpushed.
controles:
	@test -n "$(VERSION)" || (echo "Version introuvable dans $(DESCRIPTEUR)" && exit 1)
	@command -v gh >/dev/null || (echo "Il faut l'outil gh (GitHub CLI) : https://cli.github.com" && exit 1)
	@test -z "$$(git status --porcelain)" || (echo "Des fichiers ne sont pas commités : commitez d'abord." && git status --short && exit 1)
	@git fetch --quiet origin
	@test "$$(git rev-parse HEAD)" = "$$(git rev-parse origin/main)" || (echo "La branche main locale n'est pas égale à origin/main : poussez (ou tirez) d'abord." && exit 1)

# Une version deja publiee ne change jamais : si son tag existe, on passe d'abord a la
# suivante, commitee et poussee. `publier` est relance par $(MAKE) pour relire la
# nouvelle version dans le descripteur.
# / A released version never changes: bump first if its tag exists.
release: controles
	@if git rev-parse --quiet --verify "refs/tags/$(TAG)" >/dev/null; then \
		echo "$(TAG) est déjà publiée : passage à $(VERSION_SUIVANTE)." && \
		sed -i "s/this->version = '$(VERSION)'/this->version = '$(VERSION_SUIVANTE)'/" $(DESCRIPTEUR) && \
		git commit --quiet -m "TiBillet pour Dolibarr $(VERSION_SUIVANTE)" $(DESCRIPTEUR) && \
		git push --quiet origin main; \
	fi
	@$(MAKE) --no-print-directory publier

publier: controles
	@! git rev-parse --quiet --verify "refs/tags/$(TAG)" >/dev/null || (echo "Le tag $(TAG) existe déjà : lancez make release." && exit 1)
	$(MAKE) --no-print-directory zip
	git tag -a $(TAG) -m "TiBillet pour Dolibarr $(VERSION)"
	git push origin $(TAG)
	gh release create $(TAG) $(ZIP) \
		--title "TiBillet pour Dolibarr $(VERSION)" \
		--notes "Installation : dans Dolibarr, Configuration → Modules/Applications → Déployer/Installer un module externe → envoyer le fichier module_tibillet-$(VERSION).zip ci-dessous. Mise à jour : même chose, puis désactiver et réactiver le module. Guide pas à pas : https://github.com/TiBillet/tibillet-dolibarr/blob/main/docs/00-installer-le-module.md"
	@echo ""
	@echo "Release $(TAG) publiée."
