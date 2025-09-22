#!/bin/bash

set -e

# Move into the agent directory
cd agent

# Force the root version to allow for reproducible builds (we use 0.0.0 so it's clear this is not a real version)
export COMPOSER_ROOT_VERSION=0.0.0

# Install composer dependencies (without dev dependencies since they are not needed in a production build)
composer install --prefer-dist --no-interaction --no-progress --no-dev

# Build the project using Box
box compile

# Export the signature of the built binary
echo $(box info:signature ../bin/sentry-agent) > ../bin/sentry-agent.sig

# Reinstall dependencies for development
composer install
