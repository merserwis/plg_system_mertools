FROM php:8.5.12-fpm-trixie
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      apache2 libavif-dev libwebp-dev libjpeg62-turbo-dev libpng-dev libfreetype-dev libzip-dev libicu-dev libgmp-dev libbz2-dev \
      libjxl-tools libavif-bin \
 && docker-php-ext-configure gd --with-avif --with-webp --with-jpeg --with-freetype \
 && docker-php-ext-install -j"$(nproc)" gd mysqli pdo_mysql zip intl gmp bz2 \
 && a2enmod proxy_fcgi rewrite headers expires deflate && a2dissite 000-default \
 && rm -rf /var/lib/apt/lists/*
COPY site.conf /etc/apache2/sites-enabled/site.conf
COPY php-test.ini /usr/local/etc/php/conf.d/zz-test.ini
CMD ["sh", "-c", "php-fpm -D && exec apache2ctl -D FOREGROUND"]
