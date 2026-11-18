# Contributing

One thing first, because it decides whether a PR is accepted:

**This project does not reimplement PocketMine's interfaces.** PocketMine itself (after forking) runs
independently as the plugin host; we only build the bridge. Any PR that hand-writes a class named like a
`pocketmine\*` class in Java or in `pm-host` will be rejected — see
`docs/design/adr/0001-pm-as-host.md` for the reasoning.

## Before you start

1. Read the three iron rules in `docs/project/spec/README.md`
2. Read the process boundaries and tick model in `docs/design/architecture.md`
3. Run `scripts/run-spike.sh` to confirm your local environment works

## Development workflow

```bash
./gradlew bootstrap             # one-time setup for a clean clone + build + architecture checks
./gradlew build                 # everyday build
./gradlew checkArchitecture     # red-line architecture checks
./gradlew runSpike              # end-to-end verification (no Nukkit needed)
./gradlew runM1                 # end-to-end verification of Nukkit + PHP + phar
```

Details on preparing external sources and runtimes are in [`docs/operations/setup.md`](docs/operations/setup.md).

If the host has no JDK/PHP, or you need to reproduce someone else's environment:

```bash
scripts/docker-test.sh          # runs scripts/run-m1.sh inside containers
```

If you change the bridge or the protocol, you must paste the exit codes of `round trip` and fault injection;
if you change the fork, you must paste the L3 liveness test; if you change an adapter, you must paste the
assertion results of `run-m1.sh`.

## Commits

`<scope>: <imperative mood>`, where scope is one of `bridge` / `nukkit` / `spike` / `host` / `fork` / `docs` /
`spec`. Details in `docs/project/spec/process.md`.

## Before submitting

Go through the review checklist in `docs/project/spec/process.md` item by item. If even one item is
unchecked, the PR gets sent back rather than merged "with follow-ups".

## Requirements

- JDK 21+ (Nukkit-MOT's MR-JAR compiles at release 21; Mukkit itself still compiles with `--release 17`)
- PHP 8.1+ (to run the host and capability probing)
- To touch the fork, you also need an environment that can compile pmmp's custom extensions — or start with
  pure-PHP-tier replacement work instead
