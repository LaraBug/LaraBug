# Forgejo workflows

v3 is tested on GitHub Actions only, by `.github/workflows/run-tests.yml`.

The Forgejo mirror at git.intil.li runs the workflows in `.forgejo/workflows` and only falls back to `.github/workflows` when this folder does not exist. This file keeps the folder in place, so the mirror runs nothing for v3.
