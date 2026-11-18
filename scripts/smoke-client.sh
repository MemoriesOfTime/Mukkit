#!/usr/bin/env bash
#
# L4 smoke driver: one real Bedrock client against the Docker probe server.
#
# What it automates:
#   join       connect, wait for world + PM PlayerJoinEvent, screenshot
#   chat       one player chat line (the one-line/two-line check)
#   money      EconomyAPI /mymoney, response packet
#   form       form-custom + a UI click on 提交, response lands in handleResponse()
#   packets    title / subtitle / toast / sidebar / the full whitelist fixture
#   inventory  /inv-set, client-visible hotbar item
#   world      /m5write + /m5read
#   kick       /st-kick, disconnect screen
#
# The server side is scripts/docker-serve.sh with PLUGINS=probe. That server enables
# NetEase client support and RCON (see scripts/serve.sh); RCON is how PM commands are
# run without opening the chat UI. Player-only commands still go through chat.
#
# Windows and Linux are not supported by the chat helper (macOS AppleScript); the
# script says so and skips those steps instead of pretending they passed.
#
# Usage:
#   scripts/smoke-client.sh
#   MUKKIT_SMOKE_STEPS=join,chat,form,packets scripts/smoke-client.sh
#   MUKKIT_SMOKE_KEEP=1 scripts/smoke-client.sh      # leave the client session running
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# 外部的 Bedrock 客户端自动化驱动，不在本仓库；默认命令名 mcpy，可用 MCPY 覆盖。
MCPY="${MCPY:-mcpy}"
PROJECT="${MUKKIT_SMOKE_PROJECT:-/tmp/mukkit-smoke}"
PLAYER="${MUKKIT_SMOKE_PLAYER:-Smoke}"
PORT="${PORT:-19132}"
CONTAINER="${MUKKIT_SMOKE_CONTAINER:-mukkit-probe}"
DATA="${MUKKIT_SMOKE_DATA:-$ROOT/run/docker-probe}"
STEPS="${MUKKIT_SMOKE_STEPS:-join,chat,money,form,packets}"
KEEP="${MUKKIT_SMOKE_KEEP:-0}"

RUN_ID="$(date +%Y%m%d-%H%M%S)"
SINCE="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
OUT="$ROOT/run/smoke/$RUN_ID"
mkdir -p "$OUT" "$PROJECT"
REPORT="$OUT/report.md"
SESSION=""
GAME_PID=""
GAME_APP=""
KEEPALIVE_PID=""
PASS=0
FAIL=0
SKIP=0

log()  { printf '%s\n' "$*"; }
ok()   { PASS=$((PASS + 1)); printf 'PASS  %s\n' "$*"; printf '| %s | PASS |\n' "$*" >> "$REPORT"; }
bad()  { FAIL=$((FAIL + 1)); printf 'FAIL  %s\n' "$*"; printf '| %s | FAIL |\n' "$*" >> "$REPORT"; }
skip() { SKIP=$((SKIP + 1)); printf 'SKIP  %s\n' "$*"; printf '| %s | SKIP |\n' "$*" >> "$REPORT"; }
note() { printf 'NOTE  %s\n' "$*"; printf '| %s | NOTE |\n' "$*" >> "$REPORT"; }

have_step() {
    case ",$STEPS," in *,"$1",*) return 0 ;; *) return 1 ;; esac
}

mcpyx() {
    "$MCPY" --local --project "$PROJECT" --non-interactive "$@"
}

rcon() {
    python3 -I "$ROOT/scripts/smoke-rcon.py" "$@"
}

server_log() {
    docker logs --since "$SINCE" "$CONTAINER" 2>&1
}

wait_ready() {
    local i
    for i in $(seq 1 90); do
        local state
        state="$(mcpyx status --session "$SESSION" --json 2>/dev/null || true)"
        if printf '%s' "$state" | python3 -c '
import json, sys
try:
    d = json.load(sys.stdin)
except Exception:
    raise SystemExit(1)
raise SystemExit(0 if d.get("state") == "running" and d.get("world_ready") is True else 1)
'; then
            return 0
        fi
        sleep 1
    done
    return 1
}

wait_join() {
    local i
    for i in $(seq 1 60); do
        if server_log | grep -F "st-join name=$PLAYER" >/dev/null; then
            return 0
        fi
        sleep 1
    done
    return 1
}

# PM's `st-join` can be logged while the client is still on the loading screen. Opening the
# chat UI before the HUD exists fails silently, which then looks like a bridge failure.
wait_hud() {
    local i top
    for i in $(seq 1 40); do
        top="$(mcpyx runtime py --session "$SESSION" --side client \
            --code "import gui; print(gui.get_top_screen())" --json 2>/dev/null \
            | python3 -c 'import json,sys; d=json.load(sys.stdin); print((d.get("stdout") or "").strip())' 2>/dev/null || true)"
        if [ "$top" = "hud_screen" ]; then
            return 0
        fi
        sleep 0.5
    done
    return 1
}

shot() {
    mcpyx screenshot --session "$SESSION" --output "$OUT/$1.png" --json >/dev/null 2>&1 \
        && log "shot  $OUT/$1.png"
}

# Send one line through the native chat UI. The text field accepts paste, not synthetic
# key presses, so this is two steps: open the GUI through the game Python API, then paste
# with the OS keyboard and press Return.
chat() {
    local text="$1"
    if [ "$(uname -s)" != "Darwin" ]; then
        skip "chat '$text' (AppleScript paste is macOS-only)"
        return 1
    fi
    : > "$OUT/.input-busy"
    mcpyx runtime py --session "$SESSION" --side client \
        --code "import mod.client.extraClientApi as api; api.OpenChatGui(True); 'ok'" --json >/dev/null 2>&1
    # OpenChatGui is asynchronous: the screen changes on the next UI tick, and typing
    # before it is up goes to the world instead of the chat field.
    sleep 1.5
    if [ -n "$GAME_APP" ]; then
        open -a "$GAME_APP" >/dev/null 2>&1 || true
    else
        osascript -e 'tell application "System Events" to set frontmost of process "mcpelauncher-client" to true' >/dev/null 2>&1 || true
    fi
    sleep 1.0
    osascript -e 'tell application "System Events"' -e 'repeat 100 times' -e 'key code 51' -e 'end repeat' -e 'end tell' >/dev/null 2>&1
    printf '%s' "$text" | pbcopy
    osascript -e 'tell application "System Events" to keystroke "v" using command down' \
        -e 'delay 0.3' -e 'tell application "System Events" to key code 36' >/dev/null 2>&1
    sleep 1
    rm -f "$OUT/.input-busy"
}

# The chat helper is the one step that depends on the OS focus; retry it instead of
# turning an un-focused paste into a bridge failure.
chat_until() {
    local text="$1" i
    for i in 1 2 3; do
        chat "$text" || return 1
        sleep 1.5
        if server_log | grep -F "<$PLAYER> $text" >/dev/null; then
            return 0
        fi
        osascript -e 'tell application "System Events" to key code 53' >/dev/null 2>&1 || true
        sleep 0.5
    done
    return 1
}

# Count the message in the chat screen's own text tree. The screenshot cannot answer this:
# with the chat screen open, the HUD's chat stack is visible behind it and renders the same
# line a second time. The UI tree is the client's actual message list.
chat_ui_count() {
    local text="$1"
    mcpyx runtime input observe --session "$SESSION" --view ui --limit 50 --json 2>/dev/null \
        | python3 -c '
import json, sys
text = sys.argv[1]
try:
    d = json.load(sys.stdin)
except Exception:
    print(-1)
    raise SystemExit(0)
blob = " ".join(str(n.get("description", "")) + " " + str(n.get("name", "")) for n in d.get("nodes", []))
print(blob.count(text))
' "$text"
}

click_ui_button() {
    local wanted="$1"
    local obs node snap
    : > "$OUT/.input-busy"
    obs="$(mcpyx runtime input observe --session "$SESSION" --view ui --limit 50 --json 2>/dev/null || true)"
    read -r node snap < <(printf '%s' "$obs" | python3 -c '
import json, sys
try:
    d = json.load(sys.stdin)
except Exception:
    raise SystemExit(1)
for n in d.get("nodes", []):
    if n.get("role") == "button" and not n.get("ambiguous") and n.get("name") in sys.argv[1:]:
        print(n["id"], d.get("snapshot"))
        break
' "$wanted" "提交" "Submit" "确定")
    if [ -z "${node:-}" ]; then
        rm -f "$OUT/.input-busy"
        return 1
    fi
    local plan="$OUT/form-click.json"
    printf '{"schema_version":1,"steps":[{"action":"ui.click","node":%s,"snapshot":"%s","duration_ms":80}]}\n' "$node" "$snap" > "$plan"
    mcpyx runtime input run --session "$SESSION" --file "$plan" --json >/dev/null 2>&1
    rm -f "$OUT/.input-busy"
    return 0
}

trap 'if [ -n "$KEEPALIVE_PID" ]; then kill "$KEEPALIVE_PID" >/dev/null 2>&1 || true; fi; if [ -n "$SESSION" ] && [ "$KEEP" != "1" ]; then mcpyx stop --session "$SESSION" --json >/dev/null 2>&1 || true; fi' EXIT

{
    echo "# L4 smoke — $RUN_ID"
    echo
    echo "- client: $(mcpy --version 2>/dev/null | head -1)"
    echo "- player: \`$PLAYER\` on 127.0.0.1:$PORT"
    echo "- steps: \`$STEPS\`"
    echo "- server: \`docker logs $CONTAINER\`"
    echo
    echo "| check | result |"
    echo "|---|---|"
} > "$REPORT"

log "== connect"
# An old session with the same offline name makes Nukkit kick the new login with
# "Already connected", which looks like a join failure in the PM log. Start clean.
for sid in $(mcpyx status --list --json 2>/dev/null | python3 -c '
import json, sys
try:
    d = json.load(sys.stdin)
except Exception:
    raise SystemExit(0)
for s in d.get("sessions", []):
    if s.get("state") == "running":
        print(s.get("session", ""))
' 2>/dev/null); do
    [ -n "$sid" ] && mcpyx stop --session "$sid" --json >/dev/null 2>&1 || true
done
CONNECT_JSON="$(mcpyx connect 127.0.0.1 --port "$PORT" --foreground --detach --json --player-name "$PLAYER" 2>/dev/null || true)"
SESSION="$(printf '%s' "$CONNECT_JSON" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("session",""))' 2>/dev/null || true)"
GAME_PID="$(printf '%s' "$CONNECT_JSON" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("pid",""))' 2>/dev/null || true)"
GAME_EXE="$(printf '%s' "$CONNECT_JSON" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("executable",""))' 2>/dev/null || true)"
GAME_APP="${GAME_EXE%/Contents/MacOS/*}"
if [ -z "$SESSION" ]; then
    log "connect failed: $CONNECT_JSON"
    exit 1
fi
log "session $SESSION (pid ${GAME_PID:-?})"

# macOS App Nap pauses a background game process, and the NetEase client then stops
# sending RakNet keepalives: the server drops it with `transport:timed_out` about 20 s
# after join. Activating the running app (not just `set frontmost`) keeps it awake.
if [ "$(uname -s)" = "Darwin" ]; then
    echo "keepalive start pid=$GAME_PID app=$GAME_APP" > /tmp/mukkit-smoke-keepalive.log
    # A NetEase client that receives no input for ~20 s stops its RakNet keepalives and the
    # server drops it with `transport:timed_out`. Alternating the hotbar slot is a real
    # `MobEquipment`/auth-input packet, unlike a camera look, and is harmless on the flat
    # smoke world. It keeps the session alive while the harness is between UI steps.
    LOOK_A="$OUT/keepalive-slot-a.json"
    LOOK_B="$OUT/keepalive-slot-b.json"
    printf '{"schema_version":1,"steps":[{"action":"player.select_slot","slot":1}]}\n' > "$LOOK_A"
    printf '{"schema_version":1,"steps":[{"action":"player.select_slot","slot":2}]}\n' > "$LOOK_B"
    KEEPALIVE_TICK=0
    ( while [ -n "$GAME_PID" ] && kill -0 "$GAME_PID" >/dev/null 2>&1; do
          echo "$(date +%H:%M:%S) tick" >> /tmp/mukkit-smoke-keepalive.log
          if [ -n "$GAME_APP" ]; then
              open -a "$GAME_APP" >/dev/null 2>&1 || true
          else
              osascript -e 'tell application "System Events" to set frontmost of process "mcpelauncher-client" to true' >/dev/null 2>&1 || true
          fi
          KEEPALIVE_TICK=$((KEEPALIVE_TICK + 1))
          if [ $((KEEPALIVE_TICK % 2)) -eq 0 ]; then
              LOOK="$LOOK_A"
          else
              LOOK="$LOOK_B"
          fi
          if [ ! -f "$OUT/.input-busy" ]; then
              mcpyx runtime input run --session "$SESSION" --file "$LOOK" --json >/dev/null 2>&1 || true
          fi
          sleep 6
      done ) &
    KEEPALIVE_PID=$!
fi

if ! wait_ready; then
    bad "client reached the world"
    exit 1
fi
ok "client reached the world"
shot "00-connect"

mcpyx runtime install --session "$SESSION" --json >/dev/null 2>&1 || true
if wait_hud; then
    log "client HUD ready"
else
    bad "client HUD did not become ready"
fi

if have_step join; then
    if wait_join; then
        ok "PM PlayerJoinEvent (st-join name=$PLAYER)"
    else
        bad "PM PlayerJoinEvent (st-join missing; host may have restarted on the join)"
    fi
    shot "01-join"
fi

if have_step chat; then
    if chat_until "hello from smoke"; then
        ok "player chat reaches PM (server side)"
        lines="$(chat_ui_count "hello from smoke")"
        if [ "$lines" = "1" ]; then
            ok "chat UI contains the message once"
        else
            bad "chat UI message count is $lines (expected 1)"
        fi
        note "screenshot 02-chat.png can show two lines because the HUD chat stack is behind the chat screen; the UI tree is authoritative"
        shot "02-chat"
    else
        bad "player chat did not reach PM after 3 attempts"
    fi
fi

if have_step money; then
    if chat "/mymoney"; then
        sleep 3
        # EconomyAPI answers with a point-to-point TextPacket; it never appears in
        # `docker logs` (the host forwards it as a packet op). The captured client frame is
        # the evidence, so this is a NOTE rather than a log grep that would always fail.
        note "EconomyAPI /mymoney response: inspect 03-money.png"
        shot "03-money"
    fi
fi

if have_step form; then
    rcon "form-custom $PLAYER" >/dev/null 2>&1 || true
    sleep 1
    shot "04-form"
    if click_ui_button "提交"; then
        sleep 1
        if server_log | grep -F "[MukkitFormProbe] custom:" >/dev/null; then
            ok "form response lands in handleResponse()"
        else
            bad "form response did not reach the probe"
        fi
    else
        bad "form submit button was not in the UI tree"
    fi
fi

if have_step packets; then
    rcon "m4packets $PLAYER title" >/dev/null 2>&1 || true
    sleep 0.5
    shot "05-title"
    rcon "m4packets $PLAYER toast" >/dev/null 2>&1 || true
    sleep 0.5
    shot "06-toast"
    rcon "m4packets $PLAYER board" >/dev/null 2>&1 || true
    sleep 0.5
    shot "07-board"
    rcon "m4packets $PLAYER" >/dev/null 2>&1 || true
    sleep 0.5
    shot "08-packets"
    if server_log | grep -F "m4packets sent" >/dev/null; then
        ok "packet fixture was emitted"
    else
        bad "packet fixture did not emit"
    fi
    if server_log | grep -F "SetTimePacket is not in the packet whitelist" >/dev/null \
        || docker logs "$CONTAINER" 2>&1 | grep -F "SetTimePacket is not in the packet whitelist" >/dev/null; then
        ok "non-whitelisted SetTimePacket is reported, not silently dropped"
        note "the host reports each unsupported class once per host lifetime; later runs rely on the earlier line"
    else
        bad "SetTimePacket boundary was not reported"
    fi
    # The fixture leaves a modal form on screen; later steps (inventory/world) need the HUD.
    rcon "form-close $PLAYER" >/dev/null 2>&1 || true
fi

if have_step inventory; then
    # The keepalive alternates the hotbar; put slot 0 in hand so the screenshot shows the item.
    rcon "form-close $PLAYER" >/dev/null 2>&1 || true
    rcon "st-hold 0" >/dev/null 2>&1 || true
    rcon "inv-set 0 diamond 3 SmokeSword" >/dev/null 2>&1 || true
    sleep 1
    shot "09-inventory"
    if server_log | grep -F "inv-set" >/dev/null; then
        ok "inventory write reached PM"
    else
        bad "inventory write did not reach PM"
    fi
fi

if have_step world; then
    rcon "m5write 130 5 130" >/dev/null 2>&1 || true
    rcon "m5read 130 5 130" >/dev/null 2>&1 || true
    sleep 1
    shot "10-world"
    if server_log | grep -F "m5write 130,5,130 done" >/dev/null; then
        ok "world write reached PM"
    else
        bad "world write did not reach PM"
    fi
fi

if have_step kick; then
    rcon "st-kick smoke reason" >/dev/null 2>&1 || true
    sleep 3
    # The game process stays alive on the disconnect screen; what L4 needs is the server-side
    # quit, which is what the PM-side kick path produces exactly once.
    if server_log | grep -F "st-quit name=$PLAYER" >/dev/null; then
        ok "Player::kick() produced PlayerQuitEvent"
    else
        bad "kick did not produce a PM quit"
    fi
    shot "11-kick"
fi

{
    echo
    echo "## Server evidence (tail)"
    echo '```'
    server_log | tail -40
    echo '```'
} >> "$REPORT"

log
log "PASS $PASS  FAIL $FAIL  SKIP $SKIP"
log "report $REPORT"
[ "$FAIL" -eq 0 ]
