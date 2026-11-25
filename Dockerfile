FROM php:8.2-apache-bookworm

RUN docker-php-ext-install pdo_mysql \
    && php -r 'foreach (["pdo_mysql", "curl", "mbstring", "openssl"] as $name) { if (!extension_loaded($name)) { exit(1); } }'
WORKDIR /opt/agent-automation
COPY bootstrap.php VERSION ./
COPY bin ./bin
COPY src ./src
COPY database ./database
COPY public ./public
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
