<?php 
/** 
 * util.php (c) Jacob Dilles <theshadow27@gmail.com> 2019-11-04
 * MIT License - Do whatever you want but don't blame me. 
 * 
 * Utilities in support of the DMARC project. 
 * 
 * Currently used by:
 * - demarker.php
 *
**/

function str_ends_with($haystack, ...$needles) {
    foreach($needles as $needle)
      if( substr_compare(trim($haystack), $needle, -strlen($needle)) === 0 ) 
        return true;
    return false;
}

function str_contains($haystack, $needle){
    return strpos($haystack, $needle) !== false;
}

function join_path(...$paths){
    return join(DIRECTORY_SEPARATOR, $paths);
}

/** Determine if this is the CLI **/
function is_cli(){
    return defined('STDIN') || ( php_sapi_name() === 'cli' )
        ||  array_key_exists('SHELL', $_ENV) 
        || ( empty($_SERVER['REMOTE_ADDR']) and !isset($_SERVER['HTTP_USER_AGENT']) and count($_SERVER['argv']) > 0)
        || !array_key_exists('REQUEST_METHOD', $_SERVER) ;
}

/** Ensure that the supplied argument is a positional array. If it is not, wrap it with one **/
function positional_array($var){
    if(!$var) return array();
    if(!is_array($var)) return array($var);
    // ensure that it is 
    if( array_keys($var) !== range(0, count($var)-1) ) return array($var);
    return $var;
}

/** positional array, binary-search based insert. **/
function insert_sorted(Array &$array, $value, Callable $compare = NULL){
   
    $n = count($array);
    if($n === 0){
        $array[]=$value;
        return TRUE;    
    }
   
    if(!isset($compare)) {
        if(is_numeric($value))
            $compare =  function($a, $b){ return $a - $b; };
        else if(is_string($value))
            $compare = strnatcmp;
        else
            throw new Exception("Don't know how to compare " . gettype($value) . ". Please provide a comparator function.");
    }
    
    $left = 0; $right = $n; $middle = 0;
    while ($left <= $right) {
        $middle = floor(($left + $right) / 2);
        $cv = $array[$middle];
        $cr = $compare($cv, $value);
        //print("    ( ${left} , ${right} ; ${middle} )     $cv <=> $value  ::=  $cr \n");
        if ($cr < 0) {
            $left = $middle + 1;
            if($left > $right) $middle = $left;  // insert after
        } elseif ($cr > 0) {
            $right = $middle - 1;
            if($left > $right) $middle = $right; // insert before
        } else {
            if($cr !== 0)
                print("Warning! compare(${cv}, ${value}) -> ${cr} : value is not <0, >0, or =0: ${cv}\n");
            return FALSE; // already exists 
        }
    }
    //print("T   ( ${left} , ${right} ; ${middle} )  |  ${value} --> " . "[". implode(", ", $array) . "]"."\n");

    if($middle <= 0) 
        array_unshift($array, $value);
    else if($middle >= $n)
        array_push($array, $value);
    else 
        array_splice($array, $middle, 0, array($value));
    
    return TRUE;
}


/** Compare IP Addresses (<0, ==0, >0) **/
function ip_cmp($a, $b){
    if($a == $b) return 0;
    foreach( array_map( null,
                preg_split("[.]", $a . ".0.0.0.0", 4),  
                preg_split("[.]", $b . ".0.0.0.0", 4) ) 
            as [$av, $bv] ){
        $v = intval($bv) - intval($av);
        if($v) return $v;
    }
    return 0;
}

function set_array_default(&$arr, $key, $dv){
    if(is_array($arr) && !array_key_exists($key, $arr))
        $arr[$key] = $dv;
}

function _extract_pivot_table_opts($cnt){
    if(is_numeric($cnt))
        $cnt = array("value"=>$cnt);
    if(!is_array($cnt))
        throw new Exception("pivot table requires numeric or parameters");
        
    set_array_default($cnt, "init", 0);
    set_array_default($cnt, "value", 1);
    set_array_default($cnt, "fn", "sum");
    set_array_default($cnt, "sort", true);
    return $cnt;
}

function _get_pivot_op($ptp){
    if(is_callable($ptp["fn"])) return $ptp["fn"];
    if($ptp["fn"] === "sum") return function($x, $y){return intval($x) + intval($y);};
    if($ptp["fn"] === "count") return function($x, $y){return intval($x) + intval(1);};
    if($ptp["fn"] === "cat") return function($x, $y){
        if($x && !is_array($x)) return array($x, $y);
        array_push($x, $y);
        return $x;
    };
    throw new Exception("pivot table requires aggregator 'fn' of 'sum', 'count', 'cat', or a custom callable function");
}

/** Create an associative array based histogram. 
  $key can be an array, in which case it will be multi-dimentional 
  $cnt is the number of instances to add to the histogram, by default, it is 1
*/
  
function add_histogram(Array &$array, $key, $cnt = 1){
    if(is_array($key)){
        if(count($key) > 1){ // multi-dim histogram (pivot table)
            $subkey = array_shift($key);
            
            if(! array_key_exists($subkey, $array) ){
                $array[$subkey] = array();
                //ksort($array);
            }

            return add_histogram($array[$subkey], $key, $cnt);
        }
        $key = $key[0];
    }

    $ptp = _extract_pivot_table_opts($cnt);
    
    if(!array_key_exists($key, $array)){
         $array[$key] = $ptp["init"];
         if($ptp["sort"]) 
            ksort($array);
    } 
    $x = $array[$key];
    $y = $ptp["value"];
    $z = _get_pivot_op($ptp)($x, $y);
    $array[$key] = $z;
    
    return $array;
}



function part_error($err=""){
    if($err){
        $GLOBALS["dmarc_error"] = $err;
        return FALSE;
    }
    return $GLOBALS["dmarc_error"];
}


function load_dmarc_report($fname){

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
	
    return $xml;
}


function parse_time_span($spec){
    if(!$spec) $spec = "-1 month";
    $r = date_parse($spec);
    if($r['year'] && $r['month'] && $r['day'])
        $t1 = strtotime($r['year'] . '-' . $r['month'] .'-' . $r['day']);
    else 
        $t1 = time();
    
    if($r['relative'])
        $t2 = strtotime($spec); 
    else 
        $t2 = $t1 - 60*60*24;

    return $t2 > $t1 ? array($t1, $t2, $spec) : array($t2, $t1, $spec);
}





// 
// 
// function add_histogram(Array &$array, $key, $cnt = 1){
//     if(array_key_exists($key, $array)){
//         $v = $array[$key];
//         $array[$key] = $v + $cnt;    
//     } else {
//         $array[$key] = intval($cnt);
//     }
//     return $array;
// }