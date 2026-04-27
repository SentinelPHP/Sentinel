ARG BASE_IMAGE
FROM ${BASE_IMAGE}

ARG SWOOLE_VERSION=6.0.0
ARG uid=1000
ARG gid=1000

# Ensure user exists in passwd database (fixes DDEV user mapping issue)
RUN if ! getent passwd ${uid} > /dev/null 2>&1; then \
        groupadd -g ${gid} ddevuser || true; \
        useradd -u ${uid} -g ${gid} -m -s /bin/bash ddevuser || true; \
    fi

# Install dependencies and build tools for Swoole
RUN apt-get update && apt-get install -y \
    php8.4-dev \
    libcurl4-openssl-dev \
    libssl-dev \
    inotify-tools \
    && rm -rf /var/lib/apt/lists/*

# Install Swoole from source
RUN cd /tmp \
    && curl -sSL https://github.com/swoole/swoole-src/archive/refs/tags/v${SWOOLE_VERSION}.tar.gz | tar xz \
    && cd swoole-src-${SWOOLE_VERSION} \
    && phpize \
    && ./configure --enable-openssl --enable-hook-curl \
    && make -j$(nproc) \
    && make install \
    && cd / \
    && rm -rf /tmp/swoole-src-${SWOOLE_VERSION}

# Enable Swoole extension for all PHP versions
RUN echo "extension=swoole.so" > /etc/php/8.4/mods-available/swoole.ini \
    && echo "swoole.use_shortname = Off" >> /etc/php/8.4/mods-available/swoole.ini \
    && ln -sf /etc/php/8.4/mods-available/swoole.ini /etc/php/8.4/cli/conf.d/20-swoole.ini \
    && ln -sf /etc/php/8.4/mods-available/swoole.ini /etc/php/8.4/fpm/conf.d/20-swoole.ini
