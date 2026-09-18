#!/bin/sh

set -eu

project_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
repo_dir=$(CDPATH= cd -- "$project_dir/../.." && pwd)

if [ -z "${PHPX_HOME:-}" ]; then
    echo "Set PHPX_HOME to the PHPX checkout containing both iOS SDK prefixes." >&2
    exit 2
fi

case "${1:-}" in
    device)
        config=ios.yml
        package_script=package-ios-app.sh
        ;;
    simulator)
        config=ios-simulator.yml
        package_script=package-ios-simulator-app.sh
        ;;
    *)
        echo "Usage: $0 <device|simulator>" >&2
        exit 2
        ;;
esac

cd "$repo_dir"
php bin/tpc.php "examples/apple-native/$config" --no-progress

if [ "$package_script" = package-ios-app.sh ] &&
    { [ -z "${TYPEPHP_IOS_PROVISIONING_PROFILE:-}" ] || [ -z "${TYPEPHP_IOS_CODE_SIGN_IDENTITY:-}" ]; }; then
    echo "Device executable built. Set the provisioning profile and signing identity, then run $project_dir/$package_script to package it."
else
    sh "$project_dir/$package_script"
fi
