FROM golang:1.26-alpine AS build

WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /threadline .

FROM alpine:3.22

RUN apk add --no-cache ca-certificates tzdata \
    && addgroup -S threadline \
    && adduser -S -G threadline threadline

WORKDIR /app
COPY --from=build /threadline /usr/local/bin/threadline
RUN mkdir -p /app/public/uploads && chown -R threadline:threadline /app

USER threadline
EXPOSE 8080

CMD ["threadline"]
