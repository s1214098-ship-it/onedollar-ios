FROM swift:6.1.2-noble

# The official Swift image is based on Ubuntu 24.04 (noble) and ships the Swift
# 6.1.2 toolchain plus swift-corelibs-foundation, which is all the repository's
# Swift sources need. git and curl are added for repository and tooling use.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*
