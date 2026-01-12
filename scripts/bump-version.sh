#!/bin/bash
set -eux

if [ "$(uname -s)" != "Linux" ]; then
    echo "Please use the GitHub Action."
    exit 1
fi

# Ensure we are in the root directory of the project
SCRIPT_DIR="$( dirname "$0" )"
cd $SCRIPT_DIR/..

# Get the old and new version from the command line arguments
OLD_VERSION="${1}"
NEW_VERSION="${2}"

# Run a new phar build with the tagged version
./scripts/build.sh $NEW_VERSION
