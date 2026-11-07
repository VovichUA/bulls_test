FROM php:8.4-fpm

# Системні залежності, потрібні для розширень PHP та Composer
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        mbstring \
        zip \
        gd \
        bcmath \
        pcntl \
        dom \
        xml \
        xmlwriter \
    && rm -rf /var/lib/apt/lists/*

# Встановлення xDebug через PECL
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && mkdir -p /tmp/xdebug \
    && chmod 1777 /tmp/xdebug

# Конфігурація xDebug (окремий файл, щоб не лізти в основний php.ini)
COPY xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini
COPY php.ini /usr/local/etc/php/conf.d/custom.ini

# Встановлення Composer (копіюємо офіційний бінарник з офіційного образу composer)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

EXPOSE 9000

CMD ["php-fpm"]
