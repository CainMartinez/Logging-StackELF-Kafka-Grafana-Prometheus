# Etapa de compilación
FROM php:8.2-fpm-alpine AS builder

# Instalar dependencias de compilación
RUN apk add --no-cache \
    libzip-dev \
    icu-dev \
    libpq-dev \
    oniguruma-dev \
    autoconf \
    gcc \
    g++ \
    make \
    bash \
    # Dependencias para Kafka
    librdkafka-dev \
    openssl-dev

# Instalar y configurar extensiones PHP
RUN docker-php-ext-install -j$(nproc) pdo pdo_mysql zip intl opcache \
    && pecl install redis \
    && pecl install apcu-5.1.21 \
    && pecl install rdkafka \
    && docker-php-ext-enable redis apcu rdkafka \
    && echo "apc.enable_cli=1" >> /usr/local/etc/php/conf.d/apcu.ini

# Instalar Composer de forma segura
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copiar solo archivos de composer e instalar dependencias
COPY composer.* ./
RUN composer install --no-dev --prefer-dist --no-scripts --no-interaction --optimize-autoloader

# Copiar solo los directorios necesarios
COPY bin bin/
COPY config config/
COPY public public/
COPY src src/
COPY templates templates/
COPY .env .env

# Etapa final (solo runtime)
FROM php:8.2-fpm-alpine

# Instalar solo dependencias de runtime (NO herramientas de compilación)
RUN apk add --no-cache \
    libzip \
    icu \
    oniguruma \
    # Runtime de librdkafka para Kafka
    librdkafka \
    openssl

# Configurar PHP para producción
RUN docker-php-ext-install -j$(nproc) pdo pdo_mysql opcache \
    && echo "opcache.memory_consumption=256" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.max_accelerated_files=20000" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.validate_timestamps=0" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "realpath_cache_size=4096K" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "realpath_cache_ttl=600" >> /usr/local/etc/php/conf.d/opcache.ini

# Copiar extensiones compiladas de la etapa anterior
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Cambiamos el directorio de trabajo para consistencia con docker-compose
WORKDIR /var/www/Symfony-7.2

# Copiar archivos de la aplicación
COPY --from=builder /app/bin bin/
COPY --from=builder /app/config config/
COPY --from=builder /app/public public/
COPY --from=builder /app/src src/
COPY --from=builder /app/templates templates/
COPY --from=builder /app/vendor vendor/
COPY --from=builder /app/.env .env

# Crear directorios necesarios con permisos correctos
RUN mkdir -p var/cache var/log \
    && chown -R www-data:www-data var \
    && chmod -R 755 var

# Exponer el puerto para el servidor web integrado de PHP
EXPOSE 8000

# Usar un usuario no privilegiado
USER www-data

# Iniciar el servidor web integrado de PHP
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public/"]