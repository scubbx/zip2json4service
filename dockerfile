FROM golang:1.24-alpine AS build

WORKDIR /src
COPY go.mod go.sum* ./
RUN go mod download

COPY . .
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /app/umap-geojson-proxy .

FROM alpine:3.22

RUN adduser -D -H appuser
USER appuser

COPY --from=build /app/umap-geojson-proxy /umap-geojson-proxy

EXPOSE 8080
ENTRYPOINT ["/umap-geojson-proxy"]