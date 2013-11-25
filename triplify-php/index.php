<?php
/*
 * This is the main Triplify script.
 *
 * @copyright  Copyright (c) 2008, {@link http://aksw.org AKSW}
 * @license    http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 * @version    $Id: index.php 83 2011-12-22 15:51:09Z p_frischmuth $
 */
//error_reporting(E_ALL ^ E_NOTICE);

require_once('config.inc.php');
require_once('metadata.php');

if (!isset($triplify['db'])) {
        die('error: no database connection configured.'.PHP_EOL);
}

if($_SERVER['REQUEST_URI']) {
    $serverURI='http://'.$_SERVER['SERVER_NAME'].($_SERVER['SERVER_PORT']!=80?':'.$_SERVER['SERVER_PORT']:'');
    $baseURI=$serverURI.substr($_SERVER['REQUEST_URI'],0,strpos($_SERVER['REQUEST_URI'],'/triplify')+9).'/'.
        ((in_array('mod_rewrite',apache_get_modules()) && (!isset($triplify['use_mod_rewrite']) || ((boolean)$triplify['use_mod_rewrite'] === true)))?'':'index.php/');
    $path=str_replace($baseURI,'',$serverURI.$_SERVER['REQUEST_URI'].(substr($_SERVER['REQUEST_URI'],-1,1)!='/'?'/':''));
} else {
    ob_end_flush();
    $cliMode=true;
    if($argv[1])
        include($argv[1]);
    $_SERVER['REQUEST_URI']=$baseURI=$triplify['namespaces']['base'];
    $triplify['LinkedDataDepth']=0;
    
    if ($triplify['db'] instanceof PDO) {
        if ($triplify['db']->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $triplify['db']->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }
    }
}

// If active, expose the X-Pingack service
if (isset($triplify['pingback']['enabled']) && ((boolean)$triplify['pingback']['enabled'] === true)) {
    // add X-Pingback header
    header('X-Pingback: ' . $baseURI . 'pingback/');
}

$idx = strpos($path, '?');
if (false !== $idx) {
    $path = substr($path, 0, $idx);
}

if($path) {
    $r=array_filter(explode('/',$path));
    $class=array_shift($r);
    if($class!='update' && count($r)==1)
        $instance=array_pop($r);
    foreach($triplify['queries'] as $key=>$val)
        if(substr($key,0,1)=='/' && preg_match($key,$path))
            $exists=true;
    if(!$exists && (($class!='update' && $r) || !$class || !$triplify['queries'][$class])) {
        header("HTTP/1.0 404 Not Found");
        echo("<h1>Error 404</h1>Resource not found!");
        exit;
    }
}

if($_REQUEST['t-output']=='json')
    header('Content-Type: text/javascript');
else #if(0)
    header('Content-Type: text/rdf+n3');

# suggested by Alex Bilbie
# http://sf.net/mailarchive/forum.php?thread_name=alpine.DEB.2.00.0912151904370.28237%40fbywnevf4&forum_name=triplify-discussion
$cacheFileHashData = $_SERVER['SERVER_NAME'] . ':' . $_SERVER['SERVER_PORT'] . $_SERVER['REQUEST_URI'];
$cacheFile=$triplify['cachedir'].md5($cacheFileHashData);
#$cacheFile=$triplify['cachedir'].md5($_SERVER['REQUEST_URI']);

if(file_exists($cacheFile) && filemtime($cacheFile)>time()-$triplify['TTL']) {
    echo file_get_contents($cacheFile);
    exit;
}

if(!file_exists($triplify['cachedir'].'registered') && $triplify['register']) {
    $url='http://triplify.org/register/?url='.urlencode($baseURI).'&type='.urlencode($triplify['namespaces']['vocabulary']);
    if($f=fopen($url,'r'))
        fclose($f);
    touch($triplify['cachedir'].'registered');
}

$t=new tripleizer($triplify);
if($triplify['TTL'] && !$cliMode)
    ob_start();

$t->tripleize($triplify['queries'],$class,$instance);
if($_REQUEST['t-output']=='json')
    echo json_encode($t->json);

if($triplify['TTL'])
    file_put_contents($cacheFile,ob_get_contents());

if (!array_key_exists('provenance', $triplify) || $triplify['provenance']) {
    writeMetadata($triplify, $provenance, $t);
}

// If active, write pingbacks
if (isset($triplify['pingback']['write']) && ((boolean)$triplify['pingback']['write'] === true)) {
    require_once 'pingback.php';
    writePingbacks($triplify, null, $t, ($baseURI.$class.'/'.$instance));
}

/*
 *
 */
class tripleizer {
    var $maxResults;
    var $json=array();
    var $version='V0.7.1';
    /* Constructor
     *
     * @param   array   $config Array of configuration parameters, which are explained in config.inc.php
     */
    function tripleizer($config=array()) {
        if ($config['db'] instanceof PDO) {
            $this->pdo=$config['db'];
            
            if ($config['db']->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $config['db']->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            }
        }
        
        $this->config=$config;
        $this->ns=$config['namespaces'];
        $this->objectProperties=$config['objectProperties'];
        $this->classMap=$config['classMap'];
        $this->path=explode('/',rtrim($GLOBALS['path'],'/'));
    }
    /*
     * Transforms an SQL query (result) into NTriples
     * The first column of the query result is used as instance identifier.
     *
     * @param       string  $query  an SQL query
     * @param       string  $class  RDF class URI of the class the instances belong to
     * @return      string  $id Id of a specific entry
     */
    function tripleize($queries,$c=NULL,$id=NULL) {
        $self=$GLOBALS['serverURI'].$_SERVER['REQUEST_URI'];
        $idx = strpos($self, '?');
        if (false !== $idx) {
            $self = substr($self, 0, $idx);
        }
        
        #$this->writeTriple($self,$this->uri('owl:imports'),$this->ns['vocabulary']);
        
        if (!array_key_exists('provenance', $this->config) || $this->config['provenance']) {
            $this->writeTriple($self, $this->uri('rdfs:comment'),'Generated by Triplify '.$this->version.' (http://Triplify.org)', true);
        }
        
        if($this->config['license'])
            $this->writeTriple($self,'http://creativecommons.org/ns#license',$this->config['license']);
        if(is_array($this->config['metadata']))
            foreach($this->config['metadata'] as $property=>$value) if($value)
                $this->writeTriple($self,$this->uri($property),$value,true);
        foreach($queries as $class=>$q) if(!$c || $class==$c || (substr($class,0,1)=='/' && preg_match($class,$GLOBALS['path']))) {
            $qc=0;
            foreach(is_array($q)?$q:array($q) as $qid=>$query) {
                $qc++;
                unset($cols,$group);
                if(substr($class,0,1)=='/') {
                    unset($id);
                    $query=preg_replace($class,$query,rtrim($GLOBALS['path'],'/'));
                } else if(!$id && $this->config['LinkedDataDepth']==3) {
                    exit;
                } else if($class=='update') {
                    $cols=count($this->path)==7?'*':'SUBSTR(id,1,'.(count($this->path)*3+1).')';
                    $group=($this->path[1]?' AND YEAR(id)="'.$this->path[1].'"':'').
                            ($this->path[2]?' AND MONTH(id)="'.$this->path[2].'"':'').
                            ($this->path[3]?' AND DAY(id)="'.$this->path[3].'"':'').
                            ($this->path[4]?' AND HOUR(id)="'.$this->path[4].'"':'').
                            ($this->path[5]?' AND MINUTE(id)="'.$this->path[5].'"':'').
                            ($this->path[6]?' AND SECOND(id)="'.$this->path[6].'"':'');
                    if(count($this->path)!=7)
                        $group.=' GROUP BY SUBSTR(id,1,'.(count($this->path)*3+1).')';
                } else if($this->config['LinkedDataDepth']==2&&!$c) {
                    $this->writeTriple($this->uri($class),$this->uri('rdf:type'),$this->uri('owl:Class'));
                    continue(2);
                } else {
                    $cols=($this->config['LinkedDataDepth']==1&&!$c)||($this->config['LinkedDataDepth']==2&&!$id)?'id':'*';
                }
                // Suggested by Eric Feliksik
                // http://sf.net/mailarchive/forum.php?thread_name=d2e647770911221042r7aaa41e4t810d2f1c682f5a1d%40mail.gmail.com&forum_name=triplify-discussion
                if($cols || $group) { #if(($cols && $cols!='*') || $group) {
                    $query="SELECT $cols FROM ($query) t WHERE '1'".$group;
                }
                if($class && !$id && $_GET) {
                    foreach($_GET as $key=>$v)
                        if(substr($key,0,2)!='t-' && !strpos($key,'`'))
                            $query.=' AND `'.$key.'`='.$this->dbQuote($v);
                }
                $start=is_numeric($_GET['t-start'])?$_GET['t-start']:0;
                $erg=is_numeric($_GET['t-results'])?($this->maxResults?min($_GET['t-results'],$this->maxResults):$_GET['t-results']):$this->maxResults;
                $query=$query.($id?' AND '.(is_numeric($qid)?'id':$qid).'='.$this->dbQuote(urldecode($id)):'').
                    (preg_match('/^[A-Za-z0-9: ,]+?$/',$_GET['t-order'])?' ORDER BY '.$_GET['t-order']:'').
                    ($start||$erg?' LIMIT '.($start?$start.','.($erg?$erg:20):$erg):'');
#echo $query;
                if($res=$this->dbQuery($query)) {
                    $dtype=$this->dbDtypes($res);
                    while($cl=$this->dbFetch($res))
                                                $this->makeTriples($cl,$class,$qc==1||$class=='update'?True:False,$dtype);
                }
                if($cols=='id')
                    break;
            }
        }
    }
    /**
     * makeTriples creates a number of triples from a database row
     *
     * @param array $cl
     * @param string $class
     * @param array $dtypes
     * @return
     */
    function makeTriples($cl,$class,$maketype,$dtypes) {
        $rdf_ns='http://www.w3.org/1999/02/22-rdf-syntax-ns#';
        $ipref=$this->uri($class.'/');
        $uri=array_shift($cl);
        if($class=='update')
            $uri=preg_replace('/[^0-9]/','/',$uri).'#'.substr(strstr(key($cl),'->'),2).current($cl);
        $subject=$this->uri($uri,$ipref);
        if(!$uri)
            $uri=md5(join($cl));
        if(!$GLOBALS['triplify']['noType'] && $maketype && substr($class,0,1)!='/') {
            if($class=='update')
                $c=count($this->path)==7?'http://triplify.org/vocabulary/update#Update':'http://triplify.org/vocabulary/update#UpdateCollection';
            else
                $c=$this->classMap[$class]?$this->classMap[$class]:$class;
            $this->writeTriple($subject,$rdf_ns.'type',$this->uri($c,$this->ns['vocabulary']));
        }
        #foreach($cl as $p=>$val)
        reset($cl);
        while(list($p, $val) = each($cl)) {
            if($p=='t:unc') {
                $p=current($cl);
                next($cl);
            }
            if($val && ($dtypes[$p]!='xsd:dateTime'||$val!='0000-00-00 00:00:00')) {
                if(strpos($p,'^^')) {
                    $dtype=$this->uri(substr($p,strpos($p,'^^')+2));
                    $p=substr($p,0,strpos($p,'^'));
                } else if($dtypes[$p]) {
                    $dtype=$this->uri($dtypes[$p]);
                } else if(strpos($p,'@')) {
                    $lang=substr(strstr($p,'@'),1);
                    $p=substr($p,0,strpos($p,'@'));
                } else
                    unset($dtype,$lang);

                if(strpos($p,'->')) {
                    $objectProperty=substr(strstr($p,'->'),2);
                    $p=substr($p,0,strpos($p,'->'));
                } else if(isset($this->objectProperties[$p])) {
                    $objectProperty=$this->objectProperties[$p];
                } else
                    unset($objectProperty);

                if($this->config['CallbackFunctions'][$p] && is_callable($this->config['CallbackFunctions'][$p]))
                    $val=call_user_func($this->config['CallbackFunctions'][$p],$val);
                $val=utf8_encode($val);

                $prop=$this->uri($p,$this->ns['vocabulary']);
                if(isset($objectProperty)) {
                    $isLiteral=false;
                    $object=$this->uri($objectProperty.($objectProperty&&substr($objectProperty,-1,1)!='/'?'/':'').$val);
                } else {
                    $isLiteral=true;
                    $object=($dtypes[$p]=='xsd:dateTime'?str_replace(' ','T',$val):$val);
                }
                $this->writeTriple($subject,$prop,$object,$isLiteral,$dtype,$lang);
            }
        }
        return $ret;
    }
    /**
     * writeTriple - writes a triple in a certain output format
     *
     * @param string $subject   subject of the triple to be written
     * @param string $predicate predicate of the triple to be written
     * @param string $object    object of the triple to be written
     * @param boolean $isLiteral    boolean flag whether object is a literal, defaults to false
     * @param string $dtype datatype of a literal object
     * @param string $lang  language of the literal object
     * @return
     */
    function writeTriple($subject,$predicate,$object,$isLiteral=false,$dtype=NULL,$lang=NULL) {
        if(!$GLOBALS['cliMode']) {
            static $oldsubject,$written;
            if($subject!=$oldsubject) {
                $oldsubject=$subject;
                //unset($written);
                $written = array();
            }
            $hash=md5($subject.$predicate.$object.$isLiteral.$dtype.$lang);
            if($written[$hash])
                return;
            else
                $written[$hash]=true;
        }
        if($_REQUEST['t-output']=='json') {
            $oa=array('value'=>$object,'type'=>($isLiteral?'literal':'uri'));
            if($isLiteral && $dtype)
                $oa['datatype']=$dtype;
            else if($isLiteral && $lang)
                $oa['language']=$lang;
            $this->json[$subject][$predicate]=($this->json[$subject][$predicate]?array_merge($this->json[$subject][$predicate],$oa):$oa);
        } else {
            if($isLiteral)
                $object='"'.$this->escape($object).'"'.($dtype?'^^<'.$dtype.'>':($lang?'@'.$lang:''));
                #$object='"'.str_replace(array('\\',"\r","\n",'"'),array('\\\\','\r','\n','\"'),$object).'"'.($dtype?'^^<'.$dtype.'>':($lang?'@'.$lang:''));
            else
                $object = ( substr($object,0,2) == '_:' ) ? $object : '<'.$object.'>';
            $subject = ( substr($subject,0,2) == '_:' ) ? $subject : '<'.$subject.'>';
            echo $subject.' <'.$predicate.'> '.$object." .\n";
        }
        flush();
    }
    /**
     * tripleizer::uri()
     *
     * @param mixed $name
     * @param mixed $default
     * @return
     */
    function uri($name,$default=NULL) {
        if(strstr($name,'://'))
            return $name;
        return (strpos($name,':')?$this->ns[substr($name,0,strpos($name,':'))].$this->normalizeLocalName(substr($name,strpos($name,':')+1)):
            ($default?$default:$GLOBALS['baseURI']).$this->normalizeLocalName($name));
    }
    function normalizeLocalName($name) {
        return str_replace(array('%2F','%23'),array('/','#'),urlencode(trim($name)));
    }
    function dbQuote($string) {
        return $this->pdo?$this->pdo->quote($string):'\''.mysql_real_escape_string($string).'\'';
    }
    function dbQuery($query) {
        $result = $this->pdo ? $this->pdo->query($query,eval('return PDO::FETCH_ASSOC;')) : mysql_query($query);

        if (defined('TRIPLIFY_DEBUG_MODE') && ((bool)TRIPLIFY_DEBUG_MODE === true)) {
            if (!$result) {
                if ($this->pdo) {
                    print_r($this->pdo->errorInfo());
                } else {
                    print_r(mysql_error());
                }
            }
        }

        return $result;
    }
    function dbFetch($res) {
        return $this->pdo?$res->fetch():mysql_fetch_assoc($res);
    }
    function dbDtypes($res){
        if(method_exists($res,'getColumnMeta'))
            for ($i=0; $i<$res->columnCount(); $i++) {
                $meta=$res->getColumnMeta($i);
                if (($meta['native_type'] == 'TIMESTAMP') || ($meta['native_type']=='DATETIME')) {
                    $dtype[$meta['name']] = 'xsd:dateTime';
                } else if (!strcasecmp($meta['native_type'],'numeric')) {
                    $dtype[$meta['name']] = 'xsd:decimal';
                } else if (!strcasecmp($meta['native_type'],'float')) {
                    $dtype[$meta['name']] = 'xsd:float';
                } else if (!strcasecmp($meta['native_type'],'integer')) {
                    $dtype[$meta['name']] = 'xsd:int';   
                }
            }
        else if(!$this->pdo) {
            for($i=0;$i<mysql_num_fields($res);$i++) {
                $type=mysql_field_type($res,$i);
                if(!strcasecmp($type,'timestamp') || !strcasecmp($type,'datetime'))
                    $dtype[mysql_field_name($res,$i)]='xsd:dateTime';
            }
        }
        return $dtype;
    }
    const error_character = '\\uFFFD';

    // Input is an UTF-8 encoded string. Output is the string in N-Triples encoding.
    // Checks for invalid UTF-8 byte sequences and replaces them with \uFFFD (white
    // question mark inside black diamond character)
    //
    // Sources:
    // http://www.w3.org/TR/rdf-testcases/#ntrip_strings
    // http://en.wikipedia.org/wiki/UTF-8
    // http://www.cl.cam.ac.uk/~mgk25/ucs/examples/UTF-8-test.txt
    private static function escape($str) {
        // Replaces all byte sequences that need escaping. Characters that can
        // remain unencoded in N-Triples are not touched by the regex. The
        // replaced sequences are:
        //
        // 0x00-0x1F   non-printable characters
        // 0x22        double quote (")
        // 0x5C        backslash (\)
        // 0x7F        non-printable character (Control)
        // 0x80-0xBF   unexpected continuation byte, 
        // 0xC0-0xFF   first byte of multi-byte character,
        //             followed by one or more continuation byte (0x80-0xBF)
        //
        // The regex accepts multi-byte sequences that don't have the correct
        // number of continuation bytes (0x80-0xBF). This is handled by the
        // callback.
        return preg_replace_callback(
                "/[\\x00-\\x1F\\x22\\x5C\\x7F]|[\\x80-\\xBF]|[\\xC0-\\xFF][\\x80-\\xBF]*/",
                array('tripleizer', 'escape_callback'),
                $str);
    }

    private static function escape_callback($matches) {
        $encoded_character = $matches[0];
        $byte = ord($encoded_character[0]);
        // Single-byte characters (0xxxxxxx, hex 00-7E)
        if ($byte == 0x09) return "\\t";
        if ($byte == 0x0A) return "\\n";
        if ($byte == 0x0D) return "\\r";
        if ($byte == 0x22) return "\\\"";
        if ($byte == 0x5C) return "\\\\";
        if ($byte < 0x20 || $byte == 0x7F) {
            // encode as \u00XX
            return "\\u00" . sprintf("%02X", $byte);
        }
        // Multi-byte characters
        if ($byte < 0xC0) {
            // Continuation bytes (0x80-0xBF) are not allowed to appear as first byte
            return tripleizer::error_character;
        }
        if ($byte < 0xE0) { // 110xxxxx, hex C0-DF
            $bytes = 2;
            $codepoint = $byte & 0x1F;
        } else if ($byte < 0xF0) { // 1110xxxx, hex E0-EF
            $bytes = 3;
            $codepoint = $byte & 0x0F;
        } else if ($byte < 0xF8) { // 11110xxx, hex F0-F7
            $bytes = 4;
            $codepoint = $byte & 0x07;
        } else if ($byte < 0xFC) { // 111110xx, hex F8-FB
            $bytes = 5;
            $codepoint = $byte & 0x03;
        } else if ($byte < 0xFE) { // 1111110x, hex FC-FD
            $bytes = 6;
            $codepoint = $byte & 0x01;
        } else { // 11111110 and 11111111, hex FE-FF, are not allowed
            return tripleizer::error_character;
        }
        // Verify correct number of continuation bytes (0x80 to 0xBF)
        $length = strlen($encoded_character);
        if ($length < $bytes) { // not enough continuation bytes
            return tripleizer::error_character;
        }
        if ($length > $bytes) { // Too many continuation bytes -- show each as one error
            $rest = str_repeat(tripleizer::error_character, $length - $bytes);
        } else {
            $rest = '';
        }
        // Calculate Unicode codepoints from the bytes
        for ($i = 1; $i < $bytes; $i++) {
            // Loop over the additional bytes (0x80-0xBF, 10xxxxxx)
            // Add their lowest six bits to the end of the codepoint
            $byte = ord($encoded_character[$i]);
            $codepoint = ($codepoint << 6) | ($byte & 0x3F);
        }
        // Check for overlong encoding (character is encoded as more bytes than
        // necessary, this must be rejected by a safe UTF-8 decoder)
        if (($bytes == 2 && $codepoint <= 0x7F) ||
            ($bytes == 3 && $codepoint <= 0x7FF) ||
            ($bytes == 4 && $codepoint <= 0xFFFF) ||
            ($bytes == 5 && $codepoint <= 0x1FFFFF) ||
            ($bytes == 6 && $codepoint <= 0x3FFFFF)) {
            return tripleizer::error_character . $rest;
        }
        // Check for UTF-16 surrogates, which must not be used in UTF-8
        if ($codepoint >= 0xD800 && $codepoint <= 0xDFFF) {
            return tripleizer::error_character . $rest;
        }
        // Misc. illegal code positions
        if ($codepoint == 0xFFFE || $codepoint == 0xFFFF) {
            return tripleizer::error_character . $rest;
        }
        if ($codepoint <= 0xFFFF) {
            // 0x0100-0xFFFF, encode as \uXXXX
            return "\\u" . sprintf("%04X", $codepoint) . $rest;
        }
        if ($codepoint <= 0x10FFFF) {
            // 0x10000-0x10FFFF, encode as \UXXXXXXXX
            return "\\U" . sprintf("%08X", $codepoint) . $rest;
        }
        // Unicode codepoint above 0x10FFFF, no characters have been assigned
        // to those codepoints
        return tripleizer::error_character . $rest;
    }
}
?>
