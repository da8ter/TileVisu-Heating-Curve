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

// Kernelstart bzw. Modul-Reload: neues Objekt, Create, danach die gespeicherten Eigenschaften und Attribute
// (Symcon laedt sie nach Create; nicht mehr registrierte fallen weg), ApplyChanges vor KR_READY, dann
// IPS_KERNELSTARTED. Puffer, Nachrichten und Referenzen ueberleben das nicht.
function neustart(TilVisuHeatingCurve $alt): TilVisuHeatingCurve
{
    $neu = new TilVisuHeatingCurve();
    $neu->InstanceID = $alt->InstanceID;
    $neu->creating = true;
    $neu->Create();
    $neu->creating = false;
    $neu->properties = array_replace($neu->properties, array_intersect_key($alt->properties, $neu->properties));
    $neu->attributes = array_replace($neu->attributes, array_intersect_key($alt->attributes, $neu->attributes));
    $GLOBALS['runlevel'] = 0;
    $neu->ApplyChanges();
    $GLOBALS['runlevel'] = KR_READY;
    $neu->MessageSink(0, 0, IPS_KERNELSTARTED, []);
    return $neu;
}

// module.html der geladenen Fassung (beim Vorgaenger die Datei neben dessen module.php)
function basis(): string
{
    return (string) file_get_contents(dirname((string) (new ReflectionClass(TilVisuHeatingCurve::class))->getFileName()) . '/module.html');
}

// Anfangszustand im Kacheldokument: module.html, dahinter genau <script>handleMessage("<JSON-Text>");</script>.
// null, wenn er fehlt oder anders aussieht.
function anfangszustand(string $html): ?array
{
    $base = basis();
    if (!str_starts_with($html, $base)
        || preg_match('~\A<script>handleMessage\\(("(?:[^"\\\\]|\\\\.)*")\\);</script>\z~s', substr($html, strlen($base)), $literal) !== 1) {
        return null;
    }
    // Das JS-Stringliteral ist selbst gueltiges JSON
    return zustand(json_decode($literal[1], true, 512, JSON_THROW_ON_ERROR));
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

    // Anfangszustand im Kacheldokument statt Init-Takt und Broadcast
    $zeile('Tile document carries the current state inline (runtime values after ±)', true, static function (): bool {
        $m = kachel();
        $m->RequestAction('MinVL', 1);
        $inline = anfangszustand($m->GetVisualizationTile());
        return $inline !== null && $inline === letzte($m) && $inline['MinVorlauf'] === 26.0 && $inline['VL'] === 36.0;
    });
    $zeile('Opening a tile sends nothing to the open tiles (no UpdateVisualizationValue)', true, static function (): bool {
        $m = kachel();
        $vorher = count($m->updates);
        $m->GetVisualizationTile();
        return count($m->updates) === $vorher;
    });
    $zeile('Tile no longer polls with requestAction(\'Init\')', true, static function (): bool {
        $html = kachel()->GetVisualizationTile();
        return !str_contains($html, 'requestAction(\'Init\'') && !str_contains($html, 'initHandshake');
    });
    $zeile('Init no longer triggers a broadcast', true, static function (): bool {
        $m = kachel();
        $vorher = count($m->updates);
        try {
            $m->RequestAction('Init', 0);
        } catch (Exception $e) {
            // unbekannter Ident
        }
        return count($m->updates) === $vorher;
    });
    $zeile('Opening a tile writes nothing to the target variable', false, static function (): bool {
        $m = kachel();
        $GLOBALS['variables'][100]['value'] = -10.0;
        $GLOBALS['writes'] = [];
        $m->GetVisualizationTile();
        return $GLOBALS['writes'] === [];
    });

    // Nachrichten und Referenzen
    $zeile('VM_UPDATE only for the assigned outside temperature', false, static function (): bool {
        return kachel()->messages === [100 => [VM_UPDATE]];
    });
    $zeile('Unassigned tile registers no VM_UPDATE (sender 0 would mean every object) and no reference', false, static function (): bool {
        $m = new TilVisuHeatingCurve();
        $m->Create();
        $m->ApplyChanges();
        return $m->messages === [] && $m->references === [];
    });
    $zeile('Another outside temperature variable moves the subscription', false, static function (): bool {
        $m = kachel();
        variable(102, 3.0);
        $m->properties['Var_Aussentemperatur'] = 102;
        $m->ApplyChanges();
        return $m->messages === [102 => [VM_UPDATE]];
    });
    $zeile('After the kernel start no registration on sender 0 remains', false, static function (): bool {
        variable(100, 5.0);
        variable(101, 0.0);
        $m = new TilVisuHeatingCurve();
        $m->Create();
        $m->properties['Var_Aussentemperatur'] = 100;
        $m->properties['Var_SollVorlauf'] = 101;
        $GLOBALS['runlevel'] = 0;
        $m->ApplyChanges();
        $GLOBALS['runlevel'] = KR_READY;
        $m->MessageSink(0, 0, IPS_KERNELSTARTED, []);
        return $m->messages === [100 => [VM_UPDATE]];
    });
    $zeile('Destroy unregisters the messages', false, static function (): bool {
        $m = kachel();
        $m->Destroy();
        return $m->messages === [];
    });
    $zeile('References for both assigned variables, following the assignment', true, static function (): bool {
        $m = kachel();
        $vorher = array_keys($m->references);
        variable(102, 3.0);
        $m->properties['Var_Aussentemperatur'] = 102;
        $m->properties['Var_SollVorlauf'] = 0;
        $m->ApplyChanges();
        return $vorher === [100, 101] && array_keys($m->references) === [102];
    });

    // Laufzeitwerte der ±-Knoepfe gegen Kernelstart, Reload und Uebernehmen
    $zeile('Kernel start / module reload keeps the values set with ±', true, static function (): bool {
        $m = kachel();
        $m->RequestAction('MinVL', 1);
        $m->RequestAction('StartAT', -2);
        $n = neustart($m);
        return kurve(letzte($n)) === [26.0, 55.0, -10.0, 15.0, 8.0, -5.0]
            && kurve((array) anfangszustand($n->GetVisualizationTile())) === [26.0, 55.0, -10.0, 15.0, 8.0, -5.0];
    });
    $zeile('... twice in a row', true, static function (): bool {
        $m = kachel();
        $m->RequestAction('MaxVL', -3);
        return kurve(letzte(neustart(neustart($m)))) === [25.0, 52.0, -10.0, 15.0, 10.0, -5.0];
    });
    $zeile('Saving the form without a changed curve property keeps the ± values', true, static function (): bool {
        $m = kachel();
        $m->RequestAction('EndAT', -2);
        $m->properties['VLScaleMax'] = 60.0;
        $m->ApplyChanges();
        return letzte($m)['EndAT'] === -7.0 && letzte($m)['VLScaleMax'] === 60.0;
    });
    $zeile('MinAT changed in the form is taken over', false, static function (): bool {
        $m = kachel();
        $m->properties['MinAT'] = -12.0;
        $m->ApplyChanges();
        return letzte($m)['MinAT'] === -12.0;
    });
    $zeile('... while the ± values stay', true, static function (): bool {
        $m = kachel();
        $m->RequestAction('MinVL', 2);
        $m->properties['MinAT'] = -12.0;
        $m->ApplyChanges();
        return kurve(letzte($m)) === [27.0, 55.0, -12.0, 15.0, 10.0, -5.0];
    });
    $zeile('A changed curve property wins over its ± value (last change wins)', false, static function (): bool {
        $m = kachel();
        $m->RequestAction('MinVL', 2);
        $m->properties['MinVorlauf'] = 30.0; // IPS_SetProperty + IPS_ApplyChanges
        $m->ApplyChanges();
        return letzte($m)['MinVorlauf'] === 30.0;
    });
    $zeile('Update from the previous version keeps its runtime values', true, static function (): bool {
        variable(100, 5.0);
        variable(101, 0.0);
        $m = new TilVisuHeatingCurve();
        $m->Create();
        $m->properties['Var_Aussentemperatur'] = 100;
        $m->properties['Var_SollVorlauf'] = 101;
        // gespeicherte Laufzeitwerte des Vorgaengers, per ± verstellt (ohne gemerkte Uebernahme)
        foreach (['RT_MinVorlauf' => 30.0, 'RT_MaxVorlauf' => 50.0, 'RT_MinAT' => -10.0, 'RT_MaxAT' => 15.0, 'RT_StartAT' => 8.0, 'RT_EndAT' => -3.0] as $name => $wert) {
            $m->attributes[$name] = $wert;
        }
        $m->ApplyChanges();
        return kurve(letzte($m)) === [30.0, 50.0, -10.0, 15.0, 8.0, -3.0];
    });
    $zeile('Instance from before the runtime values takes the curve from its properties', false, static function (): bool {
        variable(100, 5.0);
        variable(101, 0.0);
        $m = new TilVisuHeatingCurve();
        $m->Create();
        $m->properties['Var_Aussentemperatur'] = 100;
        $m->properties['Var_SollVorlauf'] = 101;
        $m->properties['MinVorlauf'] = 28.0; // frueher schrieben die ±-Knoepfe per IPS_SetProperty
        $m->properties['StartAT'] = 7.0;
        $m->ApplyChanges();
        return kurve(letzte($m)) === [28.0, 55.0, -10.0, 15.0, 7.0, -5.0];
    });

    // Nachrichtenfilter: eine Folge an derselben Kachel, gezaehlt werden die Nachrichten je Schritt.
    // $Data wie von Symcon: [neuer Wert, geaendert, alter Wert, Zeitstempel]
    $m = kachel(20100);
    $schritt = static function (string $label, bool $neu, int $erwartet, callable $aktion) use ($m, &$zeilen): void {
        $GLOBALS['runlevel'] = KR_READY;
        $vorher = count($m->updates);
        try {
            $aktion();
            $ist = count($m->updates) - $vorher;
            $zeilen[] = [$label, $neu, $ist === $erwartet, $ist === $erwartet ? '' : $ist . ' messages'];
        } catch (Throwable $e) {
            $zeilen[] = [$label, $neu, false, get_class($e) . ': ' . $e->getMessage()];
        }
    };
    $at = static function (float $wert, array $data) use ($m): callable {
        return static function () use ($m, $wert, $data): void {
            $GLOBALS['variables'][100]['value'] = $wert;
            $m->MessageSink(0, 100, VM_UPDATE, $data);
        };
    };
    $GLOBALS['variables'][101]['value'] = 40.0; // Soll-Vorlauf von anderer Stelle verstellt
    $GLOBALS['writes'] = [];
    $schritt('Update without a new value ($Data[1] false) sends nothing', true, 0, $at(5.0, [5.0, false, 5.0, 1]));
    $zeilen[] = ['... and neither recalculates nor rewrites the target', true, $GLOBALS['writes'] === [], ''];
    $schritt('Changed value ($Data[1] true) sends the new state', false, 1, $at(6.0, [6.0, true, 5.0, 2]));
    // Das Modul liest den aktuellen Wert: nach schnellen Aenderungen ist der Zustand derselbe
    $schritt('Identical state is not sent again', true, 0, $at(6.0, [6.0, true, 5.5, 3]));
    $schritt('ApplyChanges sends the full state even when unchanged', false, 1, static fn () => $m->ApplyChanges());
    $schritt('... after that an identical update is not repeated', true, 0, $at(6.0, [6.0, true, 5.5, 4]));
    // Erstaufbau ausserhalb der Zaehlung (der Vorgaenger schickte dabei selbst eine Nachricht an alle)
    try {
        $m->GetVisualizationTile();
    } catch (Throwable $e) {
        $zeilen[] = ['Initial build of a tile', false, false, get_class($e) . ': ' . $e->getMessage()];
    }
    $schritt('After the initial build of a tile the next update goes out again', false, 1, $at(6.0, [6.0, true, 5.5, 5]));
    $schritt('... but only once', true, 0, $at(6.0, [6.0, true, 5.5, 6]));
    $schritt('Without $Data[1] (other format) the update is handled', false, 1, $at(7.0, []));
    $schritt('± action sends the new state', false, 1, static fn () => $m->RequestAction('StartAT', 10));
    $schritt('± at a limit without change sends nothing (the tile shows nothing optimistically)', true, 0,
        static fn () => $m->RequestAction('StartAT', 1));
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

echo '--- Anfangszustand sicher im Skriptblock' . PHP_EOL;
$boese = "O'Neil \"x\" \\ Zeile1\nZeile2 </script><script>alert(1)</script> & <b>";
$b = kachel(20001);
$variables[100]['value'] = $boese;
$html = $b->GetVisualizationTile();
$inline = anfangszustand($html);
check($inline !== null && $inline['AT'] === $boese, 'Initial value with quote, backslash, newline and </script> arrives unchanged');
$skript = substr($html, strlen(basis()));
check(substr_count($skript, '</script>') === 1 && !str_contains($skript, '<b>') && substr_count($html, '<script>alert(1)') === 0,
    'No </script> or tag from a value inside the initial script block');
$variables[100]['value'] = "\xB1";
$vorher = count($b->updates);
check($b->GetVisualizationTile() === basis(), 'State that cannot be encoded as JSON: tile without initial script instead of an error');
$b->MessageSink(0, 100, VM_UPDATE, ["\xB1", true, $boese, 2]);
check(count($b->updates) === $vorher, 'State that cannot be encoded as JSON is not sent (no TypeError, no empty message)');

echo '--- Kachel-Skripte (node --check)' . PHP_EOL;
exec('command -v node 2>/dev/null', $nodePfad, $nodeCode);
if ($nodeCode !== 0) {
    echo 'SKIP: node not available' . PHP_EOL;
} else {
    preg_match_all('~<script>(.*?)</script>~s', kachel(20002)->GetVisualizationTile(), $skripte);
    check(count($skripte[1]) === 2, 'Tile document: module script and the initial handleMessage script');
    foreach ($skripte[1] as $nr => $js) {
        $datei = sys_get_temp_dir() . '/tvhc-js-' . bin2hex(random_bytes(6)) . '.js';
        file_put_contents($datei, $js);
        exec('node --check ' . escapeshellarg($datei) . ' 2>&1', $ausgabe, $jsCode);
        unlink($datei);
        check($jsCode === 0, 'Script ' . ($nr + 1) . ' of the tile document compiles' . ($jsCode !== 0 ? ' [' . implode(' ', $ausgabe) . ']' : ''));
    }
}

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
