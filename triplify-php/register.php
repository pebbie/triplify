<?php
/**
 * Use this script to manually register your Triplify installation at the
 * Triplify registry.
 *
 * @copyright  Copyright (c) 2008, {@link http://aksw.org AKSW}
 * @license    http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 * @version    $Id: register.php 54 2009-09-28 13:23:49Z seebi $
 */

include('config.inc.php');

$baseURI='http://'.$_SERVER['SERVER_NAME'].substr($_SERVER['REQUEST_URI'],0,strpos($_SERVER['REQUEST_URI'],'/triplify')+9).'/';

$url='http://triplify.org/register/?url='.urlencode($baseURI).'&type='.urlencode($triplify['namespaces']['vocabulary']);
if($f=fopen($url,'r')) {
	echo fread($f,1000);
	fclose($f);
} else
	echo 'Please <a href="'.$url.'">register manually</a>!';
?>