#!/usr/bin/env bash
# Idempotent Cloud Agent setup for the OneDollarIOS repository.
#
# OneDollarIOS is an iOS/Xcode app whose full build only runs on macOS (see
# codemagic.yaml). Cloud Agents run on Linux x86_64, so this environment provides
# the Linux Swift toolchain (compiler, Swift Package Manager, and the bundled
# swift-format) so agents can compile, type-check, and lint the platform
# independent Swift sources in this repo. Xcode / iOS-SDK builds still happen in
# Codemagic on a Mac.
set -euo pipefail

SWIFT_VERSION="6.3.1"
SWIFT_HOME="/opt/swift"
SWIFT_BIN="${SWIFT_HOME}/usr/bin"

log() { printf '\n=== %s ===\n' "$1"; }

log "Ensuring system dependencies for the Swift toolchain"
sudo apt-get update -qq
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
  binutils git gnupg2 libc6-dev libcurl4-openssl-dev libedit2 libgcc-13-dev \
  libpython3-dev libsqlite3-0 libstdc++-13-dev libxml2-dev libz3-dev pkg-config \
  tzdata unzip zlib1g-dev libncurses-dev

if [ ! -x "${SWIFT_BIN}/swift" ]; then
  log "Installing Swift ${SWIFT_VERSION} toolchain to ${SWIFT_HOME}"
  url="https://download.swift.org/swift-${SWIFT_VERSION}-release/ubuntu2404/swift-${SWIFT_VERSION}-RELEASE/swift-${SWIFT_VERSION}-RELEASE-ubuntu24.04.tar.gz"
  tmp="$(mktemp -d)"
  curl -fL "${url}" -o "${tmp}/swift.tar.gz"
  sudo rm -rf "${SWIFT_HOME}"
  sudo mkdir -p "${SWIFT_HOME}"
  sudo tar -xzf "${tmp}/swift.tar.gz" -C "${SWIFT_HOME}" --strip-components=1
  rm -rf "${tmp}"
else
  log "Swift toolchain already present at ${SWIFT_HOME}; skipping download"
fi

log "Exposing Swift on PATH"
echo 'export PATH=/opt/swift/usr/bin:$PATH' | sudo tee /etc/profile.d/swift.sh >/dev/null
sudo chmod +x /etc/profile.d/swift.sh
for b in swift swiftc sourcekit-lsp swift-format; do
  if [ -e "${SWIFT_BIN}/${b}" ]; then
    sudo ln -sf "${SWIFT_BIN}/${b}" "/usr/local/bin/${b}"
  fi
done

log "Verifying toolchain"
"${SWIFT_BIN}/swift" --version
"${SWIFT_BIN}/swift-format" --version

log "Smoke test: type-checking repository Swift sources"
mapfile -t swift_sources < <(find OneDollarIOS -name '*.swift' -type f 2>/dev/null || true)
if [ "${#swift_sources[@]}" -gt 0 ]; then
  "${SWIFT_BIN}/swiftc" -typecheck -parse-as-library "${swift_sources[@]}"
  echo "Type-check passed for: ${swift_sources[*]}"
else
  echo "No Swift sources found under OneDollarIOS/ (nothing to type-check)."
fi

log "Cloud Agent environment ready"
