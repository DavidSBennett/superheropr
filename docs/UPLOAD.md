# Upload instructions - Phase 2 vertical slice

Target layout on GreenGeeks (adjust the folder name if you prefer another):

```
public_html/superheropr/           <- contents of the dist zip (index.html, assets/)
public_html/superheropr/api/       <- everything in server/api/
public_html/superheropr/engine/    <- everything in server/engine/
public_html/superheropr/data/      <- the 5 CSVs from data/ (for re-imports)
```

Steps, in order:

1. Database (cPanel > MySQL Databases): create a database and a user, grant
   the user ALL on it. Then cPanel > phpMyAdmin: select the database and run
   server/migrations/001_init.sql.
2. Config: copy server/api/config.sample.php to api/config.local.php on the
   host and fill in the MySQL dsn/user/pass. Put your shim username in
   'admins'.
3. Files (cPanel File Manager): upload and extract the dist zip into
   public_html/superheropr/, then upload the api/ and engine/ folders next to
   it as shown above. engine/ must sit one level above api/ (api files
   require __DIR__ . '/../engine/...').
4. Card import: visit https://yourdomain/superheropr/api/auth_login.php is
   not needed first - open the app at https://yourdomain/superheropr/, log in
   with your admin username, then visit
   https://yourdomain/superheropr/api/admin_import.php, upload the five CSVs
   from data/ with a version label, and submit. A JSON response with
   "ok": true means the card set is live.
5. Play: open https://yourdomain/superheropr/ in two browsers, create a
   2-player game, share the join code, start.

Notes:
- The slice uses the placeholder sign-in (any username). It will be replaced
  by The Historians auth once I have that source.
- PHP 8.1+ required (the code uses readonly-free 8.1 syntax; tested on 8.3).
- No composer dependencies are needed for CSV import. The xlsx upload path in
  admin_import.php activates only if PhpSpreadsheet exists at
  ../vendor/autoload.php relative to api/.
- Local dev on your machine (optional): php -d extension=pdo_sqlite -S
  127.0.0.1:8010 -t server, then npm run dev in client/. The sqlite config in
  api/config.local.php is created automatically by the repo copy.
