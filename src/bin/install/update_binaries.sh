#!/bin/bash
set -euo pipefail

OWNER="${1:-Vateron-Media}"
REPO="${2:-XC_VM_Binaries}"
TARGET_BIN_DIR="${3:-/home/xc_vm/bin}"
TARGET_RELEASE_TAG="${4:-}"
VERSION_FILE="${TARGET_BIN_DIR}/bin_version.json"
SERVICE_NAME="${XD_SERVICE_NAME:-xc_vm}"
ALLOW_LATEST="${XD_ALLOW_LATEST:-0}"
ALLOW_LEGACY_MD5="${XD_ALLOW_LEGACY_MD5:-0}"

TMP_ROOT=""
EXTRACT_DIR=""
STAGE_DIR=""
BACKUP_DIR=""
ROLLBACK_REQUIRED=0
SERVICE_STOPPED=0

INSTALLED_PATHS=()
BACKED_UP_COMPONENTS=()

fail() {
    echo "ERROR: $*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "Required command not found: $1"
}

is_binary_root() {
    local path="$1"
    [[ -d "$path/nginx" && -d "$path/nginx_rtmp" && -d "$path/php" ]]
}

rollback_update() {
    local i dest

    for ((i=${#INSTALLED_PATHS[@]}-1; i>=0; i--)); do
        dest="${INSTALLED_PATHS[$i]}"
        [[ -e "$TARGET_BIN_DIR/$dest" ]] && rm -rf -- "$TARGET_BIN_DIR/$dest"
    done

    for dest in "${BACKED_UP_COMPONENTS[@]}"; do
        if [[ -e "$BACKUP_DIR/$dest" ]]; then
            [[ -e "$TARGET_BIN_DIR/$dest" ]] && rm -rf -- "$TARGET_BIN_DIR/$dest"
            mv -- "$BACKUP_DIR/$dest" "$TARGET_BIN_DIR/$dest"
        fi
    done
}

cleanup() {
    local exit_code="$1"

    if [[ "$exit_code" -ne 0 && "$ROLLBACK_REQUIRED" -eq 1 ]]; then
        echo "Update failed, rolling back binaries" >&2
        rollback_update
    fi

    [[ -n "$TMP_ROOT" && -d "$TMP_ROOT" ]] && rm -rf -- "$TMP_ROOT"

    if [[ "$SERVICE_STOPPED" -eq 1 ]]; then
        echo "Starting service: ${SERVICE_NAME}"
        systemctl start "$SERVICE_NAME" || echo "Warning: Failed to start service: ${SERVICE_NAME}" >&2
    fi
}

validate_archive_members() {
    local archive="$1" member

    while IFS= read -r member; do
        [[ -z "$member" ]] && continue

        case "$member" in
            /*|../*|*/../*|*/..)
                fail "Unsafe archive member detected: $member"
                ;;
        esac
    done < <(tar -tzf "$archive")
}

extract_expected_hash() {
    local manifest="$1" asset="$2" line hash_value file_name

    while IFS= read -r line; do
        [[ -z "$line" || "$line" =~ ^[[:space:]]*# ]] && continue
        hash_value="$(printf '%s\n' "$line" | awk '{print $1}')"
        file_name="$(printf '%s\n' "$line" | awk '{print $2}' | sed 's#^\*##; s#^\./##')"

        if [[ "$file_name" == "$asset" ]]; then
            printf '%s' "$hash_value"
            return 0
        fi
    done <<< "$manifest"

    return 1
}

trap 'cleanup $?' EXIT

[[ "$EUID" -eq 0 ]] || fail "Please run as root"

for cmd in curl tar sha256sum awk sed find cp mv chmod chown mktemp systemctl; do
    require_command "$cmd"
done

if [[ -z "$TARGET_RELEASE_TAG" && "$ALLOW_LATEST" != "1" ]]; then
    fail "A fixed release tag is required. Pass it as argument 4, or set XD_ALLOW_LATEST=1 only for laboratory use."
fi

echo "Stopping service: ${SERVICE_NAME}"
systemctl stop "$SERVICE_NAME" || fail "Failed to stop service: ${SERVICE_NAME}"
SERVICE_STOPPED=1

TMP_ROOT="$(mktemp -d /tmp/xd_stream_bin_update.XXXXXX)"
EXTRACT_DIR="$TMP_ROOT/extract"
STAGE_DIR="$TMP_ROOT/stage"
BACKUP_DIR="$TMP_ROOT/backup"
mkdir -p "$EXTRACT_DIR" "$STAGE_DIR" "$BACKUP_DIR" "$TARGET_BIN_DIR"
chmod 0700 "$TMP_ROOT"

DIST_ID=""
DIST_VERSION=""

if command -v lsb_release >/dev/null 2>&1; then
    DIST_ID="$(lsb_release -is 2>/dev/null || true)"
    DIST_ID="${DIST_ID,,}"
    DIST_VERSION="$(lsb_release -rs 2>/dev/null || true)"
fi

if [[ -z "$DIST_ID" || -z "$DIST_VERSION" ]] && [[ -r /etc/os-release ]]; then
    DIST_ID="${DIST_ID:-$(. /etc/os-release && echo "${ID,,}")}"
    DIST_VERSION="${DIST_VERSION:-$(. /etc/os-release && echo "$VERSION_ID")}"
fi

[[ -n "$DIST_ID" && -n "$DIST_VERSION" ]] || fail "Failed to detect Linux distribution"
DIST_MAJOR="${DIST_VERSION%%.*}"
ASSET_NAME=""

case "$DIST_ID" in
    ubuntu)
        case "$DIST_MAJOR" in
            18|20|22|24) ASSET_NAME="ubuntu_${DIST_MAJOR}.tar.gz" ;;
        esac
        ;;
    debian)
        case "$DIST_MAJOR" in
            11|12|13) ASSET_NAME="debian_${DIST_MAJOR}.tar.gz" ;;
        esac
        ;;
    rocky|almalinux|rhel|centos)
        case "$DIST_MAJOR" in
            8|9) ASSET_NAME="rhel_${DIST_MAJOR}.tar.gz" ;;
        esac
        ;;
esac

[[ -n "$ASSET_NAME" ]] || fail "Unsupported distribution for binaries: $DIST_ID $DIST_VERSION"

RELEASE_TAG="$TARGET_RELEASE_TAG"
if [[ -z "$RELEASE_TAG" ]]; then
    API_URL="https://api.github.com/repos/${OWNER}/${REPO}/releases/latest"
    API_RESPONSE="$(curl -fsSL --connect-timeout 5 --max-time 20 \
        -H "Accept: application/vnd.github+json" \
        -H "User-Agent: XD-Stream-Binaries-Updater" \
        "$API_URL")"
    RELEASE_TAG="$(printf '%s\n' "$API_RESPONSE" | sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | awk 'NR == 1 { print; exit }')"
fi

[[ -n "$RELEASE_TAG" && "$RELEASE_TAG" != "latest" ]] || fail "Failed to resolve a valid release tag"
[[ "$RELEASE_TAG" =~ ^[A-Za-z0-9._-]+$ ]] || fail "Unsafe release tag: $RELEASE_TAG"

BASE_RELEASE_URL="https://github.com/${OWNER}/${REPO}/releases/download/${RELEASE_TAG}"
ARCHIVE_URL="${BASE_RELEASE_URL}/${ASSET_NAME}"
ARCHIVE_PATH="$TMP_ROOT/${ASSET_NAME}"

printf 'Downloading %s from %s\n' "$ASSET_NAME" "$RELEASE_TAG"
curl -fL --proto '=https' --tlsv1.2 --connect-timeout 10 --max-time 900 \
    --retry 3 --retry-all-errors -o "$ARCHIVE_PATH" "$ARCHIVE_URL"

HASH_ALGORITHM="sha256"
HASH_MANIFEST_NAME="hashes.sha256"
HASH_MANIFEST="$(curl -fsSL --proto '=https' --tlsv1.2 --connect-timeout 5 --max-time 30 \
    "${BASE_RELEASE_URL}/${HASH_MANIFEST_NAME}" || true)"
EXPECTED_HASH=""

if [[ -n "$HASH_MANIFEST" ]]; then
    EXPECTED_HASH="$(extract_expected_hash "$HASH_MANIFEST" "$ASSET_NAME" || true)"
fi

if [[ -z "$EXPECTED_HASH" && "$ALLOW_LEGACY_MD5" == "1" ]]; then
    require_command md5sum
    HASH_ALGORITHM="md5"
    HASH_MANIFEST_NAME="hashes.md5"
    HASH_MANIFEST="$(curl -fsSL --proto '=https' --tlsv1.2 --connect-timeout 5 --max-time 30 \
        "${BASE_RELEASE_URL}/${HASH_MANIFEST_NAME}" || true)"
    EXPECTED_HASH="$(extract_expected_hash "$HASH_MANIFEST" "$ASSET_NAME" || true)"
fi

[[ -n "$EXPECTED_HASH" ]] || fail "No verified checksum found for ${ASSET_NAME}. Expected hashes.sha256. Legacy MD5 requires XD_ALLOW_LEGACY_MD5=1."

case "$HASH_ALGORITHM" in
    sha256)
        [[ "$EXPECTED_HASH" =~ ^[A-Fa-f0-9]{64}$ ]] || fail "Invalid SHA-256 value in checksum manifest"
        ACTUAL_HASH="$(sha256sum "$ARCHIVE_PATH" | awk '{print $1}')"
        ;;
    md5)
        [[ "$EXPECTED_HASH" =~ ^[A-Fa-f0-9]{32}$ ]] || fail "Invalid MD5 value in checksum manifest"
        ACTUAL_HASH="$(md5sum "$ARCHIVE_PATH" | awk '{print $1}')"
        ;;
esac

[[ "$ACTUAL_HASH" == "$EXPECTED_HASH" ]] || fail "Checksum verification failed for ${ASSET_NAME}"
echo "${HASH_ALGORITHM^^} verification passed for ${ASSET_NAME}"

validate_archive_members "$ARCHIVE_PATH"
tar -xzf "$ARCHIVE_PATH" -C "$EXTRACT_DIR" --no-same-owner --no-same-permissions

SOURCE_ROOT=""
POSSIBLE_PATHS=()
DISTRO_VARIANTS=("${DIST_ID}${DIST_MAJOR}" "${DIST_ID}_${DIST_MAJOR}")

for dname in "${DISTRO_VARIANTS[@]}"; do
    POSSIBLE_PATHS+=("$EXTRACT_DIR/$dname/bin" "$EXTRACT_DIR/$dname")
done
POSSIBLE_PATHS+=("$EXTRACT_DIR/bin" "$EXTRACT_DIR")

for candidate in "${POSSIBLE_PATHS[@]}"; do
    [[ -d "$candidate" ]] || continue
    if is_binary_root "$candidate"; then
        SOURCE_ROOT="$candidate"
        break
    elif is_binary_root "$candidate/bin"; then
        SOURCE_ROOT="$candidate/bin"
        break
    fi
done

if [[ -z "$SOURCE_ROOT" ]]; then
    while IFS= read -r root; do
        if is_binary_root "$root"; then
            SOURCE_ROOT="$root"
            break
        elif is_binary_root "$root/bin"; then
            SOURCE_ROOT="$root/bin"
            break
        fi
    done < <(find "$EXTRACT_DIR" -type d -print)
fi

[[ -n "$SOURCE_ROOT" ]] || fail "Could not locate binary directories inside ${ASSET_NAME}"

NETWORK_SOURCE=""
NETWORK_DEST=""
if [[ -d "$SOURCE_ROOT/network" ]]; then
    NETWORK_SOURCE="$SOURCE_ROOT/network"; NETWORK_DEST="network"
elif [[ -f "$SOURCE_ROOT/network.py" ]]; then
    NETWORK_SOURCE="$SOURCE_ROOT/network.py"; NETWORK_DEST="network.py"
elif [[ -f "$SOURCE_ROOT/network" ]]; then
    NETWORK_SOURCE="$SOURCE_ROOT/network"; NETWORK_DEST="network"
fi
[[ -n "$NETWORK_SOURCE" ]] || fail "Network component is missing in ${ASSET_NAME}"

cp -a -- "$SOURCE_ROOT/nginx" "$STAGE_DIR/nginx"
cp -a -- "$SOURCE_ROOT/nginx_rtmp" "$STAGE_DIR/nginx_rtmp"
cp -a -- "$SOURCE_ROOT/php" "$STAGE_DIR/php"
cp -a -- "$NETWORK_SOURCE" "$STAGE_DIR/$NETWORK_DEST"

install_component_merge() {
    local component="$1" target_path="$TARGET_BIN_DIR/$component" stage_path="$STAGE_DIR/$component"
    [[ -d "$stage_path" ]] || fail "Stage directory is missing for component: ${component}"
    [[ ! -e "$target_path" || -d "$target_path" ]] || fail "Target path is not a directory: ${target_path}"

    if [[ -d "$target_path" ]]; then
        cp -a -- "$target_path" "$BACKUP_DIR/$component"
        BACKED_UP_COMPONENTS+=("$component")
    else
        mkdir -p "$target_path"
        INSTALLED_PATHS+=("$component")
    fi
    cp -a -- "$stage_path/." "$target_path/"
}

install_component_replace() {
    local src_name="$1" dst_name="$2" target_path="$TARGET_BIN_DIR/$dst_name" stage_path="$STAGE_DIR/$src_name"
    [[ -e "$stage_path" ]] || fail "Stage path is missing for component: ${src_name}"

    if [[ -e "$target_path" ]]; then
        mv -- "$target_path" "$BACKUP_DIR/$dst_name"
        BACKED_UP_COMPONENTS+=("$dst_name")
    else
        INSTALLED_PATHS+=("$dst_name")
    fi
    mv -- "$stage_path" "$target_path"
}

ROLLBACK_REQUIRED=1
install_component_merge nginx
install_component_merge nginx_rtmp
install_component_merge php
install_component_replace "$NETWORK_DEST" "$NETWORK_DEST"

[[ ! -f "$TARGET_BIN_DIR/php/bin/php" ]] || chmod 0551 "$TARGET_BIN_DIR/php/bin/php"
[[ ! -f "$TARGET_BIN_DIR/php/sbin/php-fpm" ]] || chmod 0551 "$TARGET_BIN_DIR/php/sbin/php-fpm"
[[ ! -f "$TARGET_BIN_DIR/nginx/sbin/nginx" ]] || chmod 0550 "$TARGET_BIN_DIR/nginx/sbin/nginx"
[[ ! -f "$TARGET_BIN_DIR/nginx_rtmp/sbin/nginx_rtmp" ]] || chmod 0750 "$TARGET_BIN_DIR/nginx_rtmp/sbin/nginx_rtmp"
[[ ! -f "$TARGET_BIN_DIR/network" ]] || chmod 0750 "$TARGET_BIN_DIR/network"
[[ ! -f "$TARGET_BIN_DIR/network.py" ]] || chmod 0750 "$TARGET_BIN_DIR/network.py"

UPDATED_AT="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
cat > "$VERSION_FILE" <<EOF
{
  "owner": "${OWNER}",
  "repository": "${REPO}",
  "release": "${RELEASE_TAG}",
  "asset": "${ASSET_NAME}",
  "checksum_algorithm": "${HASH_ALGORITHM}",
  "checksum": "${ACTUAL_HASH}",
  "checksum_manifest": "${HASH_MANIFEST_NAME}",
  "distribution": "${DIST_ID}",
  "distribution_version": "${DIST_VERSION}",
  "updated_at_utc": "${UPDATED_AT}"
}
EOF
chmod 0640 "$VERSION_FILE"

chown xc_vm:xc_vm -R /home/xc_vm
ROLLBACK_REQUIRED=0
echo "Binaries updated successfully. Version file: ${VERSION_FILE}"
