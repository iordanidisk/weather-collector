FROM php:8.3-apache

# PostgreSQL driver + pcntl (graceful shutdown of the scheduler)
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq5 libpq-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl \
 && apt-get purge -y --auto-remove libpq-dev \
 && rm -rf /var/lib/apt/lists/*

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'date.timezone=UTC\nexpose_php=Off\n' > "$PHP_INI_DIR/conf.d/app.ini"

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
RUN a2enmod headers >/dev/null

WORKDIR /app
COPY composer.json ./
COPY bin ./bin
COPY config ./config
COPY migrations ./migrations
COPY public ./public
COPY src ./src
COPY tests ./tests
RUN chmod +x bin/console

# Same image runs the API (default), the scheduler and one-off CLI commands.
EXPOSE 80
CMD ["apache2-foreground"]
