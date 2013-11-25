<?php
function writePingbacks($triplifyConfig, $pingbackConfig, $tripleizer, $subject = '') 
{
	$p = new PingbackWriter($triplifyConfig, $provenanceConfig);
	$p->describePingbacks($subject, $tripleizer);
}

class PingbackWriter 
{
	var $bNodeCounter = 0;

	function PingbackWriter ($triplifyConfig, $provenanceConfig) 
	{
		$this->tConfig = $triplifyConfig;
		$this->pConfig = $provenanceConfig;
	}

	function describePingbacks($subject, $tripleizer) 
	{
	    $sql = 'SELECT * FROM triplify_pingbacks WHERE o="' . $subject . '"';
	    $result = $this->_query($sql); 
	        
	    if (!is_array($result)) {
	        return;
	    }
	    
	    foreach ($result as $row) {
	        $tripleizer->writeTriple($row['s'], $row['p'], $row['o']);
	    }
	}
	
	function _query($sql)
	{
	    $result = mysql_query($sql, $this->tConfig['db']);
	    if (!$result) {
	        return false;
	    }
	    $returnValue = array();
	    while ($row = mysql_fetch_assoc($result)) {
	        $returnValue[] = $row;
	    }
	    
	    if (count($returnValue) === 0) {
	        return false;
	    }
	    
	    return $returnValue;
	}
}
