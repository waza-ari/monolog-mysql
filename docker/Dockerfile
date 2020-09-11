#
# PHP Setup
#
FROM php:7.2.31-cli

#
# Install dependencies
#

RUN set -xe; \
    apt-get update && \
    apt-get install -y \
        curl \
        zip \
        zlib1g-dev \
        libzip-dev \
        libicu-dev && \
    pecl install \
        xdebug && \
    docker-php-ext-install \
        zip \
        intl \
        pdo \
        pdo_mysql && \
    docker-php-ext-enable \
        xdebug && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/* \
           /tmp/* \
           /var/tmp/* \
           /var/log/lastlog \
           /var/log/faillog

#
# Workspace User
#

ARG APP_USER_ID=1000
ARG APP_GROUP_ID=1000

RUN set -xe; \
    groupadd -f workspace && \
    groupmod -g ${APP_GROUP_ID} workspace && \
    useradd workspace -g workspace && \
    mkdir -p /home/workspace && chmod 755 /home/workspace && chown workspace:workspace /home/workspace && \
    usermod -u ${APP_USER_ID} -m -d /home/workspace workspace -s $(which bash) && \
    chown -R workspace:workspace /var/www/html

#
# Set Timezone
#

ARG TIME_ZONE='Asia/Seoul'

RUN ln -snf /usr/share/zoneinfo/${TIME_ZONE} /etc/localtime && echo ${TIME_ZONE} > /etc/timezone

#
# Composer Setup
#

ARG COMPOSER_VERSION=1.10.10
ARG COMPOSER_REPO_PACKAGIST='https://packagist.jp'

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer --version=${COMPOSER_VERSION} && \
    composer config -g repos.packagist composer ${COMPOSER_REPO_PACKAGIST} && \
    composer global require hirak/prestissimo --no-interaction

WORKDIR /var/www/html
