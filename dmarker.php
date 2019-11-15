<?php
/** 
 * demarker.php (c) Jacob Dilles <theshadow27@gmail.com> 2019-11-04
 * MIT License - Do whatever you want but don't blame me. 
 * 
 * This script reads DMARC report .zip and .xml.gz attachment files from the tmp
 * directory (as stored by `demime.php`, aggregates the data, and formats it 
 * to STDOUT as JSON or CSV. Access this file by visiting (for example):
 * 
 * `yourdomain.com/dmarc-reports/demarker.php?fmt=json&t=-1%20month`
 *
 * To get a JSON summary of the DMARC report files for the past month. The $_GET
 * parameters are:
 * 
 * - `fmt=` - one of `json`, `csv`, or `json,csv` (useful for debugging) 
 *            determines the output format
 * - `errors_only=` - either `false` or anything else (including missing) 
 *                    is `true` skips records without errors
 * - `t=` - a PHP time spec, by default `-1 month`. See 
 *          https://www.php.net/manual/en/datetime.formats.relative.php
 * 
 * For more information on DMARC, see https://dmarc.org and RFC7489 (
 * https://tools.ietf.org/html/rfc7489) .
 *
 * System Requirements 
 *    - PHP 5 or 7. 
 *    - /usr/bin/gzip, /usr/bin/unzip   (for reading attachment files)
 * PHP Modules Required (Standard for Siteground, Bluehost, etc):
 *    - json
 *    - simplexml 
 *
**/

define("DATA_BASE_DIRECTORY", dirname(__FILE__) );
include_once("util.php");


////////////////// INPUT ARGUMENTS  ///////////////
$output_types = $_GET["fmt"];
if(!$output_types) 
    return;

$errors_only = $_GET["errors_only"];
$errors_only = !$errors_only || $errors_only !== "false";

$range = $_GET["t"];
$range = parse_time_span($range);

if($output_types === "json")
    header('Content-Type: application/json; charset=UTF-8');
else
    header('Content-Type: text/plain; charset=UTF-8');

////////////////////////////////////////




define("EML_DIRECTORY", join_path(DATA_BASE_DIRECTORY, "eml"));
define("TMP_DIRECTORY", join_path(DATA_BASE_DIRECTORY, "tmp"));
define("OUT_DIRECTORY", join_path(DATA_BASE_DIRECTORY, "data"));

function record_cmp($a, $b){
    $v = $a["begin"] - $b["begin"];
    if($v) return $v;
    return strcmp($a["filename"], $b["filename"]);
}

// Note per https://tools.ietf.org/html/rfc7489
//  filename = receiver "!" policy-domain "!" begin-timestamp
//                "!" end-timestamp [ "!" unique-id ] "." extension
function test_file_range($name, $tt){
    if(!$tt)  return true; // no filter specified -> ignore filter
    
    $parts = explode('!', $name);
    if(count($parts) < 3) {
        print("invalid filename: $name ");
        return true; // not enough parts in filename, ignore filter
    } 
    
    $start_date = intval($parts[2]);
    if(!$start_date){
        print("invalid start date: $name");
        return true; // not a number for start date
    }

    return $tt[0] < $start_date && $start_date < $tt[1];
}


$file_count = 0;
$files_by_date = array();
$output = array();
foreach (array("*.xml.gz", "*.zip") as $extension){
    foreach (glob( join_path(TMP_DIRECTORY, $extension)) as $filename) { 
        $bnf = basename($filename);
        
        // apply filename-based range filtering
        if(!test_file_range($bnf, $range))
            continue;
        
        
        $xml = load_dmarc_report($filename);
        if(!$xml) {
            print("Error reading " . basename($filename) . ': ' . part_error() . "\n") ;
            continue;
        }	
        $file_count++;
        add_histogram($files_by_date, [date("Y-m-d",  $xml["report_metadata"]["date_range"]["begin"]),"files"] , 1);

        $reciever =  explode('!', $bnf)[0];

        foreach( positional_array($xml["record"]) as $record ) {
            // create the base record
            $dmark = array(
                "file"=>$bnf,
                "path"=> $filename,
                "link" => '<a href="tmp/' . $bnf . '" target="_blank">' . $bnf .'</a>',
                "date" => date("Y-m-d H:i:s", $xml["report_metadata"]["date_range"]["begin"]),
                "begin" => $xml["report_metadata"]["date_range"]["begin"],
                "end" => $xml["report_metadata"]["date_range"]["end"],
                "recip" => $reciever,
                "org_name" => $xml["report_metadata"]["org_name"],
                "email" => $xml["report_metadata"]["email"],
                "report_id" => $xml["report_metadata"]["report_id"],
                "source_ip" => $record["row"]["source_ip"],
                "count" => $record["row"]["count"],
                "disposition" => $record["row"]["policy_evaluated"]["disposition"],
                "dkim" => $record["row"]["policy_evaluated"]["dkim"],
                "spf" => $record["row"]["policy_evaluated"]["spf"],
                "source_host" => "" // gethostbyaddr($record["row"]["source_ip"])
            );

            // extract the additional auth results
            $authr = $record["auth_results"];
            if( $authr ){
                $extra = array();
                foreach ( array("dkim", "spf") as $type )
                    foreach(  positional_array($authr[$type]) as $result) 
                        if( is_array($result) && $result["result"] != "pass" )
                            array_push($extra, $type . ":" . $result["result"] . ":" . $result["domain"] );
                if( $extra ) 
                    $dmark["extra"] = join( " ; ", $extra);
            }
        
            // determine if there are problems
            $errors = 0;
            if($dmark["dkim"] !== "pass") $errors++;
            if($dmark["spf"] !== "pass") $errors++;
            if($dmark["extra"]) $errors ++;
            if($errors)
                $dmark["errors"] = $dmark["count"];
            
            // store for later
            //insert_sorted($output, $dmark, record_cmp);
            array_push($output, $dmark);

        }
    }
}

$GLOBALS["hostname_cache"] = array();
function get_source_host(&$dmark){
    $a = $dmark["source_host"];
    if($a) return $a;
    $ip = $dmark["source_ip"];
    $a = $GLOBALS["hostname_cache"][$ip];
    if($a) return $a;
    $a = gethostbyaddr($ip);
    if(!$a) $a = $dmark["source_ip"];
    $dmark["source_host"] = $a;
    $GLOBALS["hostname_cache"][$ip] = $a;
    return $a;
}

function formatdmark($d=Nil){
    if(is_array($d))
        return $d["date"] .','. $d["org_name"] .','. $d["count"].','. $d["dkim"].','. $d["spf"].','. $d["source_ip"] .','. get_source_host($d) . ',' .$d["extra"] .','. $d["file"]."\r\n";
    else
        return "date,org_name,count,dkim,spf,source_ip,source_addr,extra,file\r\n";
}


if(str_contains($output_types, "csv") ){
    // csv mode
    usort($output, record_cmp);
    print(formatdmark()); // print the header
    foreach( $output as $dmark ){
        if(!$errors_only || $dmark["errors"]){
            print(formatdmark($dmark)); // print each row!
        }
    }
} 

if(str_contains($output_types, "json")) {
    // info mode
    
    $by_source_ip = array();
    $by_provider = array();
    $by_hostname = array();
    $by_date = array();
    $pivot_table = array();
    $by_extra = array();

    $errors = 0;
    $totals = 0;
    foreach( $output as $dmark ){
        $count = $dmark["count"];
        $totals += $count;
        
        if($dmark["errors"])
            $errors += $count;
        
        if(!$errors_only || $dmark["errors"]){
            $sip = $dmark["source_ip"];
            $org =  $dmark["org_name"];
            $recip = $dmark["recip"];
            $shn = get_source_host($dmark);
            $dat = date("Y-m-d", $dmark["begin"]);
            $ym = date("Y-m", $dmark["begin"]);
            
            $extra =  $dmark["extra"];
            
            add_histogram($by_source_ip, $sip, $count);
            add_histogram($by_provider, $org, $count);
            add_histogram($by_hostname, $shn, $count);
            add_histogram($by_date, $dat, $count);           
            add_histogram($pivot_table, array($dat, $recip, $shn), $count);
            if($extra)
                add_histogram($by_extra, array($ym, $recip, $shn . ' => ' . $extra), $count);
        }
    }
    
    
    uksort($by_source_ip,ip_cmp );
//     ksort($by_provider);
//     ksort($by_hostname);
//     ksort($by_date);
    
    $result = array(
        "stats_type" => $errors_only ? "errors_only" : "all",
        "by_date" => $by_date,
        "by_provider" => $by_provider,
        "by_source_ip" => $by_source_ip,
        "by_hostname" => $by_hostname,
        "auth_results" => $by_extra,
        "pivot" => $pivot_table,
        "timespan" => $range,
        "file_count" => $file_count,
        "errors" => $errors,
        "total" => $totals,
    );
    print(json_encode($result, JSON_PRETTY_PRINT));
}

if(str_contains($output_types, "log")) {
    foreach(array("demime.php.errorlog", "php_errorlog") as $file){
        if(file_exists($file)){
            print("======================= $file =======================\n");
            readfile($file);
            print("=====================================================\n");
        }
    
    }
}

?>