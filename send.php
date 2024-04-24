<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$data = json_decode( file_get_contents( 'php://input' ), true );

function convert_to_embedded_images(&$mail, $images) {

    foreach($images as $image) {
        $URL            = $image['src'];
        $img            = file_get_contents($URL);

        // Get CURL to download the image to our local directory
        //$curr_dir = basename(__DIR__); // e.g. /my/folder/location/
        //$image_location = $curr_dir . $image['file_name'];

        //$ch = curl_init($image['src']);
        //$fp = fopen($image_location, 'wb');
        //curl_setopt($ch, CURLOPT_FILE, $fp);
        //curl_setopt($ch, CURLOPT_HEADER, 0);
        //curl_exec($ch);
        //curl_close($ch);
        //fclose($fp);

        //$mail->AddEmbeddedImage($image_location, $image['name']);
        $mail->addStringEmbeddedImage($img, $image['name'], $image['file_name'], 'base64', 'image/' . $image['type']);
    }
}

function clean_up_images(&$mail, $images) {

    foreach($images as $image) {

        // Delete image
        $image_location = $curr_dir . $image['file_name'];

        unlink($image_location);
    }
}

$mail = new PHPMailer(true);                              // Passing `true` enables exceptions
try {
    //Server settings
    // $mail->SMTPDebug = 2;                                 // Enable verbose debug output
    $mail->isSendmail();                                      // Set mailer to use SMTP
    //$mail->isSMTP();                                      // Set mailer to use SMTP
    //$mail->Host = 'localhost';  // Specify main and backup SMTP servers
    //$mail->SMTPAuth = false;                               // Enable SMTP authentication
    //$mail->Username = '';                 // SMTP username
    //$mail->Password = '';                           // SMTP password
    //$mail->SMTPSecure = 'tls';                            // Enable TLS encryption, `ssl` also accepted
    //$mail->Port = 25;                                    // TCP port to connect to
    
    //Recipients
    $replyemail = $data['reply_to'] != "" ? $data['reply_to'] : $data['from'];
    $mail->setFrom($data['from'], $data['from_name']);
    $mail->addAddress($data['to'], $data['to_name']);     // Add a recipient
    $mail->addReplyTo($replyemail);
    if ($data['cc'] && is_array($data['cc'])) {
        foreach ($data['cc'] as $cc_addr) {
            $mail->AddCC($cc_addr);
        }
    }else if($data['cc']){
        $mail->AddCC($data['cc']);
    }
    if ($data['bcc'] && is_array($data['bcc'])) {
        foreach ($data['bcc'] as $bcc_addr) {
            $mail->AddBCC($bcc_addr);
        }
    }else if($data['bcc']){
        $mail->AddBCC($data['bcc']);
    }
    $mail->addCustomHeader('MIME-Version: 1.0');
    //$mail->addCustomHeader('Content-type:text/html;');
    $mail->ContentType = "multipart/mixed";
    
    $mail->CharSet = "UTF-8";

    $mail->Sender = $data['bounce_address'];
    $mail->XMailer = ' ';
         
    $mail->addCustomHeader('X-Mailer-Client', $data['client_id']);
    $mail->addCustomHeader('X-Mailer-Recp', $data['user_id']);
    $mail->addCustomHeader('X-Mailer-Camp', $data['campaign_id']);

    //Content
    $mail->isHTML(true);                                  // Set email format to HTML
    $mail->Subject  = $data['subject'];
    //$mail->Body     = $data['body'];
    $mail->msgHTML ($data['body']);
    $mail->AltBody  = strip_tags($data['body']);
 
    if ($data["is_embedded"] == true) {
        convert_to_embedded_images($mail, $data['images']);
    }

    $mail->send();
    echo "Message has been sent\n";
    return;
} catch (Exception $e) {
    echo 'Message could not be sent. Mailer Error: ', $mail->ErrorInfo;
}
?>