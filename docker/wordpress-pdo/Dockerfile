FROM wordpress:6.4-php8.2-apache

# Required: the store API speaks PDO MySQL (the stock image ships pdo_sqlite only).
RUN set -eux; \
    docker-php-ext-install pdo_mysql

# Optional niceties for WP media handling; skipped if the toolchain disagrees.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpng-dev libjpeg-dev libfreetype6-dev libzip-dev; \
    (docker-php-ext-configure gd --with-freetype --with-jpeg \
     && docker-php-ext-install gd zip bcmath opcache) || echo "optional extensions skipped"; \
    rm -rf /var/lib/apt/lists/*
