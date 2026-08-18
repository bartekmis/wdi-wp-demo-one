# wdi-wp-demo-one

Repozytorium `wp-content` dla projektu demo kursu WOW.

- Produkcja: https://wdi-demo-one.online
- Serwer: `/var/www/wdi-demo-one.online` (root WordPressa), repo = `wp-content`

## Co jest wersjonowane

- `themes/` - motyw (Blocksy)
- `mu-plugins/` - autorskie mu-pluginy WOW
- `index.php`, `.gitignore`, `.github/`

Poza gitem (patrz `.gitignore`): `uploads/`, `plugins/`, `wflogs/`, `upgrade/`, cache, logi.

## Deploy

Push na `main` uruchamia GitHub Actions (`.github/workflows/deploy.yml`), które loguje się
po SSH na serwer, robi `git fetch` + `git reset --hard origin/main` w `wp-content`
i czyści cache WordPressa oraz Elementora.

Sekrety repo: `SSH_HOST`, `SSH_USER`, `SSH_PORT`, `SSH_PRIVATE_KEY`, `WP_CONTENT_PATH`.

## Konfiguracja

Dane bazy i adresy siedzą w `.env` w rootcie WordPressa na serwerze
(`/var/www/wdi-demo-one.online/.env`), czytanym przez `wp-config.php`. Plik nie jest w repo.
