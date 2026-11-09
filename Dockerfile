FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    nginx \
    supervisor \
    git \
    unzip \
    libonig-dev \
    && docker-php-ext-install \
    pdo_mysql \
    mbstring \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . /app

RUN cd /app/backend \
    && composer install \
       --no-dev \
       --optimize-autoloader \
       --no-interaction

COPY docker/nginx.conf /etc/nginx/nginx.conf

COPY docker/supervisord.conf \
    /etc/supervisor/conf.d/supervisord.conf

RUN mkdir -p /run/nginx /var/log/supervisor

EXPOSE 10000

CMD ["supervisord", "-n", "-c", \
     "/etc/supervisor/conf.d/supervisord.conf"]