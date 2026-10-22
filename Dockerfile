# A floating tag on purpose: Docker Hub rebuilds it for every PHP 8.5 release,
# so `docker compose build --pull` is all it takes to get a security fix. See
# "Keeping a site patched" in SECURITY.md.
FROM php:8.5-fpm-alpine

RUN apk add --no-cache nginx supervisor

# Don't advertise the PHP version in response headers.
RUN echo "expose_php = Off" > /usr/local/etc/php/conf.d/hardening.ini

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
