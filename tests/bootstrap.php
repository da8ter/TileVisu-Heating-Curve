<?php

declare(strict_types=1);

// Isolierte SDK-Attrappe: verbindet sich nie mit einer laufenden Symcon-Installation.
const KR_READY = 10103, IPS_KERNELSTARTED = 10001, VM_UPDATE = 10603;

// Instanzzustand und die SDK-Methoden, die das Modul aufruft. Die Typen folgen IPSModuleStrict: ein falscher
// Typ faellt unter strict_types auch hier als TypeError auf.
class ModuleDouble
{
    public array $properties = [], $attributes = [], $buffers = [], $messages = [], $references = [], $updates = [];
    public int $InstanceID = 12345;
    public int $visualizationType = 0;
    // Symcon: WriteAttribute* "kann nicht in der Create Methode aufgerufen werden"
    public bool $creating = false;

    protected function RegisterPropertyInteger(string $Name, int $Value): void { $this->properties[$Name] = $Value; }
    protected function RegisterPropertyFloat(string $Name, float $Value): void { $this->properties[$Name] = $Value; }
    protected function ReadPropertyInteger(string $Name): int { return $this->Property($Name); }
    protected function ReadPropertyFloat(string $Name): float { return $this->Property($Name); }
    protected function RegisterAttributeInteger(string $Name, int $Value): void { $this->attributes[$Name] = $Value; }
    protected function RegisterAttributeFloat(string $Name, float $Value): void { $this->attributes[$Name] = $Value; }
    protected function RegisterAttributeString(string $Name, string $Value): void { $this->attributes[$Name] = $Value; }
    protected function ReadAttributeInteger(string $Name): int { return $this->Attribute($Name); }
    protected function ReadAttributeFloat(string $Name): float { return $this->Attribute($Name); }
    protected function ReadAttributeString(string $Name): string { return $this->Attribute($Name); }
    protected function WriteAttributeInteger(string $Name, int $Value): void { $this->StoreAttribute($Name, $Value); }
    protected function WriteAttributeFloat(string $Name, float $Value): void { $this->StoreAttribute($Name, $Value); }
    protected function WriteAttributeString(string $Name, string $Value): void { $this->StoreAttribute($Name, $Value); }
    protected function GetBuffer(string $Name): string { return $this->buffers[$Name] ?? ''; }
    protected function SetBuffer(string $Name, string $Data): void { $this->buffers[$Name] = $Data; }
    protected function SetVisualizationType(int $Type): void { $this->visualizationType = $Type; }
    // string statt mixed: die Kachel erwartet JSON-Text, ein false aus json_encode faellt als TypeError auf
    protected function UpdateVisualizationValue(string $Value): void { $this->updates[] = $Value; }
    protected function GetReferenceList(): array { return array_keys($this->references); }
    protected function RegisterReference(int $ID): void { $this->references[$ID] = true; }
    protected function UnregisterReference(int $ID): void { unset($this->references[$ID]); }
    protected function GetMessageList(): array { return $this->messages; }
    protected function RegisterMessage(int $SenderID, int $Message): void
    {
        if (!in_array($Message, $this->messages[$SenderID] ?? [], true)) {
            $this->messages[$SenderID][] = $Message;
        }
    }
    protected function UnregisterMessage(int $SenderID, int $Message): void
    {
        $this->messages[$SenderID] = array_values(array_diff($this->messages[$SenderID] ?? [], [$Message]));
        if ($this->messages[$SenderID] === []) {
            unset($this->messages[$SenderID]);
        }
    }
    protected function SendDebug(string $Message, string $Data, int $Format): void {}

    private function Property(string $Name): mixed
    {
        if (!array_key_exists($Name, $this->properties)) {
            throw new RuntimeException('Property not registered: ' . $Name);
        }
        return $this->properties[$Name];
    }

    private function Attribute(string $Name): mixed
    {
        if (!array_key_exists($Name, $this->attributes)) {
            throw new RuntimeException('Attribute not registered: ' . $Name);
        }
        return $this->attributes[$Name];
    }

    private function StoreAttribute(string $Name, mixed $Value): void
    {
        if ($this->creating) {
            throw new RuntimeException('WriteAttribute in Create: ' . $Name);
        }
        $this->Attribute($Name);
        $this->attributes[$Name] = $Value;
    }
}

// Die Methoden, die ein Modul ueberschreibt, mit den Signaturen von IPSModuleStrict: eine abweichende Signatur
// im Modul scheitert schon beim Laden, wie in Symcon.
class IPSModuleStrict extends ModuleDouble
{
    public function Create(): void {}
    public function ApplyChanges(): void {}
    public function Destroy(): void {}
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void {}
    public function RequestAction(string $Ident, mixed $Value): void {}
    public function GetVisualizationTile(): string { return ''; }
}

// Nur fuer die Gegenprobe: der Stand vor Module Strict erbt von IPSModule (untypisierte Signaturen).
class IPSModule extends ModuleDouble
{
    public function Create() {}
    public function ApplyChanges() {}
    public function Destroy() {}
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {}
    public function RequestAction($Ident, $Value) {}
    public function GetVisualizationTile() { return ''; }
}

$runlevel = KR_READY;
$variables = $writes = $objects = [];

function IPS_GetKernelRunlevel(): int { return $GLOBALS['runlevel']; }
function IPS_VariableExists(int $VariableID): bool { return isset($GLOBALS['variables'][$VariableID]); }
function IPS_GetVariable(int $VariableID): array
{
    return $GLOBALS['variables'][$VariableID] ?? throw new RuntimeException('Variable #' . $VariableID . ' does not exist');
}
function GetValue(int $VariableID): mixed { return IPS_GetVariable($VariableID)['value']; }
function SetValue(int $VariableID, mixed $Value): bool
{
    IPS_GetVariable($VariableID);
    $GLOBALS['writes'][] = [$VariableID, $Value];
    $GLOBALS['variables'][$VariableID]['value'] = $Value;
    return true;
}
// Aktion der Variable: schreibt den Wert, wie es ein Aktionsskript taete
function RequestAction(int $VariableID, mixed $Value): bool
{
    $GLOBALS['writes'][] = [$VariableID, $Value];
    $GLOBALS['variables'][$VariableID]['value'] = $Value;
    return true;
}
function IPS_RequestAction(int $InstanceID, string $VariableIdent, mixed $Value): bool { return false; }
function IPS_ScriptExists(int $ScriptID): bool { return isset($GLOBALS['objects'][$ScriptID]); }
function IPS_InstanceExists(int $InstanceID): bool { return false; }
function IPS_GetObject(int $ObjectID): array { return ['ParentID' => 0, 'ObjectIdent' => '']; }

// Variable, wie das Modul sie ueber IPS_GetVariable und GetValue sieht; $action: Aktionsskript (0 = keine Aktion)
function variable(int $id, mixed $value, int $action = 0): void
{
    $GLOBALS['variables'][$id] = ['VariableAction' => 0, 'VariableCustomAction' => $action, 'value' => $value];
    if ($action > 0) {
        $GLOBALS['objects'][$action] = true;
    }
}

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo 'PASS: ' . $label . PHP_EOL;
}

// TVHC_MODULE waehlt eine andere Fassung des Moduls (Gegenprobe gegen den Vorgaenger).
require getenv('TVHC_MODULE') ?: __DIR__ . '/../TilVisu Heating Curve/module.php';
