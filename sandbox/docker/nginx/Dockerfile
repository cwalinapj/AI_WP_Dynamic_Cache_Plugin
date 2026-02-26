FROM nginx:1.25-alpine

RUN rm /etc/nginx/conf.d/default.conf

COPY nginx.conf /etc/nginx/conf.d/wordpress.conf

RUN mkdir -p /tmp/nginx_cache && chown -R nginx:nginx /tmp/nginx_cache

EXPOSE 80
