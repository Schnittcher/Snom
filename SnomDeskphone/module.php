<?php

require_once("phoneProperties.php");

class SnomDeskphone extends IPSModuleStrict {
    public function Create(): void {
        parent::Create();
        $this->RegisterHook("snom/" . $this->InstanceID);
        $this->RegisterPropertyString("PhoneIP", "");
        $this->RegisterPropertyString("PhoneMac", "000413");
        $this->RegisterPropertyString("PhoneModel", "");
        $this->RegisterPropertyString("LocalIP", "127.0.0.1");
        $this->RegisterPropertyString("FkeysSettings", "[]");
    }

    public function ApplyChanges(): void {
        parent::ApplyChanges();
        $this->SetSummary($this->ReadPropertyString("PhoneModel") . "/" . $this->ReadPropertyString("PhoneMac"));
        // Transfer to Phone
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        $fkeysSettings = json_decode($this->ReadPropertyString("FkeysSettings"), true);
        $fkeysToUpdate = $this->GetFkeysToUpdate($fkeysSettings, $SenderID, $Data);
        $this->UpdateFkeys($fkeysToUpdate);
    }

    protected function GetFkeysToUpdate(array $fkeysSettings, int $SenderID, array $SenderData): array {
        $fkeysToUpdate = array();

        foreach ($fkeysSettings as $fkey => $settings) {
            if ($settings["ActionVariableId"]==$SenderID) {
                $fkeyNo = (int)$settings["FkeyNo"] - 1;
                $SenderValue = $SenderData[0] ? "On" : "Off";
                $fkeysToUpdate[$fkeyNo] = array(
                    "ledNo" => PhoneProperties::getFkeyLedNo($this->ReadPropertyString("PhoneModel"), $fkeyNo),
                    "color" => $settings["FkeyColor" . $SenderValue]
                );
            }
        }

        return $fkeysToUpdate;
    }

    protected function UpdateFkeys(array $fkeysToUpdate): void {
        $this->SendDebug("FKEYS TO UPDATE", print_r($fkeysToUpdate, true), 0);
        $instanceHook = sprintf("http://%s:3777/hook/snom/%d/", $this->ReadPropertyString("LocalIP"), $this->InstanceID);

        foreach ($fkeysToUpdate as $fkeyNo => $data) {
            $hookParameters = urlencode(
                $instanceHook . 
                "?xml=true&variableId=" . $SenderID . 
                "&value=" . (int)$Data[0] . 
                "&ledNo=" . $data["ledNo"] . 
                "&color=" . $data["color"]
            );
            $RenderRemoteUrl = sprintf("http://%s/minibrowser.htm?url=%s", $this->ReadPropertyString("PhoneIP"), $hookParameters);
            file_get_contents($RenderRemoteUrl);
        }
    }

    /**
    * This function will be called by the hook control. Visibility should be protected!
    */
    protected function ProcessHookData(): void {
        $this->SendDebug("GET", print_r($_GET, true), 0);
        $value = $_GET["value"];
        $variableId = $_GET["variableId"];

        if (filter_var($_GET["xml"], FILTER_VALIDATE_BOOLEAN)) {
            // status
            // $value ? $ledValue="On" : $ledValue="Off";
            $ledValue = ($_GET["color"]==="none") ? "Off" : "On";
            $text = $variableId . " = " . $value;
            header("Content-Type: text/xml");
            $xml = $this->GetIPPhoneTextItem($text, $ledValue, $_GET["ledNo"], $_GET["color"]);
            $this->SendDebug("XML", print_r($xml, true), 0);
            echo $xml;
        }
        else {
            //write
            $this->SendDebug("HOOK [ACTION]", print_r($variableId . "  = " . $value, true), 0);
            RequestAction((int)$variableId, $value);
        }
    }

    private function GetIPPhoneTextItem(string $text, string $ledValue, int $ledNo, string $color, int $timeout=1): string {
        header("Content-Type: text/xml");
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $xmlRoot = $xml->appendChild($xml->createElement("SnomIPPhoneText"));

        //text tag
        $xmlRoot->appendChild($xml->createElement('Text', $text));

        //led tag
        $led = $xml->createElement('LED', $ledValue);
        $ledNumber = $xml->createAttribute('number');
        $ledNumber->value = $ledNo;
        $led->appendChild($ledNumber);
        $ledColor = $xml->createAttribute('color');
        $ledColor->value = $color;
        $led->appendChild($ledColor);
        $xmlRoot->appendChild($led);

        //fetch tag
        $fetch = $xml->createElement('fetch','snom://mb_exit');
        $fetchTimeout = $xml->createAttribute('mil');
        $fetchTimeout->value = $timeout;
        $fetch->appendChild($fetchTimeout);
        $xmlRoot->appendChild($fetch);

        $xml->format_output = TRUE;

        return $xml->saveXML();
    }

    // Usage of public functions (prefix defined in module.json):
    // SNMD_PingPhone();

    public function PingPhone(): string {
        $phoneIp = $this->ReadPropertyString("PhoneIP");

        if (Sys_Ping($phoneIp, 4000)) {
            return sprintf("Phone with IP %s is reachable", $phoneIp);
        }
        return sprintf("Phone with IP %s is not reachable", $phoneIp);
    }

    public function SetValueFieldVisibility(bool $RecieveOnly): void {
        $this->UpdateFormField("ActionValue", "visible", !$RecieveOnly);
    }

    public function SetVariableId(int $variableId, bool $RecieveOnly): void {
        $this->SendDebug('SET', print_r($RecieveOnly, true), 0);

        if ($RecieveOnly) {
            $this->SendDebug('SET', print_r('Recieve only fkey', true), 0);
        }
        else {
            $this->UpdateFormField("ActionValue", "variableID", $variableId);
            $this->SendDebug('SET', print_r('set variable id', true), 0);     
        }
    }

    public function SetFkeySettings(string $fKey, bool $isRecieveOnly, string $variableId, string $variableValue, string $labelValue): void {
        $fKeyIndex = ((int)$fKey)-1;
        $this->RegisterMessage($variableId , VM_UPDATE);

        if ($isRecieveOnly) {
            $fkeyType="none";
            $fkeyValue = urlencode($fkeyType);
        }
        else {
            $fkeyType = "url";
            $instanceHook = sprintf("http://%s:3777/hook/snom/%d/", $this->ReadPropertyString("LocalIP"), $this->InstanceID);
            $hookParameters = "?xml=false&variableId=" . $variableId . "&value=" . $variableValue;
            $fkeyValue = urlencode($fkeyType . " " . $instanceHook . $hookParameters);
        }

        $urlQuery = sprintf("settings=save&fkey%d=%s&fkey_label%d=%s", $fKeyIndex, $fkeyValue, $fKeyIndex, urlencode($labelValue));
        $phoneModel =$this->ReadPropertyString("PhoneModel");
    
        if (PhoneProperties::hasSmartLabel($phoneModel)) {
            $urlQuery = sprintf("%s&fkey_short_label%d=%s", $urlQuery, $fKeyIndex, urlencode($labelValue));
        }

        $phoneIp = $this->ReadPropertyString("PhoneIP");
        $baseUrl = sprintf("http://%s/dummy.htm?", $phoneIp);
        $url = sprintf("%s%s", $baseUrl, $urlQuery);
        $this->SendDebug("URL", print_r($url, true), 0);

        file_get_contents($url);
    }

    public function GetConfigurationForm(): string
    {
        $data = json_decode(file_get_contents(__DIR__ . "/form.json"), true);
        $phoneModel =$this->ReadPropertyString("PhoneModel");
        $fkeyRange = PhoneProperties::getFkeysRange($phoneModel);

        foreach ($fkeyRange as $fkeyNo) {
            $data["elements"][5]["values"][$fkeyNo-1] = [
                "FkeyNo" => $fkeyNo,
                "CheckBox" => false,
                "ActionVariableId" => 1,
                "ActionValue" => false,
                "FkeyLabel" => "not set",
                "FkeyColorOn" => "none",
                "FkeyColorOff" => "none",
            ];
        }

        $data["elements"][5]["form"] = "return json_decode(SNMD_UIGetForm(\$id, \$FkeysSettings['ActionVariableId'] ?? 0, \$FkeysSettings['RecieveOnly'] ?? false, \$FkeysSettings['ActionValue'] ?? false), true);";
        
        return json_encode($data);
    }

    public function UIGetForm(int $ActionVariableId, bool $recvOnly, bool $value): string {
        $this->SendDebug("SETTING", print_r((int)$value, true), 0);
        $data = json_decode(file_get_contents(__DIR__ . "/form.json"), true);
        $data["elements"][5]["form"][3]["variableID"] = $ActionVariableId;
        $data["elements"][5]["form"][3]["visible"] = !$recvOnly;
        $data["elements"][5]["form"][3]["value"] = $value;

        return json_encode($data["elements"][5]["form"]);
    }
    // has_expanstion_module()
}