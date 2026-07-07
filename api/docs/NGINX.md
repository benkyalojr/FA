# nginx / production deployment notes

## Photo uploads failing (301 / 403)

**Symptom:** Mobile app or `curl -F file=@photo.jpg …/api/uploads` returns HTML `301 Moved Permanently` then `403 Forbidden`.

**Cause:** A physical directory `api/uploads/` on disk. nginx treats `/api/uploads` as a folder, redirects to `/api/uploads/`, and **blocks POST** to a static directory.

**Fix:**

```bash
cd /path/to/FA
rm -rf api/uploads          # never create this folder
mkdir -p api/storage api/logs
chmod 775 api/storage api/logs
chown www-data:www-data api/storage api/logs   # adjust user
php api/tools/diagnose_uploads.php
```

**Mobile workaround (no server change to nginx routing):** use **`POST /api/media`** instead of `/api/uploads` (same handler, added to avoid the directory name clash).

---

## Sample nginx location block

```nginx
location /api/ {
    alias /home/benito/FA/api/;

    # Serve uploaded images directly from disk
    location ~ ^/api/storage/(.+)$ {
        alias /home/benito/FA/api/storage/$1;
        add_header Cache-Control "private, max-age=86400";
    }

    # Everything else → PHP front controller
    location ~ ^/api/(?!storage/) {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /home/benito/FA/api/index.php;
        fastcgi_param PATH_INFO $uri;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        client_max_body_size 20M;
    }
}
```

Adjust paths, PHP socket, and `client_max_body_size` (must exceed your largest photo).

---

## PHP limits

In `php.ini` or a pool snippet:

```ini
file_uploads = On
upload_max_filesize = 20M
post_max_size = 22M
```

---

## Verify

```bash
TOKEN=$(curl -s -X POST https://avogsdev.example/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"apiuser","password":"apiuser"}' | jq -r .token)

curl -s -X POST "https://avogsdev.example/api/media" \
  -H "Authorization: Bearer $TOKEN" \
  -F "file=@test.jpg"
# Expect 201: {"upload_id":"upl_...","url":"https://.../api/storage/upl_....jpg"}
```

Check `api/logs/api.log` for `Upload stored` or error lines.
