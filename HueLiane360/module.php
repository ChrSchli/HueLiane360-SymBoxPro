<?php
declare(strict_types=1);

class HueLiane360 extends IPSModule
{
    public function Create(): void
    {
        parent::Create();
        foreach (['BridgeIP','AppKey','LightID'] as $p) $this->RegisterPropertyString($p, '');
        $this->RegisterPropertyBoolean('VerifyTLS', false);
        $this->RegisterVariableBoolean('Power', 'Ein / Aus', '~Switch', 10);
        $this->RegisterVariableInteger('Brightness', 'Helligkeit', '~Intensity.100', 20);
        $this->RegisterVariableString('GradientMode', 'Gradient-Darstellung', '', 40);
        for ($i=1;$i<=5;$i++) $this->RegisterVariableString('Color'.$i, 'Farbpunkt '.$i.' (#RRGGBB)', '', 50+$i);
        $this->RegisterVariableString('OnColor', 'Einschaltfarbe (#RRGGBB)', '', 65);
        $this->RegisterVariableString('Effect', 'Animation', '', 70);
        $this->RegisterVariableInteger('Speed', 'Geschwindigkeit (1–10)', '', 80);
        $this->RegisterVariableInteger('Turbo', 'Turbo (1,2,4,6,10)', '', 90);
        $this->RegisterVariableBoolean('Running', 'Animation läuft', '~Switch', 100);
        $this->RegisterTimer('Animation', 0, 'HLI_Tick($_IPS["TARGET"]);');
        $this->RegisterAttributeInteger('Phase', 0);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        foreach (['Power','Brightness','GradientMode','Effect','Speed','Turbo','Running'] as $ident) $this->EnableAction($ident);
        $defaults=['Power'=>false,'Brightness'=>80,'GradientMode'=>'segmented_palette','Color1'=>'#FF0000','Color2'=>'#FF8800','Color3'=>'#FFFF00','Color4'=>'#00DD44','Color5'=>'#0066FF','OnColor'=>'#FFFFFF','Effect'=>'rainbow','Speed'=>5,'Turbo'=>1,'Running'=>false];
        foreach ($defaults as $ident=>$value) {
            if ($this->GetBuffer('initialized_'.$ident)!=='yes') {
                $this->SetValue($ident,$value);
                $this->SetBuffer('initialized_'.$ident,'yes');
            }
        }
        // Neue Identifikatoren vermeiden eine riskante Typänderung bestehender String-Variablen.
        for ($i=1;$i<=5;$i++) {
            $this->RegisterVariableInteger('Color'.$i.'RGB', 'Farbpunkt '.$i, '~HexColor', 50+$i);
        }
        $this->RegisterVariableInteger('OnColorRGB', 'Einschaltfarbe', '~HexColor', 65);
        $this->RegisterAttributeBoolean('IntegerColorsMigrated', false);
        if (!$this->ReadAttributeBoolean('IntegerColorsMigrated')) {
            for ($i=1;$i<=5;$i++) {
                $old=$this->GetValue('Color'.$i);
                $this->SetValue('Color'.$i.'RGB', $this->HexToInteger($old, [0xFF0000,0xFF8800,0xFFFF00,0x00DD44,0x0066FF][$i-1]));
            }
            $this->SetValue('OnColorRGB', $this->HexToInteger($this->GetValue('OnColor'), 0xFFFFFF));
            $this->WriteAttributeBoolean('IntegerColorsMigrated', true);
        }
        for ($i=1;$i<=5;$i++) {
            $this->EnableAction('Color'.$i.'RGB');
            @IPS_SetHidden($this->GetIDForIdent('Color'.$i), true);
        }
        $this->EnableAction('OnColorRGB');
        @IPS_SetHidden($this->GetIDForIdent('OnColor'), true);
        if ($this->ReadPropertyString('BridgeIP')==='' || $this->ReadPropertyString('AppKey')==='' || $this->ReadPropertyString('LightID')==='') {
            $this->SetStatus(104);
        } else $this->SetStatus(102);
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'Power':
                if ((bool)$Value) {
                    $this->Stop();
                    $this->SetValue('Power', true);
                    $this->ApplySolidColor($this->GetValue('OnColorRGB'));
                } else {
                    $this->Stop();
                    $this->Hue('PUT','/clip/v2/resource/light/'.rawurlencode($this->ReadPropertyString('LightID')),['on'=>['on'=>false]]);
                    $this->SetValue('Power', false);
                }
                break;
            case 'Running':
                if ((bool)$Value) $this->Start(); else $this->Stop();
                break;
            case 'Brightness':
                $this->SetValue($Ident,max(1,min(100,(int)$Value)));
                if (!$this->GetValue('Running') && $this->GetValue('Power')) $this->ApplyFrame();
                break;
            case 'GradientMode':
                if (!in_array($Value,['interpolated_palette','interpolated_palette_mirrored','random_pixelated','segmented_palette'],true)) throw new Exception('Unbekannter Gradient-Modus');
                $this->SetValue($Ident,$Value);
                if (!$this->GetValue('Running')) $this->ApplyFrame();
                break;
            case 'OnColorRGB':
                $this->SetValue('OnColorRGB', $this->ValidateColorInteger($Value));
                break;
            case 'Effect':
                if (!in_array($Value,['rainbow','chase','pulse','solid','aurora','fire','ocean'],true)) throw new Exception('Unbekannter Effekt');
                $this->SetValue($Ident,$Value);break;
            case 'Speed': $this->SetValue($Ident,max(1,min(10,(int)$Value))); if ($this->GetValue('Running')) $this->SetTimerInterval('Animation',$this->Interval()); break;
            case 'Turbo':
                if (!in_array((int)$Value,[1,2,4,6,10],true)) throw new Exception('Turbo: 1,2,4,6 oder 10');
                $this->SetValue($Ident,(int)$Value);if ($this->GetValue('Running')) $this->SetTimerInterval('Animation',$this->Interval());break;
            default:
                if (!preg_match('/^Color[1-5]RGB$/', $Ident)) throw new Exception('Unbekannte Variable: '.$Ident);
                $this->SetValue($Ident, $this->ValidateColorInteger($Value));
                if (!$this->GetValue('Running')) $this->ApplyFrame();
        }
    }

    public function TestConnection(): bool
    {
        try {
            $r=$this->Hue('GET','/clip/v2/resource/light/'.rawurlencode($this->ReadPropertyString('LightID')));
            if (empty($r['data'])) throw new Exception('Leuchte nicht gefunden');
            $this->SetStatus(102);
            $this->LogMessage('Hue Liane: Verbindung erfolgreich',KL_NOTIFY);
            return true;
        } catch (Throwable $e) {
            $this->SetStatus(201);
            $this->LogMessage('Hue Liane: '.$e->getMessage(),KL_ERROR);
            return false;
        }
    }

    public function Start(): void
    {
        $this->SetValue('Power',true);
        $this->SetValue('Running',true);
        $this->SetTimerInterval('Animation',$this->Interval());
        $this->Tick();
    }

    public function Stop(): void
    {
        $this->SetTimerInterval('Animation',0);
        $this->SetValue('Running',false);
    }

    public function Tick(): void
    {
        if (!$this->GetValue('Running')) return;
        $phase=$this->ReadAttributeInteger('Phase');
        $this->WriteAttributeInteger('Phase',$phase+max(1,(int)$this->GetValue('Turbo')));
        try { $this->ApplyFrame($phase); }
        catch (Throwable $e) {
            $this->SetTimerInterval('Animation',0);
            $this->SetValue('Running',false);
            $this->LogMessage('Animation gestoppt: '.$e->getMessage(),KL_ERROR);
        }
    }

    public function ApplyFrame(int $phase=-1): void
    {
        if ($phase<0) $phase=$this->ReadAttributeInteger('Phase');
        $n=5;
        if ($n<1 || $n>64) throw new Exception('Ungültige Kanalzahl');
        $rgb=[];$effect=$this->GetValue('Running') ? $this->GetValue('Effect') : 'solid';
        for ($i=0;$i<$n;$i++) {
            $base=$this->IntegerToRgb($this->GetValue('Color'.(($i%5)+1).'RGB'));
            if ($effect==='rainbow') $base=$this->HsvToRgb(fmod(($phase*8+$i*360/$n),360),1,1);
            if ($effect==='chase') {
                $distance=($i-($phase%$n)+$n)%$n;
                $factor=$distance===0?1.0:($distance===1?0.20:0.035);
                $base=array_map(fn($v)=>(int)round($v*$factor),$base);
            }
            if ($effect==='aurora') {
                $wave=sin($phase*.13-$i*.9);
                $base=$this->HsvToRgb(135+48*$wave, .75, .48+.30*(1+sin($phase*.11+$i*.65))/2);
            }
            if ($effect==='fire') {
                $flicker=(sin($phase*.51+$i*1.73)+sin($phase*.29-$i*2.17))/2;
                $base=$this->HsvToRgb(7+24*($flicker+1)/2,1,.48+.42*($flicker+1)/2);
            }
            if ($effect==='ocean') {
                $wave=sin($phase*.12-$i*.95);
                $base=$this->HsvToRgb(185+35*($wave+1)/2,.82,.42+.34*(1+sin($phase*.10+$i*.8))/2);
            }
            if ($effect==='pulse') $base=array_map(fn($v)=>(int)round($v*(.5+.5*sin($phase*.25))),$base);
            $rgb[]=$base;
        }
        $points=[];
        foreach ($rgb as $c) $points[]=['color'=>['xy'=>$this->RgbToXy($c)]];
        $r=$this->Hue('PUT','/clip/v2/resource/light/'.rawurlencode($this->ReadPropertyString('LightID')),[
            'on'=>['on'=>true], 'dimming'=>['brightness'=>(int)$this->GetValue('Brightness')],
            'gradient'=>['points'=>$points,'mode'=>$this->GetValue('GradientMode')]
        ]);
        if (!empty($r['errors'])) throw new Exception(json_encode($r['errors']));
    }

    private function ApplySolidColor(int $color): void
    {
        $xy=$this->RgbToXy($this->IntegerToRgb($color));
        $points=array_fill(0,5,['color'=>['xy'=>$xy]]);
        $r=$this->Hue('PUT','/clip/v2/resource/light/'.rawurlencode($this->ReadPropertyString('LightID')),[
            'on'=>['on'=>true],
            'dimming'=>['brightness'=>(int)$this->GetValue('Brightness')],
            'gradient'=>['points'=>$points,'mode'=>'interpolated_palette']
        ]);
        if (!empty($r['errors'])) throw new Exception(json_encode($r['errors']));
    }

    private function Interval(): int
    {
        // Hue CLIP API is not intended for high-frame-rate streaming.
        $base=max(400,1000-(int)$this->GetValue('Speed')*55);
        return max(500,(int)round($base/max(1,(int)$this->GetValue('Turbo'))));
    }

    private function Hue(string $method,string $path,?array $payload=null): array
    {
        $host=$this->ReadPropertyString('BridgeIP');$key=$this->ReadPropertyString('AppKey');
        if (!filter_var($host,FILTER_VALIDATE_IP) || $key==='') throw new Exception('Bridge IP oder App Key fehlt/ungültig');
        $ch=curl_init('https://'.$host.$path);
        $opts=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','hue-application-key: '.$key],CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>$this->ReadPropertyBoolean('VerifyTLS'),CURLOPT_SSL_VERIFYHOST=>$this->ReadPropertyBoolean('VerifyTLS')?2:0];
        if ($payload!==null) $opts[CURLOPT_POSTFIELDS]=json_encode($payload,JSON_THROW_ON_ERROR);
        curl_setopt_array($ch,$opts);$raw=curl_exec($ch);$error=curl_error($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if ($raw===false || $code<200 || $code>=300) throw new Exception('Hue HTTP '.$code.' '.$error.' '.substr((string)$raw,0,500));
        $result=json_decode($raw,true);return is_array($result)?$result:[];
    }

    private function ValidateColorInteger($value): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) throw new Exception('Ungültiger RGB-Farbwert');
        $color=(int)$value;
        if ($color<0 || $color>0xFFFFFF) throw new Exception('RGB-Farbwert außerhalb 0 bis 16777215');
        return $color;
    }
    private function HexToInteger(string $hex, int $fallback): int
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/D', $hex) ? hexdec(substr($hex,1)) : $fallback;
    }
    private function IntegerToRgb(int $color): array
    {
        return [($color >> 16) & 255, ($color >> 8) & 255, $color & 255];
    }
    private function HexToRgb(string $hex): array { return [hexdec(substr($hex,1,2)),hexdec(substr($hex,3,2)),hexdec(substr($hex,5,2))]; }
    private function HsvToRgb(float $h,float $s,float $v): array
    {
        $c=$v*$s;$x=$c*(1-abs(fmod($h/60,2)-1));$m=$v-$c;
        $p=match ((int)floor($h/60)%6) {0=>[$c,$x,0],1=>[$x,$c,0],2=>[0,$c,$x],3=>[0,$x,$c],4=>[$x,0,$c],default=>[$c,0,$x]};
        return array_map(fn($v)=>(int)round(($v+$m)*255),$p);
    }
    private function RgbToXy(array $rgb): array
    {
        [$r,$g,$b]=array_map(function($v){$v=$v/255;return $v>.04045?(($v+.055)/1.055)**2.4:$v/12.92;},$rgb);
        $x=$r*.664511+$g*.154324+$b*.162028;
        $y=$r*.283881+$g*.668433+$b*.047685;
        $z=$r*.000088+$g*.07231+$b*.986039;
        $sum=$x+$y+$z;
        return ['x'=>round($sum>0?$x/$sum:.3127,4),'y'=>round($sum>0?$y/$sum:.329,4)];
    }
}
