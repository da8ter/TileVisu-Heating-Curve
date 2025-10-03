<?php
declare(strict_types=1);

class TilVisuHeatingCurve extends IPSModule
{
    public function Create()
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

        // Attributes to manage message subscriptions
        $this->RegisterAttributeInteger('LastATVar', 0);

        // Attributes for runtime curve parameters (overridable via RequestAction)
        $this->RegisterAttributeFloat('RT_MinVorlauf', 0.0);
        $this->RegisterAttributeFloat('RT_MaxVorlauf', 0.0);
        $this->RegisterAttributeFloat('RT_MinAT', 0.0);
        $this->RegisterAttributeFloat('RT_MaxAT', 0.0);
        $this->RegisterAttributeFloat('RT_StartAT', 0.0);
        $this->RegisterAttributeFloat('RT_EndAT', 0.0);

        // Enable HTML-SDK Tile visualization
        if (method_exists($this, 'SetVisualizationType')) {
            $this->SetVisualizationType(1);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Initialize runtime attributes from properties
        $this->WriteAttributeFloat('RT_MinVorlauf', (float)$this->ReadPropertyFloat('MinVorlauf'));
        $this->WriteAttributeFloat('RT_MaxVorlauf', (float)$this->ReadPropertyFloat('MaxVorlauf'));
        $this->WriteAttributeFloat('RT_MinAT', (float)$this->ReadPropertyFloat('MinAT'));
        $this->WriteAttributeFloat('RT_MaxAT', (float)$this->ReadPropertyFloat('MaxAT'));
        $this->WriteAttributeFloat('RT_StartAT', (float)$this->ReadPropertyFloat('StartAT'));
        $this->WriteAttributeFloat('RT_EndAT', (float)$this->ReadPropertyFloat('EndAT'));

        // Unregister previous message binding if present
        $lastAT = $this->ReadAttributeInteger('LastATVar');
        if ($lastAT > 0) {
            // Unsubscribe from previous variable VM_UPDATE
            $this->UnregisterMessage($lastAT, VM_UPDATE);
        }

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

        // Register to VM_UPDATE of Außentemperatur variable
        if ($varAT > 0) {
            $this->RegisterMessage($varAT, VM_UPDATE);
            $this->WriteAttributeInteger('LastATVar', $varAT);
        } else {
            $this->WriteAttributeInteger('LastATVar', 0);
        }

        // Perform initial calculation and push visualization state
        $this->RecalculateAndPush($valid);
    }

    public function Destroy()
    {
        // Clean up (only if kernel is ready; InstanceInterface may be unavailable during shutdown)
        if (function_exists('IPS_GetKernelRunlevel') && defined('KR_READY') && IPS_GetKernelRunlevel() == KR_READY) {
            $lastAT = $this->ReadAttributeInteger('LastATVar');
            if ($lastAT > 0) {
                $this->UnregisterMessage($lastAT, VM_UPDATE);
            }
        }
        parent::Destroy();
    }

    // Handle visualization actions from the HTML tile (idents: MinVL, MaxVL, MinAT, MaxAT, StartAT, EndAT)
    public function RequestAction($Ident, $Value)
    {
        // Value is expected to be a float delta (e.g., +0.5 / -0.5)
        $delta = (float)$Value;

        // Initial handshake from frontend to request current payload after HTML has loaded
        if ($Ident === 'Init') {
            $this->SendDebug('RequestAction', 'Init received -> push current state', 0);
            $this->RecalculateAndPush(true);
            return;
        }

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
        // Push immediate visualization update with the new values (optimistic update)
        $varATId = (int)$this->ReadPropertyInteger('Var_Aussentemperatur');
        $atNow = null;
        if ($varATId > 0 && IPS_VariableExists($varATId)) {
            $atNow = GetValue($varATId);
        }
        $vlNow = null;
        if ($atNow !== null) {
            $vlNow = $this->CalculateVorlauf((float)$atNow, $minVL, $maxVL, $minAT, $maxAT, $startAT, $endAT);
        }
        $vlScaleMin = (float)$this->ReadPropertyFloat('VLScaleMin');
        $vlScaleMax = (float)$this->ReadPropertyFloat('VLScaleMax');
        if (method_exists($this, 'UpdateVisualizationValue')) {
            $this->UpdateVisualizationValue(json_encode([
                'MinVorlauf' => $minVL,
                'MaxVorlauf' => $maxVL,
                'MinAT' => $minAT,
                'MaxAT' => $maxAT,
                'StartAT' => $startAT,
                'EndAT' => $endAT,
                'VLScaleMin' => $vlScaleMin,
                'VLScaleMax' => $vlScaleMax,
                'AT' => $atNow,
                'VL' => $vlNow
            ]));
        }
        
        // Recalculate and write target flow temperature
        if ($atNow !== null && $vlNow !== null) {
            $varVL = (int)$this->ReadPropertyInteger('Var_SollVorlauf');
            if ($varVL > 0) {
                $this->WriteTargetIfChanged($varVL, $vlNow);
            }
        }
    }

    // Message sink for VM_UPDATE events
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === VM_UPDATE && $SenderID === (int)$this->ReadPropertyInteger('Var_Aussentemperatur')) {
            $this->SendDebug('Event', 'VM_UPDATE from Außentemperatur', 0);
            $this->RecalculateAndPush(true);
        }
    }

    private function RecalculateAndPush(bool $configValid): void
    {
        // Read curve parameters from runtime attributes
        $minVL = $this->ReadAttributeFloat('RT_MinVorlauf');
        $maxVL = $this->ReadAttributeFloat('RT_MaxVorlauf');
        $minAT = $this->ReadAttributeFloat('RT_MinAT');
        $maxAT = $this->ReadAttributeFloat('RT_MaxAT');
        $startAT = $this->ReadAttributeFloat('RT_StartAT');
        $endAT = $this->ReadAttributeFloat('RT_EndAT');
        $varAT = (int)$this->ReadPropertyInteger('Var_Aussentemperatur');
        $varVL = (int)$this->ReadPropertyInteger('Var_SollVorlauf');

        $at = null;
        if ($varAT > 0 && IPS_VariableExists($varAT)) {
            $at = GetValue($varAT);
        }

        $vl = null;
        if ($configValid && $at !== null && $varVL > 0) {
            $vl = $this->CalculateVorlauf((float)$at, $minVL, $maxVL, $minAT, $maxAT, $startAT, $endAT);
            $this->SendDebug('Calculate', sprintf('AT=%.1f -> VL=%.1f', $at, $vl), 0);
            $this->WriteTargetIfChanged($varVL, $vl);
        } else {
            $this->SendDebug('Skip', sprintf('configValid=%d, at=%s, varVL=%d', $configValid, $at === null ? 'null' : $at, $varVL), 0);
        }

        // Push visualization update
        $vlScaleMin = (float)$this->ReadPropertyFloat('VLScaleMin');
        $vlScaleMax = (float)$this->ReadPropertyFloat('VLScaleMax');
        $payload = [
            'MinVorlauf' => $minVL,
            'MaxVorlauf' => $maxVL,
            'MinAT' => $minAT,
            'MaxAT' => $maxAT,
            'StartAT' => $startAT,
            'EndAT' => $endAT,
            'VLScaleMin' => $vlScaleMin,
            'VLScaleMax' => $vlScaleMax,
            'AT' => $at,
            'VL' => $vl
        ];
        if (method_exists($this, 'UpdateVisualizationValue')) {
            $this->UpdateVisualizationValue(json_encode($payload));
        }
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
            $this->SendDebug('WriteTarget', 'No valid action target for VarID=' . $varID . ' (using SetValue)', 0);
        }

        if (!$ok) {
            $this->SendDebug('WriteTarget', 'Falling back to SetValue for VarID=' . $varID, 0);
            $ok = SetValue($varID, $value);
            $this->SendDebug('WriteTarget', 'SetValue ' . ($ok ? 'SUCCESS' : 'FAILED'), 0);
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
    public function GetVisualizationTile()
    {
        $minVL = (float)$this->ReadPropertyFloat('MinVorlauf');
        $maxVL = (float)$this->ReadPropertyFloat('MaxVorlauf');
        $minAT = (float)$this->ReadPropertyFloat('MinAT');
        $maxAT = (float)$this->ReadPropertyFloat('MaxAT');
        $startAT = (float)($this->ReadPropertyFloat('StartAT') ?? $maxAT);
        $endAT = (float)($this->ReadPropertyFloat('EndAT') ?? $minAT);
        $varAT = (int)$this->ReadPropertyInteger('Var_Aussentemperatur');
        $at = ($varAT > 0 && IPS_VariableExists($varAT)) ? GetValue($varAT) : null;
        $vl = null;
        if ($at !== null) {
            $vl = $this->CalculateVorlauf((float)$at, $minVL, $maxVL, $minAT, $maxAT, $startAT, $endAT);
        }

        $vlScaleMin = (float)$this->ReadPropertyFloat('VLScaleMin');
        $vlScaleMax = (float)$this->ReadPropertyFloat('VLScaleMax');
        $payload = json_encode([
            'MinVorlauf' => $minVL,
            'MaxVorlauf' => $maxVL,
            'MinAT' => $minAT,
            'MaxAT' => $maxAT,
            'StartAT' => $startAT,
            'EndAT' => $endAT,
            'VLScaleMin' => $vlScaleMin,
            'VLScaleMax' => $vlScaleMax,
            'AT' => $at,
            'VL' => $vl
        ]);

        $templatePath = __DIR__ . '/module.html';
        $html = @file_get_contents($templatePath);
        if ($html !== false) {
            if (method_exists($this, 'UpdateVisualizationValue')) {
                @$this->UpdateVisualizationValue($payload);
            }
            return $html;
        }
        // Template missing: log and return empty
        $this->SendDebug('GetVisualizationTile', 'module.html not found', 0);
        return '';
    }
}
