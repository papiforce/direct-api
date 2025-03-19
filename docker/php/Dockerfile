FROM php:8.2-fpm

# Installation des dépendances système
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libicu-dev \
    libzip-dev

# Installation des extensions PHP nécessaires pour une API
RUN docker-php-ext-install \
    pdo_pgsql \
    intl \
    zip

# Extension optionnelle pour l'amélioration des performances
RUN pecl install apcu && docker-php-ext-enable apcu

# Installation de Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Définir le répertoire de travail
WORKDIR /var/www/symfony

# Créer la structure de dossiers pour améliorer les permissions
RUN mkdir -p var/cache var/log

# Exposer le port pour le serveur web intégré de Symfony
EXPOSE 8000