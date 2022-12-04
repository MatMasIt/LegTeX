<?php
function rand_item($arr){
	return $arr[array_rand($arr)];
}
function API($method, $data)
{
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/TOKEN/". $method);
    curl_setopt($ch, CURLOPT_POST, 1);

    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $a= json_decode(curl_exec($ch) , true);
    return $a;
}
