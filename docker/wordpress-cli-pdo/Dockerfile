FROM wordpress:cli-php8.2

# The store activator runs PDO migrations inside WP-CLI commands.
USER root
RUN docker-php-ext-install pdo_mysql
USER www-data
