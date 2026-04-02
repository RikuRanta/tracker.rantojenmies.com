# Apache stack switch runbook (Ubuntu)

## Goal
Switch from Apache `mpm_prefork + mod_php` to `mpm_event + php8.2-fpm`, and enable HTTP/2.

## 1) Precheck

```bash
apachectl -M | egrep "mpm_|php|proxy_fcgi|http2"
ls /etc/apache2/conf-enabled | grep php
systemctl list-unit-files | grep -E "php.*fpm"
```

## 2) Switch modules

```bash
sudo a2dismod php7 || true
sudo a2dismod php7.4 || true
sudo a2dismod php8.2 || true
sudo a2dismod php8.4 || true
sudo a2dismod mpm_prefork

sudo a2enmod mpm_event
sudo a2enmod proxy_fcgi
sudo a2enmod setenvif
sudo a2enmod http2

sudo a2enconf php8.2-fpm
sudo a2disconf php8.4-fpm || true

sudo systemctl restart php8.2-fpm
```

## 3) Validate and restart

```bash
sudo apachectl -t
sudo systemctl restart apache2
```

## 4) Verify result

```bash
apachectl -M | egrep "mpm_|php|proxy_fcgi|http2"
```

Expected:
- `mpm_event_module (shared)`
- `proxy_fcgi_module (shared)`
- `http2_module (shared)`
- no `php7_module`

## 5) Enable HTTP/2 in TLS vhost
Find your `:443` vhost:

```bash
sudo grep -R "VirtualHost .*:443" /etc/apache2/sites-enabled /etc/apache2/sites-available
```

Inside that `VirtualHost *:443` block, add:

```apache
Protocols h2 http/1.1
```

Apply and verify:

```bash
sudo apachectl -t && sudo systemctl reload apache2
curl -I --http2 https://YOUR_DOMAIN/
```

Expected first line includes `HTTP/2`.

## 6) If something fails

```bash
sudo apachectl -t
sudo journalctl -u apache2 -n 120 --no-pager
sudo journalctl -u php8.2-fpm -n 120 --no-pager
```

## Rollback

```bash
sudo a2disconf php8.2-fpm
sudo a2dismod mpm_event proxy_fcgi http2
sudo a2enmod mpm_prefork php7
sudo apachectl -t && sudo systemctl restart apache2
```

## 7) Production bottleneck diagnostics

Run these during an active pending-request window.

### A) Confirm active runtime stack

```bash
apachectl -M | egrep "mpm_|php|proxy_fcgi|http2"
```

### B) Apache worker pressure

```bash
ps -eo pid,ppid,cmd,%mem,%cpu --sort=-%cpu | grep -E "apache2|httpd" | head -n 30
ss -s
```

### C) PHP-FPM saturation and warnings

```bash
systemctl status php8.2-fpm --no-pager
journalctl -u php8.2-fpm --since "20 min ago" | egrep -i "max_children|slow|warning|error|pool"
```

### D) Apache errors around queueing/timeouts

```bash
journalctl -u apache2 --since "20 min ago" | egrep -i "MaxRequestWorkers|server reached|timeout|proxy|AH0|error"
```

### E) Tile hit vs miss mix (access log)

```bash
grep " /loki/merikortti/tiles/" /var/log/apache2/access.log | tail -n 200
grep " /loki/merikortti/TileProxy.php" /var/log/apache2/access.log | tail -n 200
```

### F) Timing: cache-hit tile vs miss tile

```bash
curl -s -o /dev/null -w "code=%{http_code} total=%{time_total} connect=%{time_connect} start=%{time_starttransfer}\n" "https://YOUR_DOMAIN/loki/merikortti/tiles/traficom_rannikkokartat_public/14/9229/4773.png"
curl -s -o /dev/null -w "code=%{http_code} total=%{time_total} connect=%{time_connect} start=%{time_starttransfer}\n" "https://YOUR_DOMAIN/loki/merikortti/tiles/traficom_rannikkokartat_public/14/999999/999999.png"
```

### G) Quick interpretation

- If php8.2-fpm logs show max_children reached, increase FPM pool capacity.
- If Apache logs show worker limits/timeouts, tune Apache worker capacity and keepalive behavior.
- If misses are slow but workers are free, upstream Traficom latency is likely dominating.
- If protocol is still HTTP/1.1, enabling HTTP/2 reduces browser-side request queueing.
