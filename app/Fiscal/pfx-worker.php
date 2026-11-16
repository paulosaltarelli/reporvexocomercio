<?php
// Standalone CLI: secrets enter/leave only over private pipes; never written to disk.
if (PHP_SAPI !== 'cli') { exit(64); }
try {
    $input=json_decode(stream_get_contents(STDIN,4198400),true,8,JSON_THROW_ON_ERROR);
    $pfx=base64_decode($input['pfx']??'',true);$password=$input['password']??null;
    if(!is_string($pfx)||$pfx===''||strlen($pfx)>2097152||!is_string($password)||strlen($password)>1024)exit(65);
    $keys=[];
    if(!@openssl_pkcs12_read($pfx,$keys,$password)||!@openssl_x509_check_private_key($keys['cert'],$keys['pkey']))exit(66);
    $options=empty($keys['extracerts'])?[]:['extracerts'=>$keys['extracerts']];
    if(!@openssl_pkcs12_export($keys['cert'],$modern,$keys['pkey'],$password,$options))exit(67);
    echo json_encode(['pfx'=>base64_encode($modern),'fingerprint'=>openssl_x509_fingerprint($keys['cert'],'sha256')],JSON_THROW_ON_ERROR);
} catch (Throwable) { exit(68); }
