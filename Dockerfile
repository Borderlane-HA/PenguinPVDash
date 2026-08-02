FROM php:8.3-apache

ARG PVDASH_VERSION=1.8.0
LABEL org.opencontainers.image.title="PenguinPVDash" \
      org.opencontainers.image.description="Shareable photovoltaic dashboard for Home Assistant" \
      org.opencontainers.image.source="https://github.com/Borderlane-HA/PenguinPVDash" \
      org.opencontainers.image.version="${PVDASH_VERSION}"

ENV PVDASH_VERSION=${PVDASH_VERSION}

COPY docker/apache-pvdash.conf /etc/apache2/conf-available/penguinpvdash.conf
COPY docker/php-pvdash.ini /usr/local/etc/php/conf.d/penguinpvdash.ini

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && a2enmod headers expires \
    && a2enconf penguinpvdash \
    && rm -rf /var/lib/apt/lists/*

COPY SERVER/ /var/www/html/
COPY docker-entrypoint.sh /usr/local/bin/pvdash-entrypoint

RUN chmod +x /usr/local/bin/pvdash-entrypoint \
    && mkdir -p /var/lib/penguinpvdash \
    && chown -R www-data:www-data /var/www/html /var/lib/penguinpvdash

ENV PVDASH_SQLITE=/var/lib/penguinpvdash/pvdash.sqlite
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD curl --fail --silent http://127.0.0.1/health.php >/dev/null || exit 1

ENTRYPOINT ["pvdash-entrypoint"]
CMD ["apache2-foreground"]
