FROM php:8.2-fpm-alpine

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 1. ვაინსტალირებთ ბიბლიოთეკებს (დაემატა sqlite-dev)
RUN apk update && apk add --no-cache \
    nginx \
    supervisor \
    sqlite-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libzip-dev \
    libxml2-dev \
    icu-dev \
    $PHPIZE_DEPS

# 2. PHP გაფართოებების დაყენება (დაემატა pdo_sqlite)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install -j$(nproc) \
    gd \
    zip \
    opcache \
    intl \
    exif \
    pdo \
    pdo_sqlite

# 3. PHP კონფიგურაცია
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    echo "upload_max_filesize = 20M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size = 20M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini

# 4. საქაღალდეების შექმნა (db საქაღალდე აუცილებელია SQLite-ისთვის)
RUN mkdir -p /var/www/html/db /var/log/php /run/nginx \
    && touch /var/log/php/error.log \
    && chown -R www-data:www-data /var/www/html /var/log/php \
    && chmod -R 777 /var/www/html/db

COPY nginx.conf /etc/nginx/nginx.conf
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /var/www/html
COPY . .

# უფლებების მიცემა database ფაილისთვის
RUN chown -R www-data:www-data /var/www/html

ENV PORT=8080
EXPOSE 8080

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]