<?php

declare(strict_types=1);

class Energierechner extends IPSModuleStrict
{
    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        // Properties
        $this->RegisterPropertyInteger('SourceVariable', 0);
        $this->RegisterPropertyInteger('BasePriceVariable', 0);
        $this->RegisterPropertyInteger('EnergyPriceVariable', 0);
        $this->RegisterPropertyInteger('UpdateInterval', 5);
        
        $this->RegisterPropertyBoolean('IncludeBasePrice', true);
        $this->RegisterPropertyBoolean('EnableWeek', true);
        $this->RegisterPropertyBoolean('EnableMonth', true);
        $this->RegisterPropertyBoolean('EnableYear', true);

        // Timer
        $this->RegisterTimer('UpdateTimer', 0, 'EC_UpdateCalculator($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();
        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $ref_SourceVariable = $this->ReadPropertyInteger('SourceVariable');
        if ($ref_SourceVariable > 1 && @IPS_ObjectExists($ref_SourceVariable)) {
            $this->RegisterReference($ref_SourceVariable);
        }
        $ref_BasePriceVariable = $this->ReadPropertyInteger('BasePriceVariable');
        if ($ref_BasePriceVariable > 1 && @IPS_ObjectExists($ref_BasePriceVariable)) {
            $this->RegisterReference($ref_BasePriceVariable);
        }
        $ref_EnergyPriceVariable = $this->ReadPropertyInteger('EnergyPriceVariable');
        if ($ref_EnergyPriceVariable > 1 && @IPS_ObjectExists($ref_EnergyPriceVariable)) {
            $this->RegisterReference($ref_EnergyPriceVariable);
        }
        // ---------------------------------

        // Maintain Variables
        $enableWeek = $this->ReadPropertyBoolean('EnableWeek');
        $enableMonth = $this->ReadPropertyBoolean('EnableMonth');
        $enableYear = $this->ReadPropertyBoolean('EnableYear');

        $this->MaintainVariable('ConsumptionDay', 'Verbrauch (Heute)', 2, '', 10, true);
        $this->MaintainVariable('CostDay', 'Kosten (Heute)', 2, '', 50, true);

        $this->MaintainVariable('ConsumptionWeek', 'Verbrauch (Woche)', 2, '', 20, $enableWeek);
        $this->MaintainVariable('CostWeek', 'Kosten (Woche)', 2, '', 60, $enableWeek);

        $this->MaintainVariable('ConsumptionMonth', 'Verbrauch (Monat)', 2, '', 30, $enableMonth);
        $this->MaintainVariable('CostMonth', 'Kosten (Monat)', 2, '', 70, $enableMonth);

        $this->MaintainVariable('ConsumptionYear', 'Verbrauch (Jahr)', 2, '', 40, $enableYear);
        $this->MaintainVariable('CostYear', 'Kosten (Jahr)', 2, '', 80, $enableYear);

        // Apply Custom Presentations instead of Legacy Profiles
        if (function_exists('IPS_SetVariableCustomPresentation')) {
            $presentationType = defined('VARIABLE_PRESENTATION_VALUE_PRESENTATION') ? VARIABLE_PRESENTATION_VALUE_PRESENTATION : 1;
            
            $periods = [
                'Day' => true,
                'Week' => $enableWeek,
                'Month' => $enableMonth,
                'Year' => $enableYear
            ];

            foreach ($periods as $period => $enabled) {
                if ($enabled) {
                    IPS_SetVariableCustomPresentation($this->GetIDForIdent('Consumption' . $period), [
                        'PRESENTATION' => $presentationType,
                        'SUFFIX' => ' kWh',
                        'ICON' => 'Electricity'
                    ]);
                    IPS_SetVariableCustomPresentation($this->GetIDForIdent('Cost' . $period), [
                        'PRESENTATION' => $presentationType,
                        'SUFFIX' => ' €',
                        'ICON' => 'Euro'
                    ]);
                }
            }
        }

        // Set Timer
        $interval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('UpdateTimer', $interval * 60 * 1000);

        // Initial Update
        $this->UpdateCalculator();
    }

    public function UpdateCalculator(): void
    {
        $sourceVar = $this->ReadPropertyInteger('SourceVariable');
        $basePriceVar = $this->ReadPropertyInteger('BasePriceVariable');
        $energyPriceVar = $this->ReadPropertyInteger('EnergyPriceVariable');
        
        $includeBasePrice = $this->ReadPropertyBoolean('IncludeBasePrice');
        $enableWeek = $this->ReadPropertyBoolean('EnableWeek');
        $enableMonth = $this->ReadPropertyBoolean('EnableMonth');
        $enableYear = $this->ReadPropertyBoolean('EnableYear');

        if ($sourceVar == 0 || !IPS_VariableExists($sourceVar)) {
            $this->SetStatus(104); // Instanz ist inaktiv (Variable fehlt)
            return;
        }

        $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (empty($archiveIDs)) {
            $this->SetStatus(201); // Fehler: Kein Archiv
            return;
        }
        $archiveID = $archiveIDs[0];

        if (!AC_GetLoggingStatus($archiveID, $sourceVar)) {
            $this->SetStatus(201); // Fehler: Variable ist nicht geloggt
            return;
        }
        
        $this->SetStatus(102); // IS_ACTIVE

        // Fetch Tariffs
        $basePriceYear = 0.0;
        if ($includeBasePrice && $basePriceVar > 0 && IPS_VariableExists($basePriceVar)) {
            $basePriceYear = (float)GetValue($basePriceVar);
        }

        $energyPriceCent = 0.0;
        if ($energyPriceVar > 0 && IPS_VariableExists($energyPriceVar)) {
            $energyPriceCent = (float)GetValue($energyPriceVar);
        }
        
        $energyPriceEuro = $energyPriceCent / 100.0;

        // Day Calculation
        $dayAggr = @AC_GetAggregatedValues($archiveID, $sourceVar, 1, strtotime('today 00:00:00'), time(), 0);
        $consumptionDay = (is_array($dayAggr) && count($dayAggr) > 0) ? (float)$dayAggr[0]['Avg'] : 0.0;
        $costDay = ($consumptionDay * $energyPriceEuro) + ($basePriceYear / 365.25);
        $this->SetValue('ConsumptionDay', $consumptionDay);
        $this->SetValue('CostDay', $costDay);

        // Week Calculation
        if ($enableWeek) {
            $weekStart = strtotime('monday this week 00:00:00');
            if (date('N') == 1) { // If today is Monday
                $weekStart = strtotime('today 00:00:00');
            }
            $weekAggr = @AC_GetAggregatedValues($archiveID, $sourceVar, 2, $weekStart, time(), 0);
            $consumptionWeek = (is_array($weekAggr) && count($weekAggr) > 0) ? (float)$weekAggr[0]['Avg'] : 0.0;
            $costWeek = ($consumptionWeek * $energyPriceEuro) + ($basePriceYear / 52.1429);
            $this->SetValue('ConsumptionWeek', $consumptionWeek);
            $this->SetValue('CostWeek', $costWeek);
        }

        // Month Calculation
        if ($enableMonth) {
            $monthStart = strtotime('first day of this month 00:00:00');
            $monthAggr = @AC_GetAggregatedValues($archiveID, $sourceVar, 3, $monthStart, time(), 0);
            $consumptionMonth = (is_array($monthAggr) && count($monthAggr) > 0) ? (float)$monthAggr[0]['Avg'] : 0.0;
            $daysInMonth = (int)date('t');
            $costMonth = ($consumptionMonth * $energyPriceEuro) + (($basePriceYear / 365.25) * $daysInMonth);
            $this->SetValue('ConsumptionMonth', $consumptionMonth);
            $this->SetValue('CostMonth', $costMonth);
        }

        // Year Calculation
        if ($enableYear) {
            $yearStart = strtotime('first day of January this year 00:00:00');
            $yearAggr = @AC_GetAggregatedValues($archiveID, $sourceVar, 4, $yearStart, time(), 0);
            $consumptionYear = (is_array($yearAggr) && count($yearAggr) > 0) ? (float)$yearAggr[0]['Avg'] : 0.0;
            $dayOfYear = (int)date('z') + 1; 
            $costYear = ($consumptionYear * $energyPriceEuro) + (($basePriceYear / 365.25) * $dayOfYear);
            $this->SetValue('ConsumptionYear', $consumptionYear);
            $this->SetValue('CostYear', $costYear);
        }
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "caption": "Verbrauchsvariable (Zähler, in kWh):"
        },
        {
            "type": "SelectVariable",
            "name": "SourceVariable",
            "caption": "Gesamtverbrauch"
        },
        {
            "type": "Label",
            "caption": "Tarif Variablen:"
        },
        {
            "type": "SelectVariable",
            "name": "BasePriceVariable",
            "caption": "Grundpreis (€/Jahr)"
        },
        {
            "type": "SelectVariable",
            "name": "EnergyPriceVariable",
            "caption": "Arbeitspreis (Cent/kWh)"
        },
        {
            "type": "Label",
            "caption": "Einstellungen:"
        },
        {
            "type": "CheckBox",
            "name": "IncludeBasePrice",
            "caption": "Grundpreis in die Kosten einrechnen"
        },
        {
            "type": "CheckBox",
            "name": "EnableWeek",
            "caption": "Verbrauch/Kosten für Woche berechnen"
        },
        {
            "type": "CheckBox",
            "name": "EnableMonth",
            "caption": "Verbrauch/Kosten für Monat berechnen"
        },
        {
            "type": "CheckBox",
            "name": "EnableYear",
            "caption": "Verbrauch/Kosten für Jahr berechnen"
        },
        {
            "type": "NumberSpinner",
            "name": "UpdateInterval",
            "caption": "Aktualisierungs-Intervall (Minuten)",
            "minimum": 1,
            "maximum": 1440
        }
    ],
    "actions": [
        {
            "type": "Button",
            "label": "Jetzt berechnen",
            "onClick": "EC_UpdateCalculator($id);"
        }
    ],
    "status": [
        {
            "code": 102,
            "icon": "active",
            "caption": "Aktiv"
        },
        {
            "code": 104,
            "icon": "inactive",
            "caption": "Inaktiv (Verbrauchsvariable fehlt)"
        },
        {
            "code": 201,
            "icon": "error",
            "caption": "Fehler: Die gewählte Verbrauchsvariable muss im Archive Control (als Zähler) geloggt werden."
        }
    ]
}
EOT;
    }
}
