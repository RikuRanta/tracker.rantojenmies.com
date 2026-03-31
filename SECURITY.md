# Security checklist

## Deployment must-dos
- Serve `loki` and `api` over HTTPS only.
- Set required environment secrets (`TRACKER_DB_USER`, `TRACKER_DB_PASSWORD`) from `.env.example`.
- Set `TRACKER_SHARED_SECRET` on both UI and API hosts with the exact same value.

## Session and API auth
- API auth uses stateless signed `X-Api-Token` (HMAC) bound to IMEI and expiry.
- Configure the same `TRACKER_SHARED_SECRET` on both UI host (`www`) and API host (`tracker`).
- Verify requests include `X-Api-Token` and `X-Api-Imei`.

## Secret handling
- Do not commit real credentials to git.
- Rotate DB credentials on a schedule and after incidents.
- Restrict DB user privileges to least privilege required by this app.
- If any real secrets were ever committed (even in docs), rotate them immediately.

## Operations
- Keep regular backups for database and log retention.
- Monitor failed API auth attempts and unusual write activity.
- Restrict network access to the TCP ingest port (`4096`) to trusted device sources.

## Apache env deployment
- Prefer setting app env vars in the Apache HTTPS vhost for this site.
- For multi-UI paths (for example `/loki` and `/sabra`), use path-based env vars and `Alias` mappings.

- UI vhost example (`/etc/apache2/sites-available/www.rantojenmies.com.conf`):

```apache
<VirtualHost *:443>
		ServerName www.rantojenmies.com
		DocumentRoot /var/www/www.rantojenmies.com

		# Must match API host secret exactly
		SetEnv TRACKER_SHARED_SECRET "<REPLACE_WITH_64_HEX_SECRET>"

		# Per-path UI values
		SetEnvIfExpr "req('REQUEST_URI') =~ m#^/loki(/|$)#" "TRACKER_IMEI=<REPLACE_WITH_LOKI_IMEI>"
		SetEnvIfExpr "req('REQUEST_URI') =~ m#^/loki(/|$)#" "TRACKER_SITE_FOLDER=loki"
		SetEnvIfExpr "req('REQUEST_URI') =~ m#^/loki(/|$)#" "TRACKER_SITE_NAME=Rantojen mies | S/Y La Vida - loki"

		SetEnvIfExpr "req('REQUEST_URI') =~ m#^/sabra(/|$)#" "TRACKER_IMEI=<REPLACE_WITH_SABRA_IMEI>"
		SetEnvIfExpr "req('REQUEST_URI') =~ m#^/sabra(/|$)#" "TRACKER_SITE_FOLDER=sabra"
		SetEnvIfExpr "req('REQUEST_URI') =~ m#^/sabra(/|$)#" "TRACKER_SITE_NAME=S/Y Sabra - loki"

		Alias /loki/ /var/www/tracker.rantojenmies.com/loki/
		Alias /sabra/ /var/www/tracker.rantojenmies.com/loki/

		<Directory /var/www/tracker.rantojenmies.com/loki>
			Options +FollowSymLinks
			AllowOverride All
			Require all granted
		</Directory>
</VirtualHost>
```

- API/backend vhost example (`/etc/apache2/sites-available/tracker.rantojenmies.com.conf`):

```apache
<VirtualHost *:443>
		ServerName tracker.rantojenmies.com
		DocumentRoot /var/www/tracker.rantojenmies.com

		# Must match UI host secret exactly
		SetEnv TRACKER_SHARED_SECRET "<REPLACE_WITH_64_HEX_SECRET>"

		# Required
		SetEnv TRACKER_DB_USER "<REPLACE_WITH_DB_USER>"
		SetEnv TRACKER_DB_PASSWORD "<REPLACE_WITH_STRONG_DB_PASSWORD>"
</VirtualHost>
```

- Generate strong secret once:
	- `openssl rand -hex 32`
- Enable site + reload Apache:
	- `sudo a2ensite www.rantojenmies.com.conf`
	- `sudo a2ensite tracker.rantojenmies.com.conf`
	- `sudo apache2ctl configtest`
	- `sudo systemctl reload apache2`
- Verify in runtime (temporary check file):
	- `<?php var_dump(getenv('TRACKER_DB_USER'), $_SERVER['TRACKER_DB_USER'] ?? null); ?>`
	- Open once in browser, confirm value is non-empty, then delete file.
