FROM docker:27.5.1-cli@sha256:f56779b4e86550493153cc8642c9c8e40b5d934e43cb5b4ea463aea5245c5c01 AS docker-cli
FROM php:8.3-apache-bookworm@sha256:c0c560dab1bcd301960148d726701954ff83f40dceb9b678fa3078bcc3c4f208

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libicu-dev libonig-dev libpq-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" intl mbstring opcache pdo_mysql pdo_pgsql zip \
    && a2enmod expires headers rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=docker-cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker-cli /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose
COPY . /var/www/html
COPY docker/openconcept/entrypoint.sh /usr/local/bin/openconcept-entrypoint

RUN chmod 0755 /usr/local/bin/openconcept-entrypoint \
    && mkdir -p /opt/openconcept-seed /var/www/html/storage /var/www/html/published \
    && cp /var/www/html/storage/.htaccess /opt/openconcept-seed/storage.htaccess \
    && cp /var/www/html/published/.htaccess /opt/openconcept-seed/published.htaccess \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/published

ENTRYPOINT ["openconcept-entrypoint"]
CMD ["apache2-foreground"]
