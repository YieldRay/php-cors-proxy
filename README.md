# php-cors-proxy

cors proxy written in php, using built-in curl

```js
/**
 * assume you deploy the site on http://localhost:8080
 */

// php rewrite off
fetch("http://localhost:8080/index.php/http://example.net");
// php rewrite on
fetch("http://localhost:8080/https://example.net");
// omit scheme, by default use http
fetch("http://localhost:8080/example.net");
```

## develop

```sh
php -S 0.0.0.0:8080 index.php
```

## nginx config example

> ignore this if you use apache

```nginx
location / {
        if (!-e $request_filename) {
            rewrite  ^/(\w+)/(.*)$  /$1/index.php/$2  last;
        }
}
```

## apache config example

> ignore this if you use `.htaccess` in this repo

```htaccess
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^/(\w+)/(.*)$ /$1/index.php/$2 [L,E=PATH_INFO:$2]
</IfModule>
```
