# LocalRouter — imagem mínima para produção.
# PHP 8.2-cli + ext-curl (única extensão além das bundled). Sem Composer,
# sem build, sem servidor web: o servidor embutido do PHP basta porque o
# gateway é uma API stateless por trás de um reverse proxy em produção.

FROM php:8.2-cli

# ext-curl precisa de libcurl-dev; --with-curl vem bundled no PHP source,
# mas o docker-php-ext-install precisa do header do sistema.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

# O servidor embutido escuta em 0.0.0.0:8000 por padrão.
EXPOSE 8000

WORKDIR /app

# Copia só o necessário: config, entrypoint e src/. A pasta data/ é volume
# (montada em runtime) — nunca bakear chaves na imagem.
COPY config.php index.php robots.txt ./
COPY src/ ./src/

# data/ precisa existir para o volume montado não criar como root-owned.
RUN mkdir -p data

# -t 0.0.0.0:8000 escuta em todas as interfaces (necessário fora do host).
# -S sobe o servidor embutido. docroot = /app faz /health, /models etc.
# funcionarem sem rewrite.
CMD ["php", "-S", "0.0.0.0:8000", "-t", "/app"]