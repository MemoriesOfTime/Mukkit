# Mukkit

[![Java](https://img.shields.io/badge/Java-21%2B-orange?style=flat-square)](#requirements)
[![Server](https://img.shields.io/badge/server-Nukkit--MOT-informational?style=flat-square)](https://github.com/MemoriesOfTime/Nukkit-MOT)
[![PocketMine](https://img.shields.io/badge/plugins-PocketMine--MP%205-blue?style=flat-square)](https://github.com/pmmp/PocketMine-MP)

**Mukkit is a plugin for Nukkit-MOT: drop it in and the server can load PocketMine (PHP) plugins directly.**
The world, entities, players and ticks are still owned by Nukkit; PocketMine acts only as a plugin host,
sees a read-only mirror, and submits its writes back to Nukkit.

> [!WARNING]
> **Not production-ready yet.** Mukkit is not a complete PocketMine compatibility layer: only events,
> outbound packets, player state, a world mirror and a few other capabilities are bridged, and plugins
> that rely on PM advancing the world itself are rejected. On-device smoke tests currently pass 8 of 12;
> automatic classification is not implemented. See [Compatibility](#compatibility) for the scope.

## Contents

- [How it works](#how-it-works)
- [Capabilities and status](#capabilities-and-status)
- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
- [Compatibility](#compatibility)
- [Building and verification](#building-and-verification)
- [Project layout](#project-layout)
- [Documentation](#documentation)

## How it works

Mukkit is not "PocketMine crammed into the JVM", nor "two servers fighting over one world". It has just two rules:

1. **There is exactly one authority over the world.** Nukkit owns blocks, entities, players and ticks;
   PocketMine is only a plugin host. The world it sees is a read-only mirror, and any change it wants is
   submitted back as a batch of write operations.
2. **PM's interfaces are not reimplemented.** If behavior needs to change, change the fork — do not add a
   same-named class in the bridge layer.

Everything else follows: PM's `World::tick()` does not run; snapshots a plugin reads may lag by one tick;
plugins that depend on PM advancing the world itself should be rejected at load time. For process
boundaries and the lifecycle of a tick, see
[`docs/design/architecture.md`](docs/design/architecture.md); for where each capability comes from in PM
and what the bridge has to move, see [`docs/design/graft-map.md`](docs/design/graft-map.md).

## Capabilities and status

Status legend: **verified in-bridge** = script assertions pin the shape on both ends; **on-device** = results
on a real client.

| Capability | Status | Evidence / notes |
|---|---|---|
| Install form | ✅ verified in-bridge | One jar dropped into stock Nukkit-MOT's `plugins/`; no server modifications |
| PM plugin loading | ✅ verified in-bridge | Unmodified `.phar`, loaded by PM's own `PluginManager` |
| Event surface | ✅ verified in-bridge | 19 wire names: 5 notifications + 14 decision-type (hold → await verdict → replay) |
| Outbound packet mapping | ✅ verified in-bridge | 9 packet kinds: message, title, form, scoreboard, sound; disconnect goes through `Player.close()` separately |
| Forms | ✅ verified in-bridge | `sendForm()` displays; responses land in `handleResponse()` |
| Player state / inventory | ✅ verified in-bridge | Position, health, gamemode, XP, hunger, effects, slots 0–40 both ways |
| World mirror | ✅ verified in-bridge | Chunk subscription, block reads/writes, multi-world, world clock; long-run drift not verified |
| Entities | ✅ partial | Types present in PM5 become real entities; types missing from PM5 are rejected with `entity.unsupported` |
| Containers / block entities | ✅ verified in-bridge | Windows, transactions, `getTile()` for chests/signs/furnaces; on-device pending |
| Commands / permissions / kick | ✅ verified in-bridge | Player commands go into PM's `CommandMap`, OP and `default: op` cross the bridge, `kick()` really disconnects |
| Real plugins | ✅ loadable | The Docker real-plugin suite loads 7 Poggit plugins; 2 of them need a PHP build with threads |
| On-device (L4) | 🟡 8/12 | NetEase dev client; failures cluster around virtual container windows and cases not yet run |
| Long run / backpressure | ⬜ untested | 2 hours / 20 players / world tour; state self-healing after host restart |

**Boundaries**: no weather (PM5 has no API); no entity inventory/equipment or AI; `block.place`,
`player.move`, damage replay and similar are deliberately not bridged; blocks travel as legacy id + meta,
so some modern states collapse. The full list and rationale are in
[`docs/design/events.md`](docs/design/events.md) and
[`docs/design/graft-map.md`](docs/design/graft-map.md).

By "can actually run": economy, chat, command, scoreboard, teleport, menu shop and item-giving plugins
work; gameplay plugins that depend on block placement, damage, or mob types PM5 lacks do not yet.

- Current roadmap and next steps: [`docs/project/roadmap.md`](docs/project/roadmap.md)
- On-device test checklist and results: [`docs/operations/smoke.md`](docs/operations/smoke.md)
- Verification commands and layers: [`docs/project/spec/testing.md`](docs/project/spec/testing.md)

## Requirements

**Runtime:**

| Component | Requirement |
|---|---|
| Server | [Nukkit-MOT](https://github.com/MemoriesOfTime/Nukkit-MOT) |
| Java | 21+ (Nukkit-MOT's compile target; Mukkit itself compiles for 17) |
| Plugins | `.phar` built for PocketMine-MP 5 / API 5.x |
| Client | Any Bedrock client; NetEase clients require `netease-settings.client-support` in `nukkit-mot.yml` |

**Build:**

| Component | Requirement |
|---|---|
| JDK | 21+ |
| PHP | 8.1+ (with `phar`) |
| git / curl | any recent version |
| Gradle | no install needed; the repo ships a wrapper |

## Installation

1. Obtain `Mukkit.jar` (see [Building and verification](#building-and-verification)).
2. Put `Mukkit.jar` into the server's `plugins/`.
3. Put the PocketMine 5 plugins (`.phar`) you want to load into `plugins/` as well.
4. Start Nukkit-MOT as usual — no launcher flags or server jar changes needed.

```bash
cp mukkit-nukkit/build/libs/Mukkit.jar <your-server>/plugins/
cp <some PM plugin>.phar               <your-server>/plugins/
java -jar Nukkit-MOT.jar
```

On first start it unpacks the PHP host and the PM fork under `plugins/Mukkit/`:

```
plugins/
├── Mukkit.jar
├── EconomyAPI.phar
└── Mukkit/
    ├── config.yml
    ├── pm-host/        # PHP host, unpacked from the jar
    ├── pocketmine/     # PocketMine fork sources, unpacked from the jar
    ├── php/            # bundled PHP runtime, unpacked from the jar (fat jar)
    └── pm-data/        # PocketMine's own data
```

By default the PHP runtime bundled in the jar is used; you only need to change `php-binary` in `config.yml`
(8.1+) to switch to a system PHP. The resolution order is documented in
[`docs/operations/distribution.md`](docs/operations/distribution.md).

By default, phar plugin data follows Nukkit's convention and lives in `plugins/<plugin-name>/`. To make the
PM side occupy just one directory, `plugins/Mukkit/`, set `pm-data-layout: isolated` in
`plugins/Mukkit/config.yml`; data then moves to `plugins/Mukkit/pm-data/plugin_data/<plugin-name>/`.

> [!NOTE]
> On a first start in an empty directory, Nukkit itself downloads the vanilla block palette from GitHub;
> that is unrelated to Mukkit. In offline environments, pre-seed `bin/` as described in Nukkit's docs.

## Usage

Console commands take no leading slash:

| Command | What it does |
|---|---|
| `mukkit status` | Bridge status: host pid, phar plugins, per-tick latency |
| `mukkit plugins` | List the phar plugins currently attached |
| `mukkit demo` | Inject a synthetic event to verify the whole "event → plugin → packet" chain |

NetEase clients speak the NetEase protocol (currently tested with `898 / 1.21.130_NetEase`), which
Nukkit-MOT rejects by default: set `netease-settings.client-support: true` in `nukkit-mot.yml`. The config
generated by `scripts/serve.sh` and the Docker probe mode enable it by default.

## Compatibility

Plugins are not a binary "works / doesn't work"; before loading one you must be able to answer "which tier
does this plugin fall into":

| Tier | Criterion | Examples |
|---|---|---|
| `native` | Uses only events, commands, scheduler, config, forms, messages | EconomyAPI, scoreboards, teleport menus, chat formatting |
| `bridged` | Reads/writes player state, inventory, a few blocks (batched through ops) | Mini-game chest filling, region protection |
| `rejected` | Relies on PM advancing world state itself | SkyBlock, custom blocks/entities, redstone, vehicles |

Rejections must carry a specific reason code rather than failing silently; `StubProfile` already carries
this pattern, automatic classification is not implemented yet. The real plugin list (7 Poggit plugins,
which do not install and why) is in [`docs/operations/plugins.md`](docs/operations/plugins.md).

> [!WARNING]
> An incompatible phar can make the PM host exit — taking down the whole server — rather than just
> failing to work itself. Validate new plugins on a test server first.

## Building and verification

After a clean clone there is exactly one setup command:

```bash
./gradlew bootstrap     # resolve Nukkit-MOT from repo.lanink.cn, materialize the PM fork, install Composer/PHP deps, build, architecture checks
```

Nukkit-MOT is resolved from `https://repo.lanink.cn/` (`cn.nukkit:Nukkit`, version given by
`nukkitVersion` in `gradle.properties`); it is not cloned or built locally — see
[`docs/operations/setup.md`](docs/operations/setup.md).

Everyday builds and entry points:

```bash
./gradlew build                 # produces mukkit-nukkit/build/libs/Mukkit.jar
./gradlew checkArchitecture     # red-line architecture checks
./gradlew runSpike              # in-bridge smoke without Nukkit
./gradlew runM1                 # end-to-end assertions: Nukkit + PHP + phar
./gradlew runProduct            # product assertions: real Nukkit + jar + EconomyAPI
./gradlew serve                 # start a server you can connect to
```

Common assertion scripts:

```bash
scripts/run-product.sh           # product path: real Nukkit + jar + EconomyAPI
scripts/run-m1.sh                # real Nukkit + jar + phar following the HostedPlugin contract
scripts/run-m2.sh                # fork boots on stock PHP (requires ../PocketMine-MP)
scripts/run-m5-pmphp.sh          # same assertions, running on the jar's bundled pmmp PHP
scripts/smoke-client.sh          # L4: probe server, join/chat/form/packets/inventory/world/kick
scripts/docker-test.sh           # JDK + PHP + Gradle all inside containers
```

For the full command table, verification layers and regression requirements, see
[`docs/project/spec/testing.md`](docs/project/spec/testing.md).

## Project layout

```
mukkit-bridge/    protocol, frame codec, Unix socket channel, tick driver, supervision
mukkit-nukkit/    the plugin itself (packaged as Mukkit.jar): phar loader, lifecycle, event and packet mapping
mukkit-spike/     death-criteria verification harness (needs neither Nukkit nor real PM)
pm-host/          PHP host: host loop, profile abstraction, capability probing, phar plugin host
build.gradle.kts  Gradle build: resource packaging, bootstrap, run/serve entry points
gradle/           wrapper, so clean machines need no Gradle install
docs/             design, operations, project governance (index in docs/README.md)
```

The PM fork is not in this tree: `scripts/pm-fork.sh` rebuilds it into `../PocketMine-MP` from a baseline
plus a single patch; the scope of changes is in [`docs/design/pm-fork.md`](docs/design/pm-fork.md).

Three Java classes are worth reading first: `TickDriver` (frame sync and timeouts), `HostChannel` (frame
format), `Gateway` (the only interface a game server has to implement). Two more worth a look:
`MukkitPlugin` (plugin entry point — the install form lives here), `PhpPluginLoader` (the seam into
Nukkit's plugin manager).

## Documentation

Full index in [`docs/README.md`](docs/README.md); start with these three:

| Document | Content |
|---|---|
| [`docs/design/architecture.md`](docs/design/architecture.md) | Process boundaries and the tick model |
| [`docs/design/graft-map.md`](docs/design/graft-map.md) | Where each capability comes from in PM, and what the bridge moves |
| [`docs/project/spec/principles.md`](docs/project/spec/principles.md) | Red lines and prohibitions (read before changing code) |

Everything else — protocol and events, fork cuts, distribution forms, real plugin list, Docker, on-device
tests, roadmap — is in the categorized tables in [`docs/README.md`](docs/README.md).
