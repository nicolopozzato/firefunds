#!/usr/bin/env bash
# Builds the signed APK in a container (no Android SDK needed here) and puts
# it where the web app offers it for download (Preferences → API).
#
#   android/build.sh
#
# Needs signing.env and the keystore it names (private: see README.md);
# without them the APK is left unsigned.
set -euo pipefail
cd "$(dirname "$0")"

IMAGE=ghcr.io/cirruslabs/android-sdk:35
mkdir -p .gradle-home
docker run --rm -u "$(id -u):$(id -g)" \
	-e GRADLE_USER_HOME=/app/.gradle-home -e HOME=/tmp \
	-v "$PWD":/app -w /app "$IMAGE" \
	./gradlew --no-daemon -q assembleRelease

mkdir -p ../frontend/public/downloads
cp app/build/outputs/apk/release/app-release.apk ../frontend/public/downloads/firefunds.apk
echo "frontend/public/downloads/firefunds.apk ($(du -h ../frontend/public/downloads/firefunds.apk | cut -f1))"
