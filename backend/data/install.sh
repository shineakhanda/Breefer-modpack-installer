#!/bin/bash
# Breefer Modpack Installer — runner script.
# Executed by the agent inside the install container with the server files mounted at /mnt/server.
# Providers: modrinth | curseforge | ftb

set -u
mkdir -p /mnt/server
cd /mnt/server || exit 1

log() { echo "[Breefer] $*"; }
fail() { echo "[Breefer] ERROR: $*"; exit 1; }
need() { command -v "$1" >/dev/null 2>&1; }

if ! need curl || ! need jq || ! need unzip; then
    log "Installing dependencies (curl, jq, unzip)..."
    if need apt-get; then
        apt-get update -qq || log "apt-get update reported errors"
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends curl jq unzip ca-certificates >/dev/null
    elif need apk; then
        apk add --no-cache curl jq unzip bash ca-certificates >/dev/null
    fi
fi
need curl && need jq && need unzip || fail "curl, jq and unzip are required in the install container."

UA="Breefer-Modpack-Installer/1.0"
MCJARS="${MCJARS_URL:-https://versions.mcjars.app}"
WORK="/mnt/server/.breefer-modpack"
PROVIDER="${MODPACK_PROVIDER:-}"
PACK_ID="${MODPACK_ID:-}"
VERSION_ID="${MODPACK_VERSION_ID:-}"

dl() { # url, output
    local url="$1" out="$2" tries=0
    mkdir -p "$(dirname "$out")"
    until curl -fsSL --retry 2 -A "$UA" -o "$out" "$url"; do
        tries=$((tries + 1))
        [ $tries -ge 3 ] && { log "Failed to download $url"; return 1; }
        sleep 2
    done
}

safe_path() { # rejects absolute paths and traversal
    case "$1" in
        /*|*..*|"") return 1 ;;
        *) return 0 ;;
    esac
}

copy_overrides() { # dir
    if [ -d "$1" ]; then
        log "Applying $(basename "$1")"
        cp -rf "$1"/. /mnt/server/
    fi
}

install_server() { # minecraft_version loader loader_version
    local MC="$1" TYPE LOADER_VERSION="${3:-}"
    TYPE=$(echo "${2:-vanilla}" | tr '[:lower:]' '[:upper:]')
    [ -z "$TYPE" ] || [ "$TYPE" = "NULL" ] && TYPE="VANILLA"
    log "Installing $TYPE ${LOADER_VERSION} for Minecraft $MC via MCJars"

    local BUILDS ID STEPS
    BUILDS=$(curl -fsSL -A "$UA" "$MCJARS/api/v2/builds/$TYPE/$MC?fields=id,projectVersionId,buildNumber") || fail "MCJars has no $TYPE builds for $MC"
    ID=$(echo "$BUILDS" | jq -r --arg v "$LOADER_VERSION" '([.builds[] | select(.projectVersionId == $v)][0].id) // (.builds[0].id) // empty')
    [ -n "$ID" ] || fail "No $TYPE build found for Minecraft $MC"
    log "Using MCJars build $ID"

    STEPS=$(curl -fsSL -A "$UA" -H 'Content-Type: application/json' -X POST -d "{\"id\":$ID}" "$MCJARS/api/v2/build?fields=id,installation") || fail "Could not load build $ID"
    rm -rf /mnt/server/libraries /mnt/server/server.jar
    echo "$STEPS" | jq -c '.build.installation[][]' | while read -r step; do
        case "$(echo "$step" | jq -r '.type')" in
            download)
                f=$(echo "$step" | jq -r '.file'); u=$(echo "$step" | jq -r '.url')
                safe_path "$f" || continue
                log "Downloading $f"; dl "$u" "/mnt/server/$f"
                ;;
            unzip)
                f=$(echo "$step" | jq -r '.file'); l=$(echo "$step" | jq -r '.location')
                safe_path "$f" || continue
                [ "$l" = "." ] && l=""
                unzip -qo "/mnt/server/$f" -d "/mnt/server/$l"
                ;;
            remove)
                l=$(echo "$step" | jq -r '.location')
                safe_path "$l" && rm -rf "/mnt/server/$l"
                ;;
        esac
    done
    [ -f /mnt/server/server.jar ] || [ -d /mnt/server/libraries ] || fail "Server software installation produced no files"
    rm -f /mnt/server/start.sh /mnt/server/run.sh /mnt/server/run.bat
}

log "Provider: $PROVIDER  pack: $PACK_ID  version: $VERSION_ID"
[ -n "$PROVIDER" ] && [ -n "$PACK_ID" ] && [ -n "$VERSION_ID" ] || fail "Missing modpack details."

if [ "${DELETE_SERVER_FILES:-false}" = "true" ]; then
    log "Deleting existing server files (keeping server.properties, ops, whitelist and eula)"
    find /mnt/server -mindepth 1 -maxdepth 1 \
        ! -name 'server.properties' ! -name 'eula.txt' ! -name 'ops.json' ! -name 'whitelist.json' \
        ! -name 'banned-players.json' ! -name 'banned-ips.json' -exec rm -rf {} +
else
    log "Removing previous mods, config and loader libraries"
    rm -rf /mnt/server/mods /mnt/server/libraries /mnt/server/.fabric /mnt/server/.quilt
fi

rm -rf "$WORK"; mkdir -p "$WORK"

case "$PROVIDER" in
    modrinth)
        INFO=$(curl -fsSL -A "$UA" "https://api.modrinth.com/v2/version/$VERSION_ID") || fail "Modrinth version not found"
        URL=$(echo "$INFO" | jq -r '([.files[] | select(.primary == true)][0].url) // .files[0].url // empty')
        [ -n "$URL" ] || fail "This Modrinth version has no downloadable file"
        log "Downloading modpack"
        dl "$URL" "$WORK/pack.mrpack" || fail "Download failed"
        unzip -qo "$WORK/pack.mrpack" -d "$WORK/pack" || fail "Invalid .mrpack"
        INDEX="$WORK/pack/modrinth.index.json"
        [ -f "$INDEX" ] || fail "modrinth.index.json missing from pack"

        MC=$(jq -r '.dependencies.minecraft' "$INDEX")
        if jq -e '.dependencies."fabric-loader"' "$INDEX" >/dev/null; then LOADER=fabric; LV=$(jq -r '.dependencies."fabric-loader"' "$INDEX")
        elif jq -e '.dependencies."quilt-loader"' "$INDEX" >/dev/null; then LOADER=quilt; LV=$(jq -r '.dependencies."quilt-loader"' "$INDEX")
        elif jq -e '.dependencies.neoforge' "$INDEX" >/dev/null; then LOADER=neoforge; LV=$(jq -r '.dependencies.neoforge' "$INDEX")
        elif jq -e '.dependencies.forge' "$INDEX" >/dev/null; then LOADER=forge; LV=$(jq -r '.dependencies.forge' "$INDEX")
        else LOADER=vanilla; LV=""; fi

        install_server "$MC" "$LOADER" "$LV"

        TOTAL=$(jq '.files | length' "$INDEX"); N=0
        log "Downloading $TOTAL pack files"
        jq -c '.files[]' "$INDEX" | while read -r file; do
            N=$((N + 1))
            P=$(echo "$file" | jq -r '.path')
            ENV=$(echo "$file" | jq -r '.env.server // "required"')
            [ "$ENV" = "unsupported" ] && { log "[$N/$TOTAL] skip client-only $P"; continue; }
            safe_path "$P" || { log "[$N/$TOTAL] skip unsafe path $P"; continue; }
            U=$(echo "$file" | jq -r '.downloads[0]')
            log "[$N/$TOTAL] $P"
            dl "$U" "/mnt/server/$P" || true
        done
        copy_overrides "$WORK/pack/overrides"
        copy_overrides "$WORK/pack/server-overrides"
        cp -f "$INDEX" /mnt/server/modrinth.index.json
        ;;

    curseforge)
        KEY="${CURSEFORGE_API_KEY:-}"
        [ -n "$KEY" ] || fail "CurseForge API key is not configured in the Breefer admin settings."
        CF() { curl -fsSL -A "$UA" -H "Accept: application/json" -H "x-api-key: $KEY" "$@"; }
        INFO=$(CF "https://api.curseforge.com/v1/mods/$PACK_ID/files/$VERSION_ID") || fail "CurseForge file not found"
        URL=$(echo "$INFO" | jq -r '.data.downloadUrl // empty')
        NAME=$(echo "$INFO" | jq -r '.data.fileName')
        if [ -z "$URL" ]; then
            URL="https://edge.forgecdn.net/files/${VERSION_ID:0:4}/$(echo "${VERSION_ID:4}" | sed 's/^0*//')/$(jq -rn --arg n "$NAME" '$n|@uri')"
        fi
        log "Downloading modpack $NAME"
        dl "$URL" "$WORK/pack.zip" || fail "Download failed"
        unzip -qo "$WORK/pack.zip" -d "$WORK/pack" || fail "Invalid modpack zip"
        MANIFEST="$WORK/pack/manifest.json"
        [ -f "$MANIFEST" ] || fail "manifest.json missing (is this a server pack? choose the client pack file)"

        MC=$(jq -r '.minecraft.version' "$MANIFEST")
        LOADER_ID=$(jq -r '([.minecraft.modLoaders[] | select(.primary == true)][0].id) // .minecraft.modLoaders[0].id // "vanilla"' "$MANIFEST")
        LOADER="${LOADER_ID%%-*}"; LV="${LOADER_ID#*-}"
        install_server "$MC" "$LOADER" "$LV"

        mkdir -p /mnt/server/mods
        IDS=$(jq -c '[.files[] | select(.required != false) | .fileID]' "$MANIFEST")
        log "Resolving $(echo "$IDS" | jq length) mod files"
        FILES=$(CF -H "Content-Type: application/json" -X POST -d "{\"fileIds\":$IDS}" "https://api.curseforge.com/v1/mods/files") || fail "Could not resolve mod files"
        echo "$FILES" | jq -c '.data[]' | while read -r f; do
            FN=$(echo "$f" | jq -r '.fileName'); FID=$(echo "$f" | jq -r '.id')
            FU=$(echo "$f" | jq -r '.downloadUrl // empty')
            [ -z "$FU" ] && FU="https://edge.forgecdn.net/files/${FID:0:4}/$(echo "${FID:4}" | sed 's/^0*//')/$(jq -rn --arg n "$FN" '$n|@uri')"
            case "$FN" in */*|*..*) continue ;; esac
            # Resource packs and shaders are listed in the manifest too; only jars go to mods/
            case "$FN" in *.jar) DEST="/mnt/server/mods/$FN" ;; *) DEST="/mnt/server/.breefer-modpack/extra/$FN" ;; esac
            log "mod $FN"
            dl "$FU" "$DEST" || true
        done
        copy_overrides "$WORK/pack/$(jq -r '.overrides // "overrides"' "$MANIFEST")"
        cp -f "$MANIFEST" /mnt/server/manifest.json
        ;;

    ftb)
        INSTALLER="$WORK/serverinstall"
        log "Downloading FTB server installer"
        dl "https://api.feed-the-beast.com/v1/modpacks/public/modpack/$PACK_ID/$VERSION_ID/server/linux" "$INSTALLER" || fail "Download failed"
        chmod +x "$INSTALLER"
        log "Running FTB installer"
        "$INSTALLER" -auto -no-java -force -dir /mnt/server || "$INSTALLER" -auto -no-java || fail "FTB installer failed"
        ;;

    *)
        fail "Unknown provider '$PROVIDER'"
        ;;
esac

if [ "${ACCEPT_EULA:-false}" = "true" ]; then
    echo "eula=true" > /mnt/server/eula.txt
fi

cat > /mnt/server/.breefer-modpack.json <<EOF
{"provider":"$PROVIDER","pack_id":"$PACK_ID","version_id":"$VERSION_ID","minecraft":"${MC:-}","loader":"${LOADER:-}","installed_at":"$(date -u +%Y-%m-%dT%H:%M:%SZ)"}
EOF

rm -rf "$WORK"
log "Modpack installation complete. Start the server to finish setup."
exit 0
