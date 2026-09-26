<?php

declare(strict_types=1);

// Heizkurven-Kachel ohne laufendes Symcon. Aus dem Repository-Verzeichnis:
//   php -l "TilVisu Heating Curve/module.php" && php -l tests/bootstrap.php && php -l tests/module_test.php
//   php tests/module_test.php
// Jede Warnung wird zur Ausnahme. Die Gegenprobe holt module.php und module.html des Vorgaengers per git
// in ein Verzeichnis unter sys_get_temp_dir() (TMPDIR) und fuehrt dort die Szenarien im eigenen Prozess aus.
require __DIR__ . '/bootstrap.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

const VORGAENGER = 'c69ac98'; // Stand vor Module Strict und den Kachel-Aenderungen dieses Zweigs

// Konfigurierte Kachel ab Werk: Aussentemperatur #100 (5 °C), Soll-Vorlauf #101 ohne Aktion.
// Kurve 25/55 °C Vorlauf, Aussentemperatur -10..15 °C, Steigung zwischen EndAT -5 und StartAT 10 °C.
function kachel(int $instanz = 20000): TilVisuHeatingCurve
{
    variable(100, 5.0);
    variable(101, 0.0);
    $m = new TilVisuHeatingCurve();
    $m->InstanceID = $instanz;
    $m->creating = true;
    $m->Create();
    $m->creating = false;
    $m->properties['Var_Aussentemperatur'] = 100;
    $m->properties['Var_SollVorlauf'] = 101;
    $m->ApplyChanges();
    return $m;
}

// Zuletzt an die Kachel gesendeter Zustand. json_encode schreibt 25.0 als 25: Zahlen hier einheitlich als Float.
function letzte(TilVisuHeatingCurve $m): array
{
    return $m->updates === [] ? [] : zustand((string) end($m->updates));
}

function zustand(string $json): array
{
    return array_map(static fn (mixed $v): mixed => is_int($v) ? (float) $v : $v, json_decode($json, true, 512, JSON_THROW_ON_ERROR));
}

// Kurvenparameter eines Zustands: MinVorlauf, MaxVorlauf, MinAT, MaxAT, StartAT, EndAT
function kurve(array $zustand): array
{
    return array_values(array_intersect_key($zustand, array_flip(['MinVorlauf', 'MaxVorlauf', 'MinAT', 'MaxAT', 'StartAT', 'EndAT'])));
}

// Szenarien, die auch gegen den Vorgaenger laufen: Zeilen [Bezeichnung, neues Verhalten, bestanden, Fehler].
// "Neues Verhalten" muss beim Vorgaenger fallen, alles andere dort genauso bestehen.
function szenarien(): array
{
    $zeilen = [];
    $zeile = static function (string $label, bool $neu, callable $probe) use (&$zeilen): void {
        $GLOBALS['runlevel'] = KR_READY;
        try {
            $zeilen[] = [$label, $neu, (bool) $probe(), ''];
        } catch (Throwable $e) {
            $zeilen[] = [$label, $neu, false, get_class($e) . ': ' . $e->getMessage()];
        }
    };

    // ±-Knoepfe und Neuberechnung: Rechnung und Grenzen wie bisher
    $zeile('ApplyChanges writes the calculated target flow (5 °C outside -> 35 °C)', false, static function (): bool {
        $m = kachel();
        return $GLOBALS['variables'][101]['value'] === 35.0 && letzte($m)['VL'] === 35.0
            && kurve(letzte($m)) === [25.0, 55.0, -10.0, 15.0, 10.0, -5.0];
    });
    $zeile('State keys unchanged', false, static function (): bool {
        return array_keys(letzte(kachel())) === ['MinVorlauf', 'MaxVorlauf', 'MinAT', 'MaxAT', 'StartAT', 'EndAT', 'VLScaleMin', 'VLScaleMax', 'AT', 'VL'];
    });
    $zeile('MinVL +1: 26 °C, target recalculated (36 °C)', false, static function (): bool {
        $m = kachel();
        $m->RequestAction('MinVL', 1);
        return letzte($m)['MinVorlauf'] === 26.0 && letzte($m)['VL'] === 36.0 && $GLOBALS['variables'][101]['value'] === 36.0;
    });
    $zeile('MaxVL -1: 54 °C, target recalculated (35 °C)', false, static function (): bool {
        $m = kachel();
        $m->RequestAction('MaxVL', -1);
        return letzte($m)['MaxVorlauf'] === 54.0 && $GLOBALS['variables'][101]['value'] === 35.0;
    });
    $zeile('MinVL up to MaxVL pushes MaxVL up', false, static function (): bool {
        $m = kachel();
        $m->RequestAction('MinVL', 30);
        return [letzte($m)['MinVorlauf'], letzte($m)['MaxVorlauf']] === [55.0, 56.0];
    });
    $zeile('MaxVL down to MinVL pushes MinVL down', false, static function (): bool {
        $m = kachel();
        $m->RequestAction('MaxVL', -30);
        return [letzte($m)['MinVorlauf'], letzte($m)['MaxVorlauf']] === [24.0, 25.0];
    });
    $zeile('StartAT is limited to MaxAT', false, static function (): bool {
        $m = kachel();
        $m->RequestAction('StartAT', 6);
        return letzte($m)['StartAT'] === 15.0;
    });
    $zeile('EndAT is limited to MinAT', false, static function (): bool {
        $m = kachel();
        $m->RequestAction('EndAT', -6);
        return letzte($m)['EndAT'] === -10.0;
    });
    $zeile('EndAT above StartAT takes StartAT along', false, static function (): bool {
        $m = kachel();
        $m->RequestAction('EndAT', 16);
        return [letzte($m)['StartAT'], letzte($m)['EndAT']] === [11.0, 11.0];
    });
    $zeile('StartAT below EndAT takes EndAT along', false, static function (): bool {
        $m = kachel();
        $m->RequestAction('StartAT', -16);
        return [letzte($m)['StartAT'], letzte($m)['EndAT']] === [-6.0, -6.0];
    });
    $zeile('Steps are rounded to whole degrees', false, static function (): bool {
        $m = kachel();
        $m->RequestAction('MinVL', 0.4);
        $gerundet = letzte($m)['MinVorlauf'];
        $m->RequestAction('MinVL', 0.6);
        return $gerundet === 25.0 && letzte($m)['MinVorlauf'] === 26.0;
    });
    $zeile('Unknown ident is rejected', false, static function (): bool {
        try {
            kachel()->RequestAction('Unbekannt', 1);
        } catch (Exception $e) {
            return str_contains($e->getMessage(), 'Unknown Ident');
        }
        return false;
    });
    $zeile('Outside temperature update recalculates the target (-10 °C -> 55 °C)', false, static function (): bool {
        $m = kachel();
        $GLOBALS['variables'][100]['value'] = -10.0;
        $m->MessageSink(0, 100, VM_UPDATE, [-10.0, true, 5.0, 1]);
        return letzte($m)['AT'] === -10.0 && letzte($m)['VL'] === 55.0 && $GLOBALS['variables'][101]['value'] === 55.0;
    });
    return $zeilen;
}

// Fuehrt diese Datei mit einer anderen Fassung des Moduls in einem eigenen Prozess aus: [Exitcode, Ausgabe].
function unterprozess(string $moduleFile): array
{
    $command = 'TVHC_MODULE=' . escapeshellarg($moduleFile) . ' ' . escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__FILE__) . ' szenarien 2>&1';
    exec($command, $lines, $code);
    return [$code, implode("\n", $lines)];
}

if (($argv[1] ?? '') === 'szenarien') {
    echo json_encode(szenarien(), JSON_THROW_ON_ERROR);
    exit(0);
}

$root = dirname(__DIR__);

echo '--- Module Strict' . PHP_EOL;
$class = new ReflectionClass(TilVisuHeatingCurve::class);
check($class->getParentClass()->getName() === 'IPSModuleStrict', 'Module extends IPSModuleStrict');
$signatur = static function (string $method) use ($class): string {
    $reflection = $class->getMethod($method);
    $parameter = array_map(static fn (ReflectionParameter $p): string => $p->getType() . ' $' . $p->getName(), $reflection->getParameters());
    return $reflection->class . '::' . $method . '(' . implode(', ', $parameter) . '): ' . $reflection->getReturnType();
};
foreach (['Create(): void', 'ApplyChanges(): void', 'Destroy(): void',
    'MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void',
    'RequestAction(string $Ident, mixed $Value): void', 'GetVisualizationTile(): string'] as $erwartet) {
    check($signatur((string) strstr($erwartet, '(', true)) === 'TilVisuHeatingCurve::' . $erwartet, 'Typed override ' . $erwartet);
}
$quelle = (string) file_get_contents($root . '/TilVisu Heating Curve/module.php');
check(str_starts_with($quelle, "<?php\n\ndeclare(strict_types=1);\n"), 'declare(strict_types=1) is the first statement');
check(!str_contains($quelle, 'method_exists') && !str_contains($quelle, '@$this->'), 'No guards for old Symcon versions (method_exists, @ on SDK methods)');
check(json_decode((string) file_get_contents($root . '/library.json'), true)['compatibility']['version'] === '8.1', 'library.json requires Symcon 8.1 (IPSModuleStrict)');

echo '--- Kernelstart abwarten' . PHP_EOL;
variable(100, 5.0);
variable(101, 0.0);
$k = new TilVisuHeatingCurve();
$k->creating = true;
$k->Create();
$k->creating = false;
check($k->visualizationType === 1, 'Tile uses the HTML SDK');
$k->properties['Var_Aussentemperatur'] = 100;
$k->properties['Var_SollVorlauf'] = 101;
$runlevel = 0;
$writes = [];
$k->ApplyChanges();
check($k->updates === [] && $writes === [] && $k->messages === [0 => [IPS_KERNELSTARTED]], 'Before KR_READY only the kernel start is awaited (no update, no write)');
$runlevel = KR_READY;
$k->MessageSink(0, 0, IPS_KERNELSTARTED, []);
check(count($k->updates) === 1 && in_array(VM_UPDATE, $k->messages[100] ?? [], true) && $variables[101]['value'] === 35.0,
    'Kernel start completes ApplyChanges (state sent, VM_UPDATE registered, target written)');

echo '--- Szenarien' . PHP_EOL;
$zeilen = szenarien();
foreach ($zeilen as [$label, $neu, $ok, $fehler]) {
    check($ok, $label . ($fehler !== '' ? ' [' . $fehler . ']' : ''));
}

echo '--- Gegenprobe gegen ' . VORGAENGER . PHP_EOL;
$repo = escapeshellarg($root);
exec('git -C ' . $repo . ' cat-file -e ' . escapeshellarg(VORGAENGER . '^{commit}') . ' 2>/dev/null', $unused, $gitCode);
if ($gitCode !== 0) {
    echo 'SKIP: ' . VORGAENGER . ' not available, no counter-check' . PHP_EOL;
} else {
    $dir = sys_get_temp_dir() . '/tvhc-test-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    foreach (['module.php', 'module.html'] as $datei) {
        file_put_contents($dir . '/' . $datei,
            (string) shell_exec('git -C ' . $repo . ' show ' . escapeshellarg(VORGAENGER . ':TilVisu Heating Curve/' . $datei)));
    }
    [$code, $json] = unterprozess($dir . '/module.php');
    foreach (['module.php', 'module.html'] as $datei) {
        unlink($dir . '/' . $datei);
    }
    rmdir($dir);
    $vorher = $code === 0 ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : [];
    $fallend = array_column(array_filter($vorher, static fn (array $z): bool => !$z[2]), 0);
    $neu = array_column(array_filter($zeilen, static fn (array $z): bool => $z[1]), 0);
    foreach ($vorher as [$label, , $ok, $fehler]) {
        echo ($ok ? '  besteht: ' : '  faellt:  ') . $label . ($fehler !== '' ? ' [' . $fehler . ']' : '') . PHP_EOL;
    }
    check($code === 0 && array_column($vorher, 0) === array_column($zeilen, 0) && $fallend === $neu,
        'Counter-check: at ' . VORGAENGER . ' exactly the ' . count($neu) . ' rows of new behaviour fail, the other ' . (count($zeilen) - count($neu)) . ' pass');
}
echo 'OK' . PHP_EOL;
