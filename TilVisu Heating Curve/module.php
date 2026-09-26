<?php

declare(strict_types=1);

class TilVisuHeatingCurve extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyFloat('MinVorlauf', 25.0);
        $this->RegisterPropertyFloat('MaxVorlauf', 55.0);
        $this->RegisterPropertyFloat('MinAT', -10.0);
        $this->RegisterPropertyFloat('MaxAT', 15.0);
        $this->RegisterPropertyFloat('StartAT', 10.0);
        $this->RegisterPropertyFloat('EndAT', -5.0);
        $this->RegisterPropertyFloat('VLScaleMin', 20.0);
        $this->RegisterPropertyFloat('VLScaleMax', 50.0);
        $this->RegisterPropertyInteger('Var_Aussentemperatur', 0);
        $this->RegisterPropertyInteger('Var_SollVorlauf', 0);

        // Attributes for runtime curve parameters (overridable via RequestAction)
        $this->RegisterAttributeFloat('RT_MinVorlauf', 0.0);
        $this->RegisterAttributeFloat('RT_MaxVorlauf', 0.0);
        $this->RegisterAttributeFloat('RT_MinAT', 0.0);
        $this->RegisterAttributeFloat('RT_MaxAT', 0.0);
        $this->RegisterAttributeFloat('RT_StartAT', 0.0);
        $this->RegisterAttributeFloat('RT_EndAT', 0.0);

        // Enable HTML-SDK Tile visualization
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Kein Heavy Work vor KR_READY: Nachrichten, Variablenzugriffe und das Schreiben des
        // Soll-Vorlaufs erst, wenn der Kernel bereit ist. IPS_KERNELSTARTED ruft ApplyChanges erneut auf.
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        // Initialize runtime attributes from properties
        $this->WriteAttributeFloat('RT_MinVorlauf', (float)$this->ReadPropertyFloat('MinVorlauf'));
        $this->WriteAttributeFloat('RT_MaxVorlauf', (float)$this->ReadPropertyFloat('MaxVorlauf'));
        $this->WriteAttributeFloat('RT_MinAT', (float)$this->ReadPropertyFloat('MinAT'));
        $this->WriteAttributeFloat('RT_MaxAT', (float)$this->ReadPropertyFloat('MaxAT'));
        $this->WriteAttributeFloat('RT_StartAT', (float)$this->ReadPropertyFloat('StartAT'));
        $this->WriteAttributeFloat('RT_EndAT', (float)$this->ReadPropertyFloat('EndAT'));

        // Alle bisherigen Nachrichten abmelden, auch IPS_KERNELSTARTED und eine frueher zugeordnete Variable
        $this->UnregisterAllMessages();

        // Validate curve parameters (read from runtime attributes)
        $minVL = $this->ReadAttributeFloat('RT_MinVorlauf');
        $maxVL = $this->ReadAttributeFloat('RT_MaxVorlauf');
        $minAT = $this->ReadAttributeFloat('RT_MinAT');
        $maxAT = $this->ReadAttributeFloat('RT_MaxAT');
        $startAT = $this->ReadAttributeFloat('RT_StartAT');
        $endAT = $this->ReadAttributeFloat('RT_EndAT');
        $varAT = (int)$this->ReadPropertyInteger('Var_Aussentemperatur');
        $varVL = (int)$this->ReadPropertyInteger('Var_SollVorlauf');

        $valid = true;
        if (!($minVL < $maxVL)) {
            $this->SendDebug('Validation', 'MinVorlauf must be < MaxVorlauf', 0);
            $valid = false;
        }
        if (!($minAT < $maxAT)) {
            $this->SendDebug('Validation', 'MinAT must be < MaxAT', 0);
            $valid = false;
        }
        if ($varAT === 0 || $varVL === 0) {
            $this->SendDebug('Validation', 'Aussentemperatur and SollVorlauf variables must be set', 0);
            $valid = false;
        }
        // Ensure plateau boundaries are within AT range and ordered: minAT <= endAT <= startAT <= maxAT
        if (!($minAT <= $endAT && $endAT <= $startAT && $startAT <= $maxAT)) {
            $this->SendDebug('Validation', 'Plateau AT bounds invalid (require MinAT <= EndAT <= StartAT <= MaxAT)', 0);
            $valid = false;
        }

        // Referenzen auf die zugeordneten Variablen (0 = nicht zugeordnet)
        foreach ($this->GetReferenceList() as $reference) {
            $this->UnregisterReference($reference);
        }
        foreach ([$varAT, $varVL] as $variableID) {
            if ($variableID > 0) {
                $this->RegisterReference($variableID);
            }
        }

        // Register to VM_UPDATE of Außentemperatur variable. Nur zugeordnet: Absender 0 hiesse jedes Objekt,
        // MessageSink liefe bei jeder Variablenaktualisierung im System.
        if ($varAT > 0) {
            $this->RegisterMessage($varAT, VM_UPDATE);
        }

        // Perform initial calculation and push visualization state. Nach einer Aenderung geht der volle Zustand
        // hinaus, auch wenn er dem zuletzt gesendeten gleicht: die Pruefwerte gelten nicht mehr.
        $this->SetBuffer('UpdateHashes', '');
        $this->RecalculateAndPush($valid);
    }

    public function Destroy(): void
    {
        // Clean up (only if kernel is ready; InstanceInterface may be unavailable during shutdown)
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->UnregisterAllMessages();
        }
        parent::Destroy();
    }

    // Meldet jede registrierte Nachricht ab (GetMessageList), statt sich die zuletzt angemeldete Variable zu merken
    private function UnregisterAllMessages(): void
    {
        foreach ($this->GetMessageList() as $senderID => $messageIDs) {
            foreach ($messageIDs as $messageID) {
                $this->UnregisterMessage($senderID, $messageID);
            }
        }
    }

    // Handle visualization actions from the HTML tile (idents: MinVL, MaxVL, MinAT, MaxAT, StartAT, EndAT)
    public function RequestAction(string $Ident, mixed $Value): void
    {
        // Value is expected to be a float delta (e.g., +1 / -1)
        // Kein 'Init' mehr: den Anfangszustand liefert GetVisualizationTile im Kacheldokument mit.
        $delta = (float)$Value;

        // Read current runtime values from attributes
        $minVL = $this->ReadAttributeFloat('RT_MinVorlauf');
        $maxVL = $this->ReadAttributeFloat('RT_MaxVorlauf');
        $minAT = $this->ReadAttributeFloat('RT_MinAT');
        $maxAT = $this->ReadAttributeFloat('RT_MaxAT');
        $startAT = $this->ReadAttributeFloat('RT_StartAT');
        $endAT = $this->ReadAttributeFloat('RT_EndAT');

        switch ($Ident) {
            case 'MinVL':
                $minVL = round($minVL + $delta);
                break;
            case 'MaxVL':
                $maxVL = round($maxVL + $delta);
                break;
            case 'MinAT':
                $minAT = round($minAT + $delta);
                break;
            case 'MaxAT':
                $maxAT = round($maxAT + $delta);
                break;
            case 'StartAT':
                $startAT = round($startAT + $delta);
                break;
            case 'EndAT':
                $endAT = round($endAT + $delta);
                break;
            default:
                throw new Exception('Unknown Ident: ' . $Ident);
        }

        // Enforce rules: Min < Max
        if (!($minVL < $maxVL)) {
            // Adjust by nudging the opposite bound
            if ($Ident === 'MinVL') {
                $maxVL = $minVL + 1.0;
            } else {
                $minVL = $maxVL - 1.0;
            }
        }
        if (!($minAT < $maxAT)) {
            if ($Ident === 'MinAT') {
                $maxAT = $minAT + 1.0;
            } else {
                $minAT = $maxAT - 1.0;
            }
        }
        // Ensure plateau order: minAT <= endAT <= startAT <= maxAT
        if ($endAT < $minAT) $endAT = $minAT;
        if ($startAT > $maxAT) $startAT = $maxAT;
        if ($endAT > $startAT) {
            if ($Ident === 'EndAT') {
                $startAT = $endAT;
            } else {
                $endAT = $startAT;
            }
        }

        // Persist new values to runtime attributes
        $this->WriteAttributeFloat('RT_MinVorlauf', $minVL);
        $this->WriteAttributeFloat('RT_MaxVorlauf', $maxVL);
        $this->WriteAttributeFloat('RT_MinAT', $minAT);
        $this->WriteAttributeFloat('RT_MaxAT', $maxAT);
        $this->WriteAttributeFloat('RT_StartAT', $startAT);
        $this->WriteAttributeFloat('RT_EndAT', $endAT);

        // Push the new values to the tile first, then write the target flow temperature (may take a while).
        // Die Kachel zeigt nicht optimistisch an (ein Klick schickt nur requestAction, die Anzeige folgt der
        // Nachricht des Moduls): ein unveraenderter Zustand, etwa an einer Grenze, braucht keine Korrektur.
        $state = $this->BuildState(true);
        $this->SendState($state);

        // Recalculate and write target flow temperature
        if ($state['VL'] !== null) {
            $this->WriteTargetIfChanged($this->ReadPropertyInteger('Var_SollVorlauf'), $state['VL']);
        }
    }

    // Message sink for IPS_KERNELSTARTED and VM_UPDATE events
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }

        if ($Message === VM_UPDATE && $SenderID === (int)$this->ReadPropertyInteger('Var_Aussentemperatur')) {
            // Symcon meldet jede Aktualisierung, auch ohne neuen Wert: dann bleibt alles, wie es ist
            if (!self::ValueChanged($Data)) {
                return;
            }
            $this->SendDebug('Event', 'VM_UPDATE from Außentemperatur', 0);
            $this->RecalculateAndPush(true);
        }
    }

    // Symcon meldet mit VM_UPDATE jede Aktualisierung; $Data[1] sagt, ob sich der Wert geaendert hat.
    // Fehlt die Angabe (anderes Format), gilt sie als Aenderung: lieber senden als eine verschlucken.
    private static function ValueChanged(array $Data): bool
    {
        return !isset($Data[1]) || (bool)$Data[1];
    }

    private function RecalculateAndPush(bool $configValid): void
    {
        $state = $this->BuildState($configValid);
        $varVL = $this->ReadPropertyInteger('Var_SollVorlauf');

        if ($state['VL'] !== null) {
            $this->SendDebug('Calculate', sprintf('AT=%.1f -> VL=%.1f', $state['AT'], $state['VL']), 0);
            $this->WriteTargetIfChanged($varVL, $state['VL']);
        } else {
            $this->SendDebug('Skip', sprintf('configValid=%d, at=%s, varVL=%d', $configValid, $state['AT'] === null ? 'null' : $state['AT'], $varVL), 0);
        }

        // Push visualization update
        $this->SendState($state);
    }

    // Zustand der Kachel: Kurve aus den Laufzeitwerten (RT_*), Achse, aktuelle Aussentemperatur und Soll-Vorlauf.
    // Derselbe Aufbau fuer den Anfangszustand im Kacheldokument und fuer jede spaetere Nachricht.
    // VL nur mit gueltiger Konfiguration, bekannter Aussentemperatur und zugeordnetem Soll-Vorlauf.
    private function BuildState(bool $configValid): array
    {
        $minVL = $this->ReadAttributeFloat('RT_MinVorlauf');
        $maxVL = $this->ReadAttributeFloat('RT_MaxVorlauf');
        $minAT = $this->ReadAttributeFloat('RT_MinAT');
        $maxAT = $this->ReadAttributeFloat('RT_MaxAT');
        $startAT = $this->ReadAttributeFloat('RT_StartAT');
        $endAT = $this->ReadAttributeFloat('RT_EndAT');
        $varAT = $this->ReadPropertyInteger('Var_Aussentemperatur');
        $varVL = $this->ReadPropertyInteger('Var_SollVorlauf');

        $at = null;
        if ($varAT > 0 && IPS_VariableExists($varAT)) {
            $at = GetValue($varAT);
        }

        $vl = null;
        if ($configValid && $at !== null && $varVL > 0) {
            $vl = $this->CalculateVorlauf((float)$at, $minVL, $maxVL, $minAT, $maxAT, $startAT, $endAT);
        }

        return [
            'MinVorlauf' => $minVL,
            'MaxVorlauf' => $maxVL,
            'MinAT' => $minAT,
            'MaxAT' => $maxAT,
            'StartAT' => $startAT,
            'EndAT' => $endAT,
            'VLScaleMin' => $this->ReadPropertyFloat('VLScaleMin'),
            'VLScaleMax' => $this->ReadPropertyFloat('VLScaleMax'),
            'AT' => $at,
            'VL' => $vl
        ];
    }

    // Zustand als JSON-Text an die offenen Kacheln. Laesst er sich nicht kodieren (z. B. ungueltiges UTF-8 in
    // einer Text-Variable als Aussentemperatur), geht nichts hinaus statt eines false.
    private function SendState(array $state): void
    {
        $json = json_encode($state);
        if ($json === false) {
            $this->SendDebug('SendState', 'State not encodable: ' . json_last_error_msg(), 0);
            return;
        }
        // Ein unveraenderter Zustand geht kein zweites Mal hinaus
        $this->SendUpdateIfChanged('State', $json);
    }

    // Schickt die Nachrichten eines Schluessels nur, wenn sie sich von den zuletzt dazu gesendeten unterscheiden.
    // Die Pruefwerte (md5) stehen im Puffer UpdateHashes; ApplyChanges und der Erstaufbau leeren ihn.
    private function SendUpdateIfChanged(string $key, string ...$messages): void
    {
        $hashes = json_decode($this->GetBuffer('UpdateHashes'), true);
        $hashes = is_array($hashes) ? $hashes : [];
        $hash = md5(serialize($messages));
        if (($hashes[$key] ?? null) === $hash) {
            return;
        }
        foreach ($messages as $message) {
            $this->UpdateVisualizationValue($message);
        }
        $hashes[$key] = $hash;
        $this->SetBuffer('UpdateHashes', (string)json_encode($hashes));
    }

    private function WriteTargetIfChanged(int $varID, float $value): void
    {
        if (!IPS_VariableExists($varID)) {
            $this->SendDebug('WriteTarget', 'Variable does not exist: ' . $varID, 0);
            return;
        }
        $cur = GetValue($varID);
        if (!is_float($cur) && !is_int($cur)) {
            $cur = null;
        }
        $diff = ($cur === null) ? PHP_FLOAT_MAX : abs((float)$cur - $value);
        $this->SendDebug('WriteTarget', sprintf('Current=%s, New=%.1f, Diff=%.3f', $cur === null ? 'null' : number_format((float)$cur, 1), $value, $diff), 0);
        if ($cur !== null && $diff <= 0.001) {
            $this->SendDebug('WriteTarget', 'Value unchanged, skipping write', 0);
            return;
        }

        // Prefer RequestAction if an action is available on the variable; fallback to SetValue
        $varInfo = IPS_GetVariable($varID);
        $custom = isset($varInfo['VariableCustomAction']) ? (int)$varInfo['VariableCustomAction'] : 0;
        $action = isset($varInfo['VariableAction']) ? (int)$varInfo['VariableAction'] : 0;
        $actionID = $custom > 0 ? $custom : $action;
        $hasValidAction = ($actionID > 0) && (IPS_ScriptExists($actionID) || IPS_InstanceExists($actionID));

        $ok = false;
        if ($hasValidAction) {
            $this->SendDebug('WriteTarget', 'Using RequestAction for VarID=' . $varID, 0);
            // Suppress expected warning if the action is not valid and verify via readback
            @RequestAction($varID, $value);
            $after = GetValue($varID);
            if ((is_float($after) || is_int($after)) && abs((float)$after - $value) <= 0.001) {
                $ok = true;
                $this->SendDebug('WriteTarget', 'RequestAction SUCCESS (verified by readback)', 0);
            } else {
                $this->SendDebug('WriteTarget', 'RequestAction did not apply value (will fallback)', 0);
            }
        } else {
            $this->SendDebug('WriteTarget', 'No valid action target for VarID=' . $varID, 0);
        }

        if (!$ok) {
            // Try direct call on parent instance RequestAction using variable Ident
            $obj = IPS_GetObject($varID);
            $parentID = isset($obj['ParentID']) ? (int)$obj['ParentID'] : 0;
            $ident = isset($obj['ObjectIdent']) ? (string)$obj['ObjectIdent'] : '';
            $isInstanceOwned = ($parentID > 0 && IPS_InstanceExists($parentID));

            if ($isInstanceOwned && $ident !== '') {
                $this->SendDebug('WriteTarget', 'Trying IPS_RequestAction on ParentID=' . $parentID . ', Ident=' . $ident, 0);
                @IPS_RequestAction($parentID, $ident, $value);
                $after2 = GetValue($varID);
                if ((is_float($after2) || is_int($after2)) && abs((float)$after2 - $value) <= 0.001) {
                    $ok = true;
                    $this->SendDebug('WriteTarget', 'IPS_RequestAction SUCCESS (verified by readback)', 0);
                } else {
                    $this->SendDebug('WriteTarget', 'IPS_RequestAction did not apply value', 0);
                }
            }

            // Final fallback: only when no action exists
            if (!$ok) {
                if (!$hasValidAction) {
                    $this->SendDebug('WriteTarget', 'Falling back to SetValue for VarID=' . $varID . ' (no action present)', 0);
                    $ok = @SetValue($varID, $value);
                    $this->SendDebug('WriteTarget', 'SetValue ' . ($ok ? 'SUCCESS' : 'FAILED'), 0);
                } else {
                    $this->SendDebug('WriteTarget', 'Action exists but write failed; skipping SetValue', 0);
                }
            }
        }
    }

    private function CalculateVorlauf(float $at, float $minVL, float $maxVL, float $minAT, float $maxAT, ?float $startAT = null, ?float $endAT = null): float
    {
        // Fallback if no AT span
        if ($minAT === $maxAT) {
            return $minVL;
        }

        // If breakpoints are provided, use piecewise mapping with plateaus
        if ($startAT !== null && $endAT !== null) {
            // Ensure ordering: endAT <= startAT
            if ($endAT > $startAT) {
                $tmp = $endAT; $endAT = $startAT; $startAT = $tmp;
            }
            if ($at >= $startAT) {
                $vl = $minVL;
            } elseif ($at <= $endAT) {
                $vl = $maxVL;
            } else {
                $t = ($at - $endAT) / ($startAT - $endAT); // 0 at endAT, 1 at startAT
                $vl = $maxVL + $t * ($minVL - $maxVL);
            }
        } else {
            // Linear mapping without plateaus
            $ratio = ($at - $maxAT) / ($minAT - $maxAT);
            $vl = $minVL + $ratio * ($maxVL - $minVL);
        }

        // Clamp to [minVL, maxVL]
        if ($vl < $minVL) {
            $vl = $minVL;
        } elseif ($vl > $maxVL) {
            $vl = $maxVL;
        }
        // Round to 1 K
        return round($vl);
    }

    // HTML-SDK: Provide the Tile content
    public function GetVisualizationTile(): string
    {
        $templatePath = __DIR__ . '/module.html';
        $html = @file_get_contents($templatePath);
        if ($html === false) {
            // Template missing: log and return empty
            $this->SendDebug('GetVisualizationTile', 'module.html not found', 0);
            return '';
        }

        // Anfangszustand inline im Dokument. Frueher ging er per UpdateVisualizationValue an ALLE offenen Kacheln,
        // bei jedem Oeffnen irgendeiner Kachel, und die sich oeffnende Kachel fragte bis zu 50-mal per Init nach.
        // Derselbe Zustand wie bei jeder Aktualisierung (Laufzeitwerte RT_*), ohne den Soll-Vorlauf zu schreiben.
        // Der JSON-Text steht als JS-Stringliteral da; JSON_HEX_TAG: kein </script> aus einem Wert im Skriptblock.
        // Erstaufbau: danach geht die naechste Aktualisierung wieder hinaus, auch wenn sie der letzten gleicht.
        $this->SetBuffer('UpdateHashes', '');
        $state = json_encode($this->BuildState(true));
        if ($state === false) {
            $this->SendDebug('GetVisualizationTile', 'State not encodable: ' . json_last_error_msg(), 0);
            return $html;
        }
        return $html . '<script>handleMessage(' . json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP) . ');</script>';
    }
}
