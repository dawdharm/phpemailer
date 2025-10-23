<?php

use PHPMailer\PHPMailer\PHPMailer;
require("vendor/autoload.php");
$data = json_decode( file_get_contents( 'php://input' ), true );

$key = "QeThWmZq4t7w!z%C*F-JaNdRgUkXp2s5";
$iv = "AeDhGkPn3q6t9w2z";
/**
 * @param $mail - PhpMailer object
 * @param $images - array with images to embed in email
 * 
 * @return void - sets embedded images in email
 */
function convert_to_embedded_images(&$mail, $images) {
    foreach($images as $image) {
        $URL            = $image['src'];
        $img            = file_get_contents($URL);
        $mail->addStringEmbeddedImage($img, $image['name'], $image['file_name'], 'base64', 'image/' . $image['type']);
    }
}

/**
 * setup PHPMailer object and send email
 */

$mail = new PHPMailer(true);  // Passing `true` enables exceptions
try {

    $mail->isSendmail();
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'Quoted-Printable';
    $mail->setLanguage('en', 'vendor/phpmailer/phpmailer/language/');
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
    
    if ($data['bcc'] && is_array($data['cc'])) {
        foreach ($data['bcc'] as $bcc_addr) {
            $mail->AddBCC($bcc_addr);
        }
    }else if($data['bcc']){
        $mail->AddBCC($data['bcc']);
    }
    $mail->Sender = $data['bounce_address'];
    $mail->XMailer = " ";
    $message_id = encrypt("{$data['client_id']}|{$data['user_id']}|{$data['campaign_id']}");
    $hostname = explode("@", $data['from']);
    $hostname = $hostname[1];
    $mail->MessageID = "<{$message_id}@{$hostname}>";
    #$mail->addCustomHeader('X-Mailer-DWXDID', $message_id);
    #$mail->addCustomHeader('List-Unsubscribe', "<mailto:{$data['bounce_address']}?subject=Unsubscribe>");
    $mail->Subject  = $data['subject'];
    $mail->msgHTML($data['body']);
    $altBody = strip_tags($data['body']);
    $altBody = preg_replace('/\n[\n\s]*\n/', "\n", $altBody);
    $altBody = preg_replace('/\s\s+/', ' ', $altBody);
    $altBody = htmlspecialchars_decode($altBody);
    $mail->AltBody  = '';// PHPMailer::normalizeBreaks($altBody);

    if($data['attachments'] && is_array($data['attachments']) && count($data['attachments']) > 0){
        try {
        foreach($attachments as $attachment){
            $mail->addAttachment($attachment['src'], $attachment['name']);
        }
        } catch (\Throwable $th) {
        }catch (Exception $e) {
        }
    }
 
    if ($data["is_embedded"] == true) {
        convert_to_embedded_images($mail, $data['images']);
    }

    $mail->send();
    echo "Message has been sent\n";
    return;
} catch (\Exception $e) {
    echo 'Message could not be sent. Mailer Error: ', $mail->ErrorInfo;
}

function encrypt($data)
{
    global $key, $iv;
    $return = openssl_encrypt($data, 'AES-128-CBC', $key, 0, $iv);
    //convert the binary data into a base64 encoded string
    return base64_encode($return);
}
function decrypt($data)
{
    global $key, $iv;
    $data = base64_decode($data);
    return openssl_decrypt($data, 'AES-128-CBC', $key, 0, $iv);

}
