# TileVisu Heatingcurve

IP-Symcon module providing an HTML-SDK Tile to visualize and adjust a linear heating curve with ± controls. No cyclic timers; updates on VM_UPDATE of the outside temperature and on configuration/actions.

## Features
- Linear mapping AT→VL with endpoints:
  - AT = MinAT → VL = MaxVorlauf
  - AT = MaxAT → VL = MinVorlauf
- Clamp VL to [MinVorlauf, MaxVorlauf], round to 0.5 K
- Live updates to the tile via UpdateVisualizationValue
- Simple ± buttons to change Min/Max for VL and AT (0.5 steps)

## Properties
- MinVorlauf (°C) default 25
- MaxVorlauf (°C) default 55
- MinAT (°C) default -10
- MaxAT (°C) default +15
- Var_Aussentemperatur (variable id)
- Var_SollVorlauf (variable id)

Rules: MinVorlauf < MaxVorlauf, MinAT < MaxAT. Both variables must be set and the target must be writable.

## Events
- Registers VM_UPDATE on Var_Aussentemperatur in ApplyChanges. Handled in MessageSink.
- Writes VL target only when changed (respects VariableAction if present).

## Visualization
Implements SetVisualizationType + GetVisualizationTile(). The tile exposes handleMessage(payload) and uses requestAction(ident, value) for ± controls.

## Installation
- Copy the module into your IP-Symcon modules directory.
- Create an instance of "TileVisu Heatingcurve".
- Configure properties and select the two variables.

## Notes
- No timers are used. All updates are driven by VM_UPDATE or configuration/actions.
