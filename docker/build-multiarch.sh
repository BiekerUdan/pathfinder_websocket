#!/bin/bash

# Build script for multi-architecture Docker images
# Builds for both amd64 and arm64 platforms

set -e

IMAGE_NAME="pathfinder-websocket"
TAG="php8.4"

echo "Building multi-architecture Docker image: ${IMAGE_NAME}:${TAG}"
echo "Platforms: linux/amd64, linux/arm64"
echo ""

# Create and use a new builder instance if it doesn't exist
if ! docker buildx ls | grep -q multiarch-builder; then
    echo "Creating new buildx builder instance..."
    docker buildx create --name multiarch-builder --use
else
    echo "Using existing buildx builder instance..."
    docker buildx use multiarch-builder
fi

# Bootstrap the builder
docker buildx inspect --bootstrap

# Build and push for both architectures
# Note: Add --push flag to push to a registry, or use --load for local (single arch only)
docker buildx build \
    --platform linux/amd64,linux/arm64 \
    --file docker/Dockerfile \
    --tag ${IMAGE_NAME}:${TAG} \
    --tag ${IMAGE_NAME}:latest \
    .

echo ""
echo "Build complete!"
echo "Image: ${IMAGE_NAME}:${TAG}"
echo ""
echo "To push to a registry, add --push flag to the build command"
echo "To load locally (single arch), use: docker buildx build --platform linux/amd64 --load ..."
