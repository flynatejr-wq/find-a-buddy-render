# Stage 1: install the PHP libraries (the Anthropic SDK for the AI assistant). Dev tools are left out.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs

# Stage 2: the web server.
FROM php:8.3-apache

RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/
COPY --from=vendor /app/vendor /var/www/html/vendor

RUN echo '<?php header("Location: frontend/index.html"); exit; ?>' > /var/www/html/index.php

RUN printf '#!/bin/bash\nset -e\nsed -i "s/80/$PORT/g" /etc/apache2/ports.conf /etc/apache2/sites-enabled/000-default.conf\napache2-foreground\n' > /entrypoint.sh \
    && chmod +x /entrypoint.sh

EXPOSE 80

CMD ["/entrypoint.sh"]
