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
        $this->RegisterPropertyInteger('Var_Aussentemperatur', 0);
        $this->RegisterPropertyInteger('Var_SollVorlauf', 0);

        // Attributes to manage message subscriptions
        $this->RegisterAttributeInteger('LastATVar', 0);

        // Enable HTML-SDK Tile visualization
        if (method_exists($this, 'SetVisualizationType')) {
            // 1 = Tile (HTML-SDK). If your IP-Symcon defines constants, they will be used by the runtime.
            @$this->SetVisualizationType(1);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Unregister previous message binding if present
        $lastAT = $this->ReadAttributeInteger('LastATVar');
        if ($lastAT > 0) {
            // Unsubscribe from previous variable VM_UPDATE
            @$this->UnregisterMessage($lastAT, VM_UPDATE);
        }

        // Validate properties
        $minVL = (float)$this->ReadPropertyFloat('MinVorlauf');
        $maxVL = (float)$this->ReadPropertyFloat('MaxVorlauf');
        $minAT = (float)$this->ReadPropertyFloat('MinAT');
        $maxAT = (float)$this->ReadPropertyFloat('MaxAT');
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

        // Register to VM_UPDATE of Außentemperatur variable
        if ($varAT > 0) {
            @$this->RegisterMessage($varAT, VM_UPDATE);
            $this->WriteAttributeInteger('LastATVar', $varAT);
        } else {
            $this->WriteAttributeInteger('LastATVar', 0);
        }

        // Perform initial calculation and push visualization state
        $this->RecalculateAndPush($valid);
    }

    public function Destroy()
    {
        // Clean up
        $lastAT = $this->ReadAttributeInteger('LastATVar');
        if ($lastAT > 0) {
            @$this->UnregisterMessage($lastAT, VM_UPDATE);
        }
        parent::Destroy();
    }

    // Handle visualization actions from the HTML tile (idents: MinVL, MaxVL, MinAT, MaxAT)
    public function RequestAction($Ident, $Value)
    {
        // Value is expected to be a float delta (e.g., +0.5 / -0.5)
        $delta = (float)$Value;

        $minVL = (float)$this->ReadPropertyFloat('MinVorlauf');
        $maxVL = (float)$this->ReadPropertyFloat('MaxVorlauf');
        $minAT = (float)$this->ReadPropertyFloat('MinAT');
        $maxAT = (float)$this->ReadPropertyFloat('MaxAT');

        switch ($Ident) {
            case 'MinVL':
                $minVL = round(($minVL + $delta) * 2) / 2.0;
                break;
            case 'MaxVL':
                $maxVL = round(($maxVL + $delta) * 2) / 2.0;
                break;
            case 'MinAT':
                $minAT = round(($minAT + $delta) * 2) / 2.0;
                break;
            case 'MaxAT':
                $maxAT = round(($maxAT + $delta) * 2) / 2.0;
                break;
            default:
                throw new Exception('Unknown Ident: ' . $Ident);
        }

        // Enforce rules: Min < Max
        if (!($minVL < $maxVL)) {
            // Adjust by nudging the opposite bound
            if ($Ident === 'MinVL') {
                $maxVL = $minVL + 0.5;
            } else {
                $minVL = $maxVL - 0.5;
            }
        }
        if (!($minAT < $maxAT)) {
            if ($Ident === 'MinAT') {
                $maxAT = $minAT + 0.5;
            } else {
                $minAT = $maxAT - 0.5;
            }
        }

        // Persist new properties
        IPS_SetProperty($this->InstanceID, 'MinVorlauf', $minVL);
        IPS_SetProperty($this->InstanceID, 'MaxVorlauf', $maxVL);
        IPS_SetProperty($this->InstanceID, 'MinAT', $minAT);
        IPS_SetProperty($this->InstanceID, 'MaxAT', $maxAT);
        IPS_ApplyChanges($this->InstanceID);
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
        $minVL = (float)$this->ReadPropertyFloat('MinVorlauf');
        $maxVL = (float)$this->ReadPropertyFloat('MaxVorlauf');
        $minAT = (float)$this->ReadPropertyFloat('MinAT');
        $maxAT = (float)$this->ReadPropertyFloat('MaxAT');
        $varAT = (int)$this->ReadPropertyInteger('Var_Aussentemperatur');
        $varVL = (int)$this->ReadPropertyInteger('Var_SollVorlauf');

        $at = null;
        if ($varAT > 0 && IPS_VariableExists($varAT)) {
            $at = GetValueFloat($varAT);
        }

        $vl = null;
        if ($configValid && $at !== null && $varVL > 0) {
            $vl = $this->CalculateVorlauf((float)$at, $minVL, $maxVL, $minAT, $maxAT);
            $this->WriteTargetIfChanged($varVL, $vl);
        }

        // Push visualization update
        $payload = [
            'MinVorlauf' => $minVL,
            'MaxVorlauf' => $maxVL,
            'MinAT' => $minAT,
            'MaxAT' => $maxAT,
            'AT' => $at,
            'VL' => $vl
        ];
        if (method_exists($this, 'UpdateVisualizationValue')) {
            @$this->UpdateVisualizationValue(json_encode($payload));
        }
    }

    private function WriteTargetIfChanged(int $varID, float $value): void
    {
        if (!IPS_VariableExists($varID)) {
            return;
        }
        $cur = @GetValue($varID);
        if (!is_float($cur) && !is_int($cur)) {
            $cur = null;
        }
        if ($cur === null || abs((float)$cur - $value) > 0.001) {
            // Prefer RequestAction if available
            $actionID = @IPS_GetVariable($varID)['VariableCustomAction'] ?? 0;
            if ($actionID === 0) {
                $actionID = @IPS_GetVariable($varID)['VariableAction'] ?? 0;
            }
            if ($actionID > 0) {
                @RequestAction($varID, $value);
            } else {
                @SetValue($varID, $value);
            }
        }
    }

    private function CalculateVorlauf(float $at, float $minVL, float $maxVL, float $minAT, float $maxAT): float
    {
        // ratio = (AT - MaxAT) / (MinAT - MaxAT)   // 0..1
        if ($minAT === $maxAT) {
            return $minVL; // Fallback
        }
        $ratio = ($at - $maxAT) / ($minAT - $maxAT);
        // Clamp 0..1 for the valid range, but we allow extrapolation via clamping later on VL
        $vl = $minVL + $ratio * ($maxVL - $minVL);
        // Clamp to [minVL, maxVL]
        if ($vl < $minVL) {
            $vl = $minVL;
        } elseif ($vl > $maxVL) {
            $vl = $maxVL;
        }
        // Round to 0.5 K
        return round($vl * 2) / 2.0;
    }

    // HTML-SDK: Provide the Tile content
    public function GetVisualizationTile()
    {
        $minVL = (float)$this->ReadPropertyFloat('MinVorlauf');
        $maxVL = (float)$this->ReadPropertyFloat('MaxVorlauf');
        $minAT = (float)$this->ReadPropertyFloat('MinAT');
        $maxAT = (float)$this->ReadPropertyFloat('MaxAT');
        $varAT = (int)$this->ReadPropertyInteger('Var_Aussentemperatur');
        $at = ($varAT > 0 && IPS_VariableExists($varAT)) ? GetValueFloat($varAT) : null;
        $vl = null;
        if ($at !== null) {
            $vl = $this->CalculateVorlauf((float)$at, $minVL, $maxVL, $minAT, $maxAT);
        }

        $payload = json_encode([
            'MinVorlauf' => $minVL,
            'MaxVorlauf' => $maxVL,
            'MinAT' => $minAT,
            'MaxAT' => $maxAT,
            'AT' => $at,
            'VL' => $vl
        ]);

        $html = <<<HTML
<!DOCTYPE html>
<meta charset="utf-8" />
<style>
  .tvhc { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; color: #eee; background:#222; padding: 10px; border-radius: 8px; }
  .row { display:flex; align-items:center; justify-content:space-between; margin:6px 0; }
  .label { opacity: .8; }
  .ctrl { display:flex; align-items:center; gap:8px; }
  button { width:28px; height:28px; border-radius:6px; border:1px solid #555; background:#333; color:#eee; cursor:pointer; }
  button:hover { background:#3a3a3a; }
  .val { min-width:64px; text-align:center; font-weight:600; }
  .status { margin-top:10px; font-size: 12px; opacity:.9; }
  .mini { margin-top:8px; height:36px; }
  svg { width:100%; height:36px; }
</style>
<div class="tvhc">
  <div class="row">
    <div class="label" data-i18n="MinVorlauf">Min Vorlauf</div>
    <div class="ctrl">
      <button data-ident="MinVL" data-delta="-0.5">−</button>
      <div class="val" id="val-MinVL">--</div>
      <button data-ident="MinVL" data-delta="+0.5">+</button>
    </div>
  </div>
  <div class="row">
    <div class="label" data-i18n="MaxVorlauf">Max Vorlauf</div>
    <div class="ctrl">
      <button data-ident="MaxVL" data-delta="-0.5">−</button>
      <div class="val" id="val-MaxVL">--</div>
      <button data-ident="MaxVL" data-delta="+0.5">+</button>
    </div>
  </div>
  <div class="row">
    <div class="label" data-i18n="MinAT">Min Außentemp</div>
    <div class="ctrl">
      <button data-ident="MinAT" data-delta="-0.5">−</button>
      <div class="val" id="val-MinAT">--</div>
      <button data-ident="MinAT" data-delta="+0.5">+</button>
    </div>
  </div>
  <div class="row">
    <div class="label" data-i18n="MaxAT">Max Außentemp</div>
    <div class="ctrl">
      <button data-ident="MaxAT" data-delta="-0.5">−</button>
      <div class="val" id="val-MaxAT">--</div>
      <button data-ident="MaxAT" data-delta="+0.5">+</button>
    </div>
  </div>

  <div class="status" id="status"></div>
  <div class="mini">
    <svg viewBox="0 0 100 36" preserveAspectRatio="none">
      <polyline id="curve" fill="none" stroke="#6cf" stroke-width="2" points="0,0 100,0" />
      <circle id="ptAT" r="2.5" fill="#fc6" cx="0" cy="0" />
    </svg>
  </div>
</div>
<script>
(function(){
  const $ = (id)=>document.getElementById(id);

  function fmt(v, unit){
    if (v === null || v === undefined) return '--';
    return (Math.round(v*2)/2).toFixed(1) + unit;
  }

  function drawMini(p){
    const x0=0, x1=100, y0=30, y1=6; // invert for display
    // line from (MinAT -> MaxVorlauf) to (MaxAT -> MinVorlauf)
    const pts = `${x0},${y0} ${x1},${y1}`;
    document.getElementById('curve').setAttribute('points', pts);
    if (p.AT !== null && p.AT !== undefined) {
      const ratio = (p.AT - p.MaxAT) / (p.MinAT - p.MaxAT);
      const x = x0 + (x1 - x0) * ratio;
      const y = y1 + (y0 - y1) * ( (p.VL - p.MinVorlauf) / (p.MaxVorlauf - p.MinVorlauf) );
      document.getElementById('ptAT').setAttribute('cx', Math.max(0, Math.min(100, x)));
      document.getElementById('ptAT').setAttribute('cy', Math.max(0, Math.min(36, y)));
    }
  }

  function setVals(p){
    $('val-MinVL').textContent = fmt(p.MinVorlauf, ' °C');
    $('val-MaxVL').textContent = fmt(p.MaxVorlauf, ' °C');
    $('val-MinAT').textContent = fmt(p.MinAT, ' °C');
    $('val-MaxAT').textContent = fmt(p.MaxAT, ' °C');
    const status = [];
    status.push((typeof translate === 'function' ? translate('Außen aktuell') : 'Außen aktuell')+': '+fmt(p.AT,' °C'));
    status.push((typeof translate === 'function' ? translate('Soll-Vorlauf') : 'Soll-Vorlauf')+': '+fmt(p.VL,' °C'));
    $('status').textContent = status.join('   ·   ');
    drawMini(p);
  }

  // Button wiring
  document.querySelectorAll('button[data-ident]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const ident = btn.getAttribute('data-ident');
      const delta = parseFloat(btn.getAttribute('data-delta'));
      if (typeof requestAction === 'function') {
        requestAction(ident, delta);
      }
    });
  });

  // HTML-SDK entry point for live updates
  window.handleMessage = function(payload){
    try {
      const p = (typeof payload === 'string') ? JSON.parse(payload) : payload;
      setVals(p);
    } catch (e) {
      console.error('handleMessage parse error', e);
    }
  };

  // Initial payload injected from PHP
  handleMessage($payload$);
})();
</script>
HTML;

        $html = str_replace('$payload$', $payload, $html);
        return $html;
    }
}
