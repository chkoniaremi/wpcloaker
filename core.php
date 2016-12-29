<?php

#
# wpCloaker core cloaking routines
#
# the iplist is no longer stored in the wordpress database.
# checking IPs is done through a REST service to a provided URL.
#

require_once('defines.php');
require_once('settings.php');
require_once('ip2locationlite.class.php');

if (!function_exists("get_post_data"))
{
		function get_post_data($key)
		{
			return isset($_POST[$key]) ? $_POST[$key] : '';
		}
}

if (!function_exists("file_put_contents"))
{
		function file_put_contents($n,$d)
		{
		  $f=@fopen($n,"w");
		  if (!$f) {
		   return false;
		  }
			else {
		   fwrite($f,$d);
		   fclose($f);
		   return true;
		  }
		}
}

if (function_exists("curl_exec") && ini_get('open_basedir') == '')
{
	
	function wpcloaker_file_get_contents_curl($url, $timeout=2) {
		$useragent="Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.1) Gecko/20061204 Firefox/2.0.0.1";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_USERAGENT, $useragent);
		if ($timeout != 0)
			curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

		// curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //Set curl to return the data instead of printing it to the browser.
		curl_setopt($ch, CURLOPT_URL, $url);
		$data = curl_exec($ch);

		$data = ($data === false) ? curl_error($ch) : $data;
		curl_close($ch);
		return $data;
	}

	function my_file_get_contents($url)	{
		return wpcloaker_file_get_contents_curl($url);
	}
}
else 
{
	function my_file_get_contents($url)	{
		$data = file_get_contents($url);
		$data = ($data === false) ? 'error' : $data;

		return $data;
	}
}

function wpcloaker_stuffcookie($affiliate_link) {


	$affs = explode("\n", trim($affiliate_link));
	$code = "";

	foreach ($affs as $aff) {
		$aff = trim($aff);
		if ($aff != '') $code .= "<img src='" . wpcloaker_asciiEncode($aff) . "' width='1' height='1' />\n";
	}

	return $code;
}

function wpcloaker_asciiEncode($string)  {
    for ($i=0; $i < strlen($string); $i++)  {
        $encoded .= '&#'. ord(substr($string,$i)) . ';';
    }
    return $encoded;
}

# ----------------------------------------------------------------

function wpcloaker_add_url_params($url)
{
	$full_url = $url;

	//
	// Step 1 - passthrough all parameters coming from query string - this is mainly for PPC tracking, etc.
	//
	$separator = (stristr($url, '?') === false) ? '?' : '&';
	$pstring = '';
	if (isset($_SERVER['QUERY_STRING'])) {
		$pstring = str_replace(' ', '%20', $_SERVER['QUERY_STRING']);
	}
	
	if ($pstring != '') {
		$full_url .= $separator . $pstring;
		$separator = '&';
	}

	//
	// Step 2 - passthrough any designated params from the REFERRER - this is mainly for organic traffic
	//
	$passthrough_params = trim(get_option('wpcloaker_passthroughreferrerparams'));	
	if ($passthrough_params != '') {
		$passthrough_params = explode(',', $passthrough_params);

		$s = array();
		$ref = $_SERVER['HTTP_REFERER'];
		$tmp = explode('?', $ref);
		if (count($tmp) == 2) {
			$ref_params = explode('&', $tmp[1]);
			foreach ($ref_params as $ref_p) {
				$tmp = explode('=', $ref_p);
				$key = trim($tmp[0]);		
				foreach ($passthrough_params as $pass_p) {
					if ($key == trim($pass_p))
						if (count($tmp) == 2)
							$s[] = "$key=" . trim($tmp[1]);
				}

			}
			$s = implode('&', $s);
			$s = trim(str_replace(' ', '%20', $s));
			if ($s != '')
				$full_url .= $separator . $s;
		}
	}

	// done
	return $full_url;
}

function wpcloaker_matchUA()
{

	$result = false;
	$u_agent = $_SERVER["HTTP_USER_AGENT"];

	$ualist = get_option('wpcloaker_ualist');
	$ua_arr = explode("\n", $ualist);
	
	foreach ($ua_arr as $ua) {
		if (stristr($u_agent, $ua) === false)
			;
		else {
			$result = true;
			break;
		}
	}

	return $result;
}

function wpcloaker_matchIPList(&$content)
{
	$gethost = isset($_SERVER['REMOTE_HOST']) ? $_SERVER['REMOTE_HOST'] : 'UNKNOWN';
	$getaddr = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'UNKNOWN';

	$classCclient = $getaddr;
	$classCclient = preg_replace("/\.\d+$/","",$classCclient);

	$match = false;

	foreach ($content as $line) {
		$line = trim($line);

		if ($line == ''){continue;}
		if (preg_match("/^#/",$line)){continue;}

		if ($getaddr == $line) {
			$match = true;
		}
		else if ($classCclient == $line) {
			$match = true;
		}
		else if (strpos($line, $gethost) !== false) {
			$match = true;
		}
		else
			;

		if ($match) { break; }
	}

	return $match;
}

# -------------------------------------------------------------
#
# Deprecated functions from previous versions of wpCloaker
#
# -------------------------------------------------------------

#
# @deprecated - using central service
#

function DEPRECATED_wpcloaker_shouldUpdateIPs()
{
	return false;
}

#
# @deprecated - using central service
#
function DEPRECATED_wpcloaker_updateIPs()
{
}

function DEPRECATED_wpcloaker_matchIP()
{
	global $wpcloaker_spiderspy_file;
	$match = false;

	if (!file_exists($wpcloaker_spiderspy_file)) return false;

	$iplist = file($wpcloaker_spiderspy_file);
	$match = wpcloaker_matchIPList($iplist);

	return $match;
}

// ----------------------------------------------------------------------------------------------------------

function wpcloaker_matchIP()
{
	$customips = explode("\n", get_option('wpcloaker_customlist'));
	$result = wpcloaker_matchIPList($customips);
	if ($result == false) {
		global $wpcloaker_iplist_url;

		$gethost = $_SERVER['REMOTE_HOST'];
		$getaddr = $_SERVER['REMOTE_ADDR'];

		$classCclient = $getaddr;
		$classCclient = preg_replace("/\.\d+$/","",$classCclient);
		
		// die("host = $gethost | addr = $getaddr | c = $classCclient");
		
		$isspider = my_file_get_contents($wpcloaker_iplist_url . "?host=$gethost&addr=$getaddr&c=$classCclient");			
		
		// die ("isspider = $isspider");
		
		//
		// IP server will return the strings true or false. On an error it returns the error code/message.
		// crazy logic but on 'false' return false, otherwise return true since with an error you want to treat it like a spider
		//
		$result = ($isspider == "false") ? false : true;
	}
	
	return $result;
}


function wpcloaker_matchExclude()
{
	// these are translators and applications like bablefish that should always be shown the regular content
	$addedexcludes = array("204.123.9.65",
		"204.123.9.66",
		"204.123.9.67",
		"204.123.9.68",
		"204.123.9.106",
		"204.123.9.107",
		"204.152.191.27",
		"204.152.191.28",
		"204.152.191.29",
		"204.152.190.27",
		"204.152.190.28",
		"204.152.190.29",
		"204.152.190.37",
		"204.152.190.154",
		"204.162.96.104",
		"204.162.96.154",
		"204.162.96.176",
		"209.247.194.35",
		"209.247.194.100",
		"64.208.35.5");


	$content = explode("\n", get_option('wpcloaker_excludelist'));
	$content = array_merge($content, $addedexcludes);
	return wpcloaker_matchIPList($content);
}

function wpcloaker_matchSucker()
{
	$content = explode("\n", get_option('wpcloaker_suckerlist'));
	return wpcloaker_matchIPList($content);
}

/*
 * matchPatternList
 *
 * returns false on no match
 * returns true on pattern match with no data
 * return data string on pattern match with data
 */
 
function wpcloaker_matchPatternList(&$content, $against)
{
	$match = false;
	foreach ($content as $item) {
		$item = trim($item);
		if ($item == "") continue;

		$t = explode("->", $item);
		$pattern = trim($t[0]);		
		$pattern = str_replace(" ", "-", $pattern);
			
		if (stristr($against, $pattern) !== false) {
			if (count($t) == 1)
				$match = true;
			else
				$match = trim($t[1]);
		}
		
		if ($match !== false)
			break;
	}

	return $match;
}

// 
// matches blank referrer and UA = MSIE for sites like pinterest that use
// javascript for links and do not pass user agent when IE is used.
//
function wpCloaker_matchIEReferrer()
{

	$result = false;
	
	$ua = $_SERVER['HTTP_USER_AGENT'];
	if (stristr($ua, "MSIE") !== false) {
		$ref = trim($_SERVER['HTTP_REFERER']);
		if ($ref == '') $result = true;
	}
	
	return $result;
}

function wpcloaker_matchReferrer()
{
	$against = $_SERVER['HTTP_REFERER'];
	if (trim($against) == '')
		$against = "BLANKREF";
		
		
	$content = explode("\n", get_option('wpcloaker_referrerlist'));
	return wpcloaker_matchPatternList($content, $against);
}

function wpcloaker_matchLang()
{
	$against = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
	$content = explode("\n", get_option('wpcloaker_langlist'));
	return wpcloaker_matchPatternList($content, $against);
}

function wpcloaker_getGeoIP()
{
	global $wpcloaker_ipinfodb_api;
	$geoip = 'XX';

	$remote_addr = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : "0.0.0.0";
	
	if(!isset($_COOKIE["geolocation"])) {
		if (trim($wpcloaker_ipinfodb_api) == "") {
			$geoip = my_file_get_contents("http://api.hostip.info/country.php?ip=$remote_addr");
		}
		else {
			$ipLite = new ip2location_lite;
			$ipLite->setKey($wpcloaker_ipinfodb_api);
			$locationinfo = $ipLite->getCountry($remote_addr);

			if ($locationinfo['statusCode'] == 'OK')
				$geoip = $locationinfo['countryCode'];
		}
		
		$data = base64_encode(serialize($geoip));
		setcookie("geolocation", $data, time()+3600*24); //set cookie for 1 day	
	}

	else {
		$geoip = unserialize(base64_decode($_COOKIE["geolocation"]));
	}
	return $geoip;
}

function wpcloaker_matchGeoIPCountry()
{

	$against = wpcloaker_getGeoIP();
	$content = explode("\n", get_option('wpcloaker_geoipcountrylist'));
	
	return wpcloaker_matchPatternList($content, $against);
	
}

function wpcloaker_getLandingPage()
{

	$landingpages = explode("\n", get_option('wpcloaker_customlandinglist'));
	$against = $_SERVER['REQUEST_URI'];
	$match=  wpcloaker_matchPatternList($landingpages, $against);	

	$url = "";
	if ($match === true) /* in the list but no pattern, use main landing page */
		$url = trim(get_option('wpcloaker_page'));
	else if ($match === false) /* not in the list, use main landing page */
		$url = trim(get_option('wpcloaker_page'));
	else /* in the list and have a custom landing page, use it */
		$url = $match;
		
	return $url;
}

function wpcloaker_protect()
{
	$gethost = isset($_SERVER['REMOTE_HOST']) ? $_SERVER['REMOTE_HOST'] : 'UNKNOWN';
	$getaddr = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'UNKNONW';

	$match = false;

	# show bablefish regular content
	if (preg_match("/babelfish/i",$gethost)){
			$match = true;
	}
	# bablefish sometimes shows itself as the scooter robot
	if ($getaddr == "209.73.164.50"){
		if (!preg_match("/scooter/i",$agent)){
				$match = true;
		}
	}

	return $match;
}

function wpcloaker_doNotCloak()
{
	$match = false;
	$exclude = trim(get_option('wpcloaker_donotcloaklist'));
	$uri = $_SERVER['REQUEST_URI'];

	if ($exclude == "") return false;

	$excludes = explode("\n", $exclude);
	foreach ($excludes as $e) {
		if (stristr($uri, $e) !== false)
			$match = true;
	}

	return $match;
}

function wpcloaker_doSucker()
{
}

// Check if an ip is a bot against the ARIN database doing a round trip check.
// add to the iplist on a match

function wpcloaker_matchDNS()
{

	$uas  = "(google)|(yahoo)|(slurp)|(msn)|(ask)|(microsoft)";
	$coms = "(googlebot.com)|(yahoo.net)|(live.com)|(ask.com)|(yahoo.com)|(bing.com)";
	$ua = $_SERVER["HTTP_USER_AGENT"];
	$ip = $_SERVER['REMOTE_ADDR'];

	$host_name = trim(gethostbyaddr($ip));
	$host_name_ip_address = gethostbyname($host_name);

	// check if the host name lists one of the search engine .com sites and do round trip check
	if (stristr($coms, $host_name) !== false) {
		if (ip2long($ip) == ip2long($host_name_ip_address)) {
			// it's a spider - add to the list
			$iplist = get_option('wpcloaker_customlist');
			if ($iplist != false)
				$iplist = explode("\n",$iplist);
			$iplist[] = $ip;

			$iplist = array_values(array_unique($iplist));
			update_option('wpcloaker_customlist', implode("\n", $iplist));
			return true;
		}
	}

	return false;
}

function extract_url($u)
{
	if ($u == '') return "";
	
	$parts = explode("|", $u);
	return trim($parts[0]);
}

function extract_basehref($u)
{
	if ($u == '') return "";
	
	$parts = explode("|", $u);
	return (count($parts) == 2) ? trim($parts[1]) : "";
}


### main routine ###
function wpcloaker_process($content)
{
	if (is_admin()) return true;
	
	$onlyhome = get_option('wpcloaker_onlyhome');
	$excludehome = get_option('wpcloaker_excludehome');
	$is_homepage = is_home() || is_front_page();


	if ($excludehome == 'on' && $is_homepage === true) return true;
	if ($onlyhome == 'on' && $is_homepage === false) return true;
	
	if (wpcloaker_doNotCloak())
		return true;

	$method = get_option('wpcloaker_method');
	$spiderid = get_option('wpcloaker_spiderid');

	if (WPCLOAKER_NONE == $method)
		return true;

	if (wpcloaker_protect())
		return true;

	if (wpcloaker_matchExclude())
		return true;

	if (wpcloaker_matchSucker()) {
		$suckerurl = get_option('wpcloaker_suckerurl');
		if ($suckerurl == "")
			return true;
		else {
			header("Location: $suckerurl");
			exit;
		}
	}

	$showContent = false;
	$url = "";
	
	switch ($spiderid) {
		case WPCLOAKER_IP:
			$showContent = wpcloaker_matchIP();
			break;
		case WPCLOAKER_UA:
			$showContent = wpcloaker_matchUA();
			break;
		case WPCLOAKER_IP_UA:
			$showContent = wpcloaker_matchIP() && wpcloaker_matchUA();
			break;
		case WPCLOAKER_REFERRER:
			$result = wpcloaker_matchReferrer();
			if ($result === false) 
				$showContent = true;
			else if ($result === true)
				$showContent = false;
			else {
				$showContent = false;
				$url = $result;
			}
			break;
		case WPCLOAKER_LANG:
			$result = wpcloaker_matchLang();
			if ($result === false) 
				$showContent = true;
			else if ($result === true)
				$showContent = false;
			else {
				$showContent = false;
				$url = $result;
			}
			break;
		case WPCLOAKER_GEOIP_COUNTRY:
			$result = wpcloaker_matchGeoIPCountry();
			if ($result === false)
				$showContent = true;
			else if ($result === true)
				$showContent = false;
			else {
				$showContent = false;
				$url = $result;			
			}
			break;
		case WPCLOAKER_IP_UA_REFERRER:
			$showContent = wpcloaker_matchIP() && wpcloaker_matchUA();
			if ($showContent === true) {
				$result = wpcloaker_matchReferrer();
				if ($result === false) 
					$showContent = true;
				else if ($result === true)
					$showContent = false;
				else {
					$showContent = false;
					$url = $result;
				}
			}
			break;
		case WPCLOAKER_IP_GEOIP:
			$match_ip = wpCloaker_matchIP();
			if ($match_ip == true) {
				$showContent = true;
			}
			else {
				$result = wpcloaker_matchGeoIPCountry();
				if ($result === false)
					$showContent = true;
				else if ($result === true)
					$showContent = false;
				else {
					$showContent = false;
					$url = $result;			
				}			
			}
			break;
		case WPCLOAKER_ALWAYS_CLOAK:
			$url = wpcloaker_getLandingPage();
			$showContent = false;
			break;
	}

	// check for IE browser with blank referrer
	if ($showContent == true)
		if ((get_option('wpcloaker_ieblankreferrer') == 'on') && (wpcloaker_matchIEReferrer() == true))
			$showContent = false;

	// if it is a spider, show them the content
	if ($showContent == true)
		return true;
	
	// says it's not a spider - should we check with reverse DNS check?
	$checkreversedns = get_option('wpcloaker_reversedns');
	if ($checkreversedns == 'on') {
		if (wpcloaker_matchDNS() == true)
			return true;
	}


/**
NOTE - don't need this override since the logic is already checked above and we only geoip cloak if that's the method selected.

	// says it is not a spider - do we want to show a GEOip override?
	if ($url == "") {
		$result = wpcloaker_matchGeoIPCountry();
		if (($result !== true) && ($result !== false))
			$url = $result;
			
	}
**/

	// if we don't have a landing page yet determine if we 
	// should show a custom one or the default
	if ($url == "")
		$url = wpcloaker_getLandingPage($method);

	if ($url == '')
		return true;

	// hack here to allow a URL in any of the custom lists to also include the base href
	// the way it works is that the custom lists are all "pattern->url|basehref"
	// so the extract_xxx() functions parse the possible "url|basehref" string
	//
	// NOTE: there's an order of operation problem here that makes me use a temp variable $u
	// since both extract functional want to access the same url we can't overwrite it. Other 
	// option is to return multiple params via a pass by ref. Consider changing.
	//
	
	$u = extract_url($url);
	$basehref = extract_basehref($url);
	$url = $u;
	
	// add any passed parameters onto the URL string
	$url = wpcloaker_add_url_params($url);
	
	// finally! cloak or redirect
	switch ($method) {
		case WPCLOAKER_CLOAK:
			if ($basehref == '')
				$basehref = trim(get_option('wpcloaker_basehref'));			
			$content = my_file_get_contents($url);

			if ($basehref != '') 
				$content = str_replace("<head>", "<head><base href='$basehref' >", $content);
			if (get_option('wpcloaker_cookie') == 'on') 
				$content = str_replace("</body>", wpcloaker_stuffcookie($url) . "\n</body>", $content);
			echo $content;
			break;
		case WPCLOAKER_REDIRECT:
			header("Location: $url");
			break;			
		case WPCLOAKER_FRAME:
			global $post;
			$content = $post->post_content;
			$content = "<html><head><title>$post->post_title</title></head><body>$content</body></html>";
			
			$frame = '<frameset rows="*" frameborder="0" framespacing="0" border="0"><frame src="%%URL%%" marginheight="0" marginwidth="0" name="mainone" /><noframes>';
			$frame = str_replace("%%URL%%", $url, $frame);
			$content = str_replace("</head>", "</head>\n$frame", $content);
			$content = str_replace("</body>", "</body></noframes>\n</frameset>", $content);
			if (get_option('wpcloaker_cookie') == 'on') $content = str_replace("</body>", wpcloaker_stuffcookie($url) . "\n</body>", $content);

			echo $content;
			break;
	}

	exit;
}

?>