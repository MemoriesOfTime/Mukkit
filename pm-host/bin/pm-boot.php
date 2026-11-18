<?php

/*
 * Mukkit headless boot probe: boot the PocketMine fork with no C extensions and drive ticks from outside.
 *
 * This is the acceptance test for the headless boot contract:
 *   - boot() returns (i.e. the constructor does not block in the main loop)
 *   - 1000 external tick() calls do not crash
 *   - Server::getInstance() is usable the whole way
 *
 * It deliberately does NOT go through PocketMine.php's bootstrap: that path is the "real server"
 * launcher (extension checks, console, main loop) which a plugin host must not use.
 *
 * Usage: php pm-host/bin/pm-boot.php [--ticks=1000] [--verbose]
 *   Env: MUKKIT_PM_ROOT  path to the PocketMine fork (default: ../PocketMine-MP)
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pmRoot = getenv("MUKKIT_PM_ROOT") ?: null;

$ticks = 1000;
$verbose = false;
$requireEconomy = false;
foreach(array_slice($argv, 1) as $arg){
	if(preg_match('/^--ticks=(\d+)$/', $arg, $m) === 1){
		$ticks = (int) $m[1];
	}elseif($arg === "--verbose"){
		$verbose = true;
	}elseif($arg === "--require-economy"){
		$requireEconomy = true;
	}
}

function fail(string $message, int $code = 1) : never{
	fwrite(STDERR, "[m2] FAIL " . $message . PHP_EOL);
	exit($code);
}

function ok(string $message) : void{
	fwrite(STDOUT, "[m2] OK   " . $message . PHP_EOL);
}

if($pmRoot === null){
	foreach([dirname($root) . "/PocketMine-MP", $root . "/pm-fork"] as $candidate){
		if(is_file($candidate . "/src/mukkit/polyfill.php")){
			$pmRoot = $candidate;
			break;
		}
	}
}
if($pmRoot === null || !is_file($pmRoot . "/src/mukkit/polyfill.php")){
	fail("PocketMine fork not found; set MUKKIT_PM_ROOT to a checkout with src/mukkit/polyfill.php");
}
ok("fork: " . $pmRoot);

if(!is_file($pmRoot . "/vendor/autoload.php")){
	fail("fork has no vendor/; run: php composer.phar install --ignore-platform-reqs --no-dev");
}

// ---------------------------------------------------------------------------- environment

foreach(["pmmpthread", "leveldb", "chunkutils2", "yaml", "encoding", "morton", "crypto", "igbinary"] as $ext){
	if(extension_loaded($ext)){
		fail("extension '$ext' is loaded - this probe must run on stock PHP to prove the fork is independent");
	}
}

require $pmRoot . "/src/mukkit/polyfill.php";
require $pmRoot . "/vendor/autoload.php";
ok("polyfill + autoload ready (PHP " . PHP_VERSION . ")");

// ---------------------------------------------------------------------------- YAML manifests

// Real plugin.yml files put a block sequence at the *same* indent as its key:
//   api:
//   - 5.0.0
// Reading that as "empty value" drops the list and every key after it, which turns a perfectly
// valid plugin into "Incompatible API version (plugin requires one of: )". These are the shapes
// that real Poggit phars (BedcoreProtect, ChestShop, MultiWorld, Wilderness) actually use.
$manifest = <<<YAML
name: ManifestProbe
main: probe\Main
version: 1.2.3
api:
- 5.0.0
- 5.36.0
authors:
- one
- two
load: POSTWORLD
YAML;
$map = \pocketmine\mukkit\Yaml::parse($manifest);
if(!is_array($map) || ($map["api"] ?? null) !== ["5.0.0", "5.36.0"]){
	fail("a same-indent sequence under a key did not parse as a list");
}
if(($map["version"] ?? null) !== "1.2.3" || ($map["load"] ?? null) !== "POSTWORLD"){
	fail("keys after a same-indent sequence were dropped");
}
if(($map["authors"] ?? null) !== ["one", "two"]){
	fail("a same-indent sequence of plain scalars did not parse");
}
//Plugins parse their own files with the global function, not `Config` (MultiWorld's language
//pack is the one that found this). The shim has to exist or onEnable kills the host.
$manifestFile = sys_get_temp_dir() . "/mukkit-manifest-probe.yml";
file_put_contents($manifestFile, $manifest);
$fromFile = yaml_parse_file($manifestFile);
@unlink($manifestFile);
if(!is_array($fromFile) || ($fromFile["api"] ?? null) !== ["5.0.0", "5.36.0"]){
	fail("yaml_parse_file() shim did not parse a plugin manifest");
}
ok("plugin.yml manifests: same-indent sequences keep the keys after them");

// ---------------------------------------------------------------------------- data dir

$dataPath = $root . "/run/m2/data";
$pluginPath = $root . "/run/m2/plugins";
foreach([$dataPath, $pluginPath] as $dir){
	if(!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)){
		fail("cannot create " . $dir);
	}
}

// Headless means: no console reader child process, no stats upload, no update check, no UDP.
$pocketmineYml = <<<YAML
settings:
  async-workers: 1
  enable-profiling: false
  enable-dev-builds: true
console:
  enable-input: false
  title-tick: false
anonymous-statistics:
  enabled: false
auto-updater:
  enabled: false
YAML;
file_put_contents($dataPath . "/pocketmine.yml", $pocketmineYml . "\n");

$serverProperties = <<<PROPS
motd=Mukkit headless probe
server-port=19132
enable-query=off
enable-ipv6=off
white-list=off
xbox-auth=off
level-name=m2-empty
PROPS;
file_put_contents($dataPath . "/server.properties", $serverProperties . "\n");

// ---------------------------------------------------------------------------- boot

$logger = new \pocketmine\mukkit\HeadlessLogger($verbose);
$loader = new \pocketmine\thread\ThreadSafeClassLoader();
$loader->register(false);

$bootStart = microtime(true);
try{
	$server = new \pocketmine\mukkit\HeadlessServer($loader, $logger, $dataPath, $pluginPath);
}catch(\Throwable $e){
	fail("boot threw " . $e::class . ": " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine() . "\n" . $e->getTraceAsString());
}
$bootMs = (microtime(true) - $bootStart) * 1000.0;

if(!$server instanceof \pocketmine\mukkit\HeadlessServer){
	fail("server is not a HeadlessServer");
}
if(!$server->isBootComplete()){
	fail("constructor returned but boot never completed");
}
if(\pocketmine\Server::getInstance() !== $server){
	fail("Server::getInstance() does not return the booted instance");
}
ok(sprintf("boot() returned in %.1f ms, %d plugin(s) loaded", $bootMs, count($server->getPluginManager()->getPlugins())));
ok("pluginManager=" . $server->getPluginManager()::class . " worldManager=" . $server->getWorldManager()::class);

// ---------------------------------------------------------------------------- real PM plugin

$economyPlugin = $server->getPluginManager()->getPlugin("EconomyAPI");
if($economyPlugin !== null){
	if(!$economyPlugin->isEnabled()){
		fail("EconomyAPI is loaded but not enabled");
	}
	ok("EconomyAPI v" . $economyPlugin->getDescription()->getVersion() . " loaded and enabled as a real PluginBase");

	$dataDir = $economyPlugin->getDataFolder();
	foreach(["config.yml", "messages.yml"] as $file){
		$path = $dataDir . $file;
		if(!is_file($path) || filesize($path) === 0){
			fail("plugin resource was not written: " . $path);
		}
	}
	ok("plugin data written to " . $dataDir . " (config.yml + messages.yml, YAML round trip)");

	$api = \onebone\economyapi\EconomyAPI::getInstance();
	if($api === null || $api !== $economyPlugin){
		fail("EconomyAPI::getInstance() does not return the loaded plugin");
	}

	$api->createAccount("Steve", 100);
	if(!$api->accountExists("Steve")){
		fail("createAccount() did not register an account");
	}
	if((float) $api->myMoney("Steve") !== 100.0){
		fail("myMoney() returned " . var_export($api->myMoney("Steve"), true) . ", expected 100");
	}

	// A real command through the real CommandMap: this is the "EconomyAPI loads for real" acceptance.
	$console = new \pocketmine\console\ConsoleCommandSender($server, $server->getLanguage());
	ob_start();
	$handled = $server->dispatchCommand($console, "setmoney Steve 1234");
	$output = ob_get_clean() ?? "";
	if(!$handled){
		fail("/setmoney was not handled by the CommandMap");
	}
	//the provider stores YAML numbers as float once written back, so compare numerically
	if((float) $api->myMoney("Steve") !== 1234.0){
		fail("after /setmoney Steve 1234 the balance is " . var_export($api->myMoney("Steve"), true));
	}
	if(!str_contains($output, "1234")){
		fail("/setmoney produced no console feedback, got: " . trim($output));
	}
	ok("command round trip: /setmoney Steve 1234 -> balance 1234, console said: " . trim(strip_tags($output)));

	$unit = $api->getMonetaryUnit();
	ok("monetary unit=" . $unit . ", accounts=" . count($api->getAllMoney()));
}elseif($requireEconomy){
	fail("EconomyAPI.phar is not in " . $pluginPath . " - run scripts/build-economyapi.sh");
}else{
	ok("no real PM plugin in " . $pluginPath . " (skipping plugin assertions)");
}

// ---------------------------------------------------------------------------- async pool

// Proves the synchronous pool actually runs task bodies and delivers onCompletion on a tick
// boundary - not that it silently dropped them.
$probeTask = new class extends \pocketmine\scheduler\AsyncTask{
	public bool $ran = false;
	public bool $completed = false;

	public function onRun() : void{
		$this->ran = true;
		$this->setResult(42);
	}

	public function onCompletion() : void{
		$this->completed = true;
	}
};
$server->getAsyncPool()->submitTask($probeTask);
if(!$probeTask->ran){
	fail("sync async pool did not run the task body inline");
}
if($probeTask->completed){
	fail("onCompletion fired before a tick boundary");
}
$server->tick();
if(!$probeTask->completed){
	fail("onCompletion did not fire on the next tick");
}
if($probeTask->getResult() !== 42){
	fail("task result did not survive the sync handoff");
}
ok("sync async pool: onRun inline, onCompletion on next tick, result=" . var_export($probeTask->getResult(), true));

// ---------------------------------------------------------------------------- tick

$durations = [];
$tickStart = microtime(true);
for($i = 1; $i <= $ticks; $i++){
	$t0 = microtime(true);
	try{
		$server->tick();
	}catch(\Throwable $e){
		fail(sprintf("tick #%d threw %s: %s @ %s:%d", $i, $e::class, $e->getMessage(), $e->getFile(), $e->getLine()) . "\n" . $e->getTraceAsString());
	}
	$durations[] = (microtime(true) - $t0) * 1000.0;

	if(\pocketmine\Server::getInstance() !== $server){
		fail(sprintf("tick #%d: Server::getInstance() lost the instance", $i));
	}
}
$elapsed = microtime(true) - $tickStart;

sort($durations);
$p50 = $durations[(int) floor(count($durations) * 0.5)];
$p99 = $durations[(int) floor(count($durations) * 0.99)];
$max = $durations[count($durations) - 1];

ok(sprintf("%d ticks in %.2f s (%.0f tps), per-tick p50 %.3f ms / p99 %.3f ms / max %.3f ms", $ticks, $elapsed, $ticks / max($elapsed, 1e-9), $p50, $p99, $max));
ok(sprintf("memory %.1f MB peak %.1f MB, worlds=%d", memory_get_usage(true) / 1048576, memory_get_peak_usage(true) / 1048576, count($server->getWorldManager()->getWorlds())));

// ---------------------------------------------------------------------------- shutdown

try{
	$server->shutdown();
	ok("shutdown() completed");
}catch(\Throwable $e){
	fail("shutdown threw " . $e::class . ": " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
}

fwrite(STDOUT, "[m2] PASS" . PHP_EOL);
exit(0);
