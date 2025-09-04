#!/bin/bash

set -e

# Move into the agent directory
cd agent

# Install composer dependencies
composer install --prefer-dist --no-interaction --no-progress --no-dev

# Build the project using Box
box compile

# Export the signature of the built binary
echo $(box info:signature ../bin/sentry-agent) > ../bin/sentry-agent.sig

# Reinstall dependencies for development
composer install
