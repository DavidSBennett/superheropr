# Superhero P.R. - installation on GreenGeeks

Two paths. A is the one-time setup that makes every future delivery a
two-click deploy from GitHub; B is the plain file-manager upload. Both end
with the same layout:

```
public_html/superheropr/           index.html + assets/   (the built client)
public_html/superheropr/api/       PHP endpoints
public_html/superheropr/engine/    rules engine (blocked from the web by .htaccess)
public_html/superheropr/data/      the 5 card CSVs (for re-imports)
```

## 0. One-time: database (both paths)

1. cPanel > MySQL Databases: create a database (e.g. USER_superheropr) and a
   DB user with a strong password; grant the user ALL PRIVILEGES on it.
2. cPanel > phpMyAdmin: select that database, Import (or SQL tab) and run
   server/migrations/001_init.sql from the repo.
3. Check PHP version (cPanel > Select PHP Version): 8.1 or newer.

## A. Recommended: deploy from GitHub through cPanel

The repo is public, so no tokens or SSH keys are needed to clone it.

One-time setup:

1. cPanel > Git Version Control > Create.
   - Clone URL: https://github.com/DavidSBennett/superheropr.git
   - Repository Path: repositories/superheropr   (anywhere outside
     public_html; cPanel suggests a path under your home)
   - Repository Name: superheropr
2. Open the repo's Manage > Pull or Deploy tab and click
   "Deploy HEAD Commit". The .cpanel.yml at the repo root copies the built
   client, api/, engine/ and the CSVs into public_html/superheropr/.
   (To install somewhere else, edit the DEPLOY line in .cpanel.yml.)
3. Create the config by hand once (it is deliberately not in the repo):
   cPanel > File Manager > public_html/superheropr/api/ > copy
   config.sample.php to config.local.php, edit it with the database name,
   user and password from step 0, and put your username in 'admins'.

Every later delivery:

1. Git Version Control > Manage > Update from Remote (pulls main).
2. Deploy HEAD Commit.
3. Only when a delivery note says card data changed: re-import the CSVs
   (step C below). Deploys never touch config.local.php or the database.

## B. Alternative: manual upload

1. Upload the dist zip from dist-zips/ and extract it into
   public_html/superheropr/.
2. Upload server/api/ to public_html/superheropr/api/ and server/engine/ to
   public_html/superheropr/engine/ (engine must sit next to api, not inside
   it). Include server/engine/.htaccess.
3. Create api/config.local.php as in A step 3.

## C. Card import (first install, and whenever the data changes)

1. Open https://YOURDOMAIN/superheropr/ and log in with the username you put
   in 'admins' (the placeholder sign-in accepts any name and creates it).
2. Visit https://YOURDOMAIN/superheropr/api/admin_import.php, choose the five
   CSVs from data/ (crises, mandates, fallen_heroes, spin, locations), give
   the set a version label (e.g. 2026-11-07-a) and submit.
3. A JSON response with "ok": true means the set is live. New games use the
   newest set; running games keep the set they started with.

## D. Smoke test

Open the app in two browsers (or one normal + one private window), sign in as
two different names, create a 2-player game, join with the code, start, and
play a round. The "Your Games" list on the lobby shows whose turn it is.

## Notes

- The sign-in is still the placeholder shim. When you send The Historians
  source, it gets replaced by the real AuthContext/RequireAuth and the shim
  table goes away; that swap will come with its own migration note.
- client/dist/ is committed to the repo on purpose: cPanel's deploy has no
  Node, so I build the client here and ship the output with each delivery.
- Local development on your machine needs no MySQL:
  php -d extension=pdo_sqlite -S 127.0.0.1:8010 -t server
  then: npm run dev (in client/). Import cards once with
  php -d extension=pdo_sqlite server/cli/import_local.php
- Balance tooling: php server/cli/simulate.php --players=4 --games=2000
  (see docs/BALANCE.md for the current report).
