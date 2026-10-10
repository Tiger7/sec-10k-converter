FROM php:8.3-apache

# PHP 확장 빌드에 필요한 시스템 라이브러리
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        unzip \
        git \
    && docker-php-ext-install -j$(nproc) \
        curl \
        mbstring \
        dom \
        zip \
        xml \
    && rm -rf /var/lib/apt/lists/*

# Composer 실행 파일
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /opt/sec-10k-converter

# lock 파일이 있으면 해당 버전으로 의존성 설치
COPY composer.* ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress

# 프로젝트 소스 복사
COPY . .

# 필요한 소스 파일이 실제로 이미지에 포함되었는지 확인
RUN test -f app.php \
    && test -f worker.php \
    && test -f public/index.php

# 소스는 root 소유, www-data 그룹이 읽을 수 있도록 설정
# 폴더에는 경로 탐색 권한이 필요하고, PHP 파일에는 읽기 권한이 필요합니다.
RUN chown -R root:www-data /opt/sec-10k-converter \
    && find /opt/sec-10k-converter -type d -exec chmod 750 {} \; \
    && find /opt/sec-10k-converter -type f -exec chmod 640 {} \; \
    && mkdir -p storage/cache storage/jobs storage/output logs \
    && chown -R www-data:www-data storage logs \
    && find storage logs -type d -exec chmod 2770 {} \; \
    && find storage logs -type f -exec chmod 660 {} \;

# PHP 설정
RUN printf '%s\n' \
    'memory_limit=768M' \
    'max_execution_time=300' \
    'display_errors=Off' \
    'log_errors=On' \
    > /usr/local/etc/php/conf.d/sec10k.ini

# 프로젝트의 public 폴더만 웹에 공개
RUN printf '%s\n' \
    'Alias /sec10k/ /opt/sec-10k-converter/public/' \
    '<Directory /opt/sec-10k-converter/public>' \
    'Require all granted' \
    'Options -Indexes' \
    'AllowOverride None' \
    'DirectoryIndex index.php' \
    '</Directory>' \
    > /etc/apache2/conf-available/sec10k.conf \
    && a2enconf sec10k
