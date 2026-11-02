# syntax=docker/dockerfile:1.7
# check=error=true
#
# Moodle pour Data For Good : l'image PHP + Apache de Moodle HQ, le code de
# Moodle à un tag épinglé, et les deux plugins hors distribution que le site
# utilise. Remplace bitnami/moodle:4.5, retirée de Docker Hub (DCM2-26).
#
# Le code est DANS l'image et n'est jamais écrit à l'exécution : monter Moodle
# ou un plugin, c'est changer une épingle ici, reconstruire, et laisser
# `admin/cli/upgrade.php` (l'initContainer de d4g-gitops) migrer la base.
# Seul /var/www/moodledata est un volume.

FROM docker.io/moodlehq/moodle-php-apache:8.3-bookworm@sha256:1db7b6ed013fcb5268095b5e28c410ba9117d38d9b6a324c100c48fcbe69745e

LABEL org.opencontainers.image.source="https://github.com/dataforgoodfr/d4g-moodle"
LABEL org.opencontainers.image.description="Moodle et ses plugins, pour Data For Good"
LABEL org.opencontainers.image.licenses="GPL-3.0-or-later"

# La branche 4.5 (LTS) : le site tournait en 4.5.0. 5.x déplace le code sous
# public/ et demande une autre disposition : une carte à part.
ARG MOODLE_REF=v4.5.15
ADD --keep-git-dir=false https://github.com/moodle/moodle.git#${MOODLE_REF} /var/www/html

# Le thème du site, à la version qu'il avait sous bitnami (7.0.13) : le commit
# amont, faute de tag. Le monter change l'apparence du site — à relire avant.
ARG BOOST_MAGNIFIC_REF=10be396cd02871dcbe43f0e6e350e5346d8c9b51
ADD --keep-git-dir=false https://github.com/EduardoKrausME/moodle-theme_boost_magnific.git#${BOOST_MAGNIFIC_REF} /var/www/html/theme/boost_magnific

# Le journal « Learning Analytics » (logstore_lanalytics), installé sur le site.
ARG LANALYTICS_REF=v0.10.0
ADD --keep-git-dir=false https://github.com/rwthanalytics/moodle-logstore_lanalytics.git#${LANALYTICS_REF} /var/www/html/admin/tool/log/store/lanalytics

COPY config.php /var/www/html/config.php

# Ce que l'entrypoint de l'image de base fait au démarrage, fait ici une fois
# pour toutes : il écrit dans /usr/local/etc et /etc/apache2, ce qu'un conteneur
# lancé en www-data ne peut pas. On démarre donc par l'entrypoint PHP officiel,
# sans lui.
#
# - Apache sur 8080 : 80 est refusé à un utilisateur non root.
# - Les répertoires d'exécution d'Apache à www-data, pour la même raison.
# - memory_limit : 128M par défaut dans l'image PHP, 256M sous bitnami.
ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf && \
    sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf && \
    mkdir -p /var/run/apache2 /var/lock/apache2 /var/log/apache2 && \
    chown -R www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2 && \
    printf 'memory_limit = 256M\n' > /usr/local/etc/php/conf.d/30-d4g.ini

USER www-data
EXPOSE 8080
ENTRYPOINT ["docker-php-entrypoint"]
CMD ["apache2-foreground"]
