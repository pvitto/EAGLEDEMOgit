# 1. Usar una imagen oficial de PHP 8.0 con servidor Apache
FROM php:8.0-apache

# 2. Instalar las extensiones de PHP que necesitamos (PDO y PostgreSQL)
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

# 3. Instalar Composer (el gestor de paquetes de PHP)-
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4. Copiar todos los archivos de tu proyecto al servidor web
COPY . /var/www/html/

# 5. Establecer el directorio de trabajo
WORKDIR /var/www/html

# 6. Correr "composer install" para instalar phpmailer
RUN composer install

# 7. (Opcional pero recomendado) Arreglar permisos
RUN chown -R www-data:www-data /var/www/html
