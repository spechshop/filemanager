FROM alpine:3.20
RUN apk add --no-cache bash coreutils util-linux procps nodejs npm curl openssl ca-certificates python3 iptables \
    && adduser -D -u 1000 restricted \
    && rm -f /usr/bin/crontab
# PHP is supplied read-only by the runner; application executes as uid 1000.
USER 1000:1000
WORKDIR /
