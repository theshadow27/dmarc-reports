#!/usr/local/bin/php
<?php
/** 
 * demime.php (c) Jacob Dilles <theshadow27@gmail.com> 2019-11-04
 * MIT License - Do whatever you want but don't blame me. 
 * 
 * This script takes input from STDIN, parses out a SMIME message, and saves
 * the attachemnt to disk if is in the correct format for DMARC. It is designed
 * for shared web hosts (e.g. cpannel) which have mail fitering capability that
 * includes a "pipe-to-script" option. The attached xml.gz files are stored 
 * in a directory for subsequent processing. 
 *
 * For more information, see RFC7489 ( https://tools.ietf.org/html/rfc7489 )
 * and https://dmarc.org
 *
 * System Requirements 
 *    - PHP 5 or 7. 
 *    - /usr/bin/gzip   (for checking the files)
 * PHP Modules Required (Standard for Siteground, Bluehost, etc):
 *    - json
 *    - simplexml 
 *    - mailparse     -- unfortunatly, prevents this working on MacOS native PHP
**/


define("MAX_MESSAGE_SIZE", 1048576);
define("MAX_DMARC_SIZE", 262144);
define("DATA_BASE_DIRECTORY", dirname(__FILE__) );
define("ERROR_LOG_FILE",__FILE__ . ".errorlog" );
define("LOG_INVALID_EML", true);


$FORCE_EMAIL_WRITE  = true;

// wrap everything, because if this process fails, the email will bounce!
// if the email bounces, we could be dingged for invald DMARC record!!
// DO NOT LET IT BOUNCE!
try {

////// You can implement selective filtering here

function join_path(...$paths){
    return join(DIRECTORY_SEPARATOR, $paths);
}

define("EML_DIRECTORY", join_path(DATA_BASE_DIRECTORY, "eml"));
define("TMP_DIRECTORY", join_path(DATA_BASE_DIRECTORY, "tmp"));
define("OUT_DIRECTORY", join_path(DATA_BASE_DIRECTORY, "data"));

function check_report_domain($headers){
    if(1) return TRUE;
    
    $to = $headers["to"];
    $subject = $headers["subject"];
    $from = $headers["from"];
    
    // for example, assuming this script is hosted on the email server for the
    // following domain.... 
    $target = "example.com";
    
    $result =  str_contains($subject, $target) && str_contains($to, "@" . $target);
    return $result;
}


///// RANDOM UTILITY FUNCTIONS (not worth an include/require !) //// 

function is_cli(){
    return defined('STDIN') || ( php_sapi_name() === 'cli' )
        ||  array_key_exists('SHELL', $_ENV) 
        || ( empty($_SERVER['REMOTE_ADDR']) and !isset($_SERVER['HTTP_USER_AGENT']) and count($_SERVER['argv']) > 0)
        || !array_key_exists('REQUEST_METHOD', $_SERVER) ;
}

function check_dmarc_format($fname){

    if(str_ends_with($fname, ".zip")){
        $shell_cmd = "/usr/bin/unzip -p " ;
    } else if(str_ends_with($fname, ".xml.gz")){
        $shell_cmd = "/usr/bin/gzip -c -d ";
    } else {
        return part_error("check_dmarc:unknown_format:cant_extract(" . basename($fname) . ")");
    }

    // un-gz the compressed attachment
    $xml = shell_exec($shell_cmd . ' ' . escapeshellarg( $fname ) );
    if(!$xml)// failed to gunzip the file...
        return part_error("check_dmarc:gzip_cd:fail");
    
    // actually do the XML parsing
    $xml = simplexml_load_string($xml);
    if(!$xml)        // failed to load XML file
        return part_error("check_dmarc:simplexml_load_string:fail");

    // easiest way to turn into an assocative array
    $xml = json_decode(json_encode($xml), TRUE);
	if(!$xml)
	    return part_error("check_dmarc:json_encode_decode:fail");
	
	// probably a DMARC file if this works... but could validate further...
	if(!$xml["report_metadata"]["date_range"]["begin"])
	    return part_error("check_dmarc:not_dmarc:missing_meta_date_begin");
	
    $GLOBALS["mailparse_part_helper"]["xml"] = $xml;
    return TRUE;
}

function get_file_name($info){
    if(is_array($info))
        foreach( array("content-name", "disposition-filename") as $kn )
            if(is_string($info[$kn])) return $info[$kn];
    return "";
}

function str_ends_with($haystack, ...$needles) {
    foreach($needles as $needle)
      if( substr_compare(trim($haystack), $needle, -strlen($needle)) === 0 ) 
        return true;
    return false;
}

function str_contains($haystack, $needle){
    return strpos($haystack, $needle) !== false;
}

///// MIME HELPER FUNCTIONS ///// 

function part_init($fname){
    $ph = $GLOBALS["mailparse_part_helper"];
    
    if($ph["open"])
        return part_error("part_init:no_close");
    
    $ph = $GLOBALS["mailparse_part_helper"] = array(
            "file" => $fname,
            "error" => "",
            "size" => 0,
            "writes" => 0,
            "open" => true
    );
    
    if(is_file($fname))
        return part_error("part_init:file_exists:". $fname);
        
    if(!touch($fname))
        return part_error("part_init:no_touch:" . $fname);
    
    return $ph; 
}

function part_get_error(){
    return $GLOBALS["mailparse_part_helper"]["error"];
}

function part_error($error){
    $ph = $GLOBALS["mailparse_part_helper"];
    if(isset($ph) && is_array($ph)){
        if(isset($ph["open"])) 
            unset($ph["open"]);
        
        if($ph["error"]) 
            $ph["error"] .= "; " . $error;
        else 
            $ph["error"] = $error;
    } else {
        $ph = array("error" => "part_error:no_init");
    }
    $GLOBALS["mailparse_part_helper"] = $ph;
    return FALSE;
}

function part_write ($part){ // workaround limitation of mailparse_msg_extract_part
    $ph = $GLOBALS["mailparse_part_helper"];
    
    if( !$ph["open"] )
        return part_error("part_write:not_open");
    
    // calculate bytes decoded from message 
    $part_size = strlen($part);
    $new_size =  $ph["size"] + $part_size;
    if($new_size > MAX_DMARC_SIZE)
        return part_error("part_write:max_attach_size_exceeded($new_size)");
        
    // Write out 
    $bytes_out = file_put_contents( $ph["file"], $part, FILE_APPEND );
    if($bytes_out !== $part_size)
        return part_error("part_write:write_failed(i=${part_size}, o=${bytes_out}, f=" . $ph["file"] . ")");
    
    $ph["size"] = $new_size;
    $ph["writes"] += 1;
    $GLOBALS["mailparse_part_helper"] = $ph;
}

function part_close(){
    $ph = $GLOBALS["mailparse_part_helper"];
    if( !$ph["open"] ) return FALSE;
    unset($ph["open"]);
    
    return $ph["size"] > 0 && !$ph["error"] ; 
}

//// END MIME HELPER FUNCTIONS ///// 

if(!is_cli()){
    return;
}

///////////////////////////////////////////////////////////////////
// Step 1: Read message from standard in, as pipped from cpanel 
// 
//         results in message stored in $email 
//////////////////////////////////////////////////////////////////
$mime = mailparse_msg_create();
$email = "";
$size = 0;


// read email from STDIN (and store in $email), while parsing MIME at the same time. 
$fd = fopen("php://stdin", "r");
try{
    while (!feof($fd)) {
        $line = fread($fd, 1024);
        $size += count($line);
        if($size > MAX_MESSAGE_SIZE)
            throw new Exception("Maximim message size exceeded: $size", 3);
        if(!mailparse_msg_parse($mime, $line)) 
            throw new Exception("Invalid MIME: mailparse_msg_parse did not like '${line}'", 1);       
        $email .= $line;
    }
} finally{
    fclose($fd);
}

///////////////////////////////////////////////////////////////////
// Step 2: Attempt to find an attachment that looks like DMARC data 
//      if successful, stores the xml.gz file in DATA_BASE_DIRECTORY/data/filename
//      otherwise, stores raw email in DATA_BASE_DIRECTORY/eml/DATE_SHA1.eml 
//////////////////////////////////////////////////////////////////

$fname = ""; // attachment file name
$found_dmarc = 0;


$struct = mailparse_msg_get_structure($mime);
foreach ($struct as $st) {
    
    $section = mailparse_msg_get_part($mime, $st);
    $info = mailparse_msg_get_part_data($section);
    
    // Check the primary part for filering
    $headers = $info["headers"];
    if($info["starting-pos"] === 0 
            && isset($info["headers"]["subject"]) 
            && isset($info["headers"]["to"]) ){
        // Checks passed... look for attachment. 
        if(!check_report_domain($info["headers"])) {
            // failed address checks... bail out. 
            $found_dmarc = -1; // don't save... 
            break;
        }
        // Note that in some cases from google, there is only 1 part!
    }

    // Look for an attachment part that looks like a DMARC report
    if(trim( $info["content-disposition"]) == "attachment" ){
        $fname = get_file_name($info);
        // check for the corret type of file: aol.com!example.com!1572566400!1572652799.xml.gz
        if(str_contains($fname, "!") && str_ends_with($fname, ".xml.gz", ".zip")){
            // write the part data to the specified file. 
            // Note due to mailparse being 15+ years old, 
            // mailparse_msg_extract_part CAN NOT BE IN A FUNCTION
            $tmp_file = join_path( TMP_DIRECTORY, basename($fname) );
            $ok = part_init( $tmp_file ) 
                  && mailparse_msg_extract_part( $section , $email , "part_write" )
                  && part_close()
                  && check_dmarc_format($tmp_file); // returns greater than 0 if data was written 
                  
            
            // file does not exist and is writeable, content was decoded, some bytes were processed
            if( $ok ){
                $found_dmarc = 1;
                break;
            }
        }
    }
}
mailparse_msg_free($mime);
unset($mime);


// ensure that there is at least some error if no attachments were found
if(!part_get_error() && $found_dmarc === 0)
    part_error("no_attachment_found");


// should we be logging this?
//print_r($GLOBALS["mailparse_part_helper"]);


// support email dumping
$dump_result = "";
if( (part_get_error() && LOG_INVALID_EML) || $FORCE_EMAIL_WRITE ){
    $email_file = join_path( EML_DIRECTORY,  sha1($email) . ".eml" ); // debugging file 
    if(is_file($email_file))
        $dump_result = "Email dump already exists: " .  $email_file;
    else if (file_put_contents( $email_file , $email) !== FALSE)
        $dump_result = "Wrote eml file: " .  $email_file ;
    else 
        $dump_result = "Could not write to: " .  $email_file ;

    // this will probably get overwritten below... 
    $error_message = "MESSAGE_RECIEVED: " . $dump_result;
} 



if(part_get_error()){ // some reportable problem occured...
    // try to dump the email file... 

    // Set a message to log
    $error_details =  part_get_error();
    $error_details = "Error processing MIME attachement(" . $error_details . "). "; 
    $error_message = $error_details . $dump_result ;
}


// Big catch block - see above
} catch(Exception $e){
    $error_message =  $e->getMessage();
} finally {
    // clean up... if we missed it above
    if(isset($mime))
        mailparse_msg_free($mime);
}

// Super careful logging
if($error_message){
    $error_message = "[" . date('Y-m-d-his') . "]: " . $error_message . "\n";
    print($error_message);
    try{
        file_put_contents( ERROR_LOG_FILE ,$error_message , FILE_APPEND | LOCK_EX);
    } catch(Exception $blah){
        syslog(LOG_WARNING, "demime.php can't write log to " . ERROR_LOG_FILE );
    }
}

?>