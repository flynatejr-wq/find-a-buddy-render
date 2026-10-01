FROM php:8.3-apache

RUN docker-php-ext-install pdo pdo_mysql pdo_pgsql

COPY . /var/www/html/

RUN echo '<?php header("Location: frontend/index.html"); exit; ?>' > /var/www/html/index.php

RUN printf '#!/bin/bash\nset -e\nsed -i "s/80/$PORT/g" /etc/apache2/ports.conf /etc/apache2/sites-enabled/000-default.conf\napache2-foreground\n' > /entrypoint.sh \
    && chmod +x /entrypoint.sh

EXPOSE 80

CMD ["/entrypoint.sh"]
